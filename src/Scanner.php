<?php

namespace Drupal\fact_drift;

use Drupal\Component\Plugin\Exception\PluginException;
use Drupal\Component\Utility\Html;
use Drupal\Core\Cache\Cache;
use Drupal\Core\Cache\CacheBackendInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Field\FieldItemListInterface;
use Drupal\fact_drift\ClaimExtractor\ClaimExtractorInterface;
use Drupal\fact_drift\ClaimExtractor\ClaimExtractorManager;
use Drupal\fact_drift\Entity\Source;
use Drupal\fact_drift\FactSource\FactSourceInterface;
use Drupal\fact_drift\FactSource\FactSourceManager;
use Drupal\fact_drift\Value\Mismatch;
use Drupal\node\NodeInterface;
use Psr\Log\LoggerInterface;

/**
 * Runs the scan pipeline: facts, claims, comparison and findings.
 */
class Scanner {

  /**
   * The cache tag all cached facts carry.
   */
  public const FACTS_TAG = 'fact_drift_facts';

  /**
   * The number of nodes loaded at a time by a full scan.
   */
  public const CHUNK_SIZE = 50;

  /**
   * Constructs a Scanner.
   *
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entityTypeManager
   *   The entity type manager.
   * @param \Drupal\fact_drift\FactSource\FactSourceManager $sourceManager
   *   The fact source plugin manager.
   * @param \Drupal\fact_drift\ClaimExtractor\ClaimExtractorManager $extractorManager
   *   The claim extractor plugin manager.
   * @param \Drupal\fact_drift\Comparator $comparator
   *   The comparator.
   * @param \Drupal\fact_drift\FindingRecorder $recorder
   *   The finding recorder.
   * @param \Drupal\Core\Config\ConfigFactoryInterface $configFactory
   *   The config factory.
   * @param \Drupal\Core\Cache\CacheBackendInterface $cache
   *   The cache backend for loaded facts.
   * @param \Psr\Log\LoggerInterface $logger
   *   The fact_drift logger channel.
   */
  public function __construct(
    protected EntityTypeManagerInterface $entityTypeManager,
    protected FactSourceManager $sourceManager,
    protected ClaimExtractorManager $extractorManager,
    protected Comparator $comparator,
    protected FindingRecorder $recorder,
    protected ConfigFactoryInterface $configFactory,
    protected CacheBackendInterface $cache,
    protected LoggerInterface $logger,
  ) {
  }

  /**
   * Loads the facts of the enabled sources.
   *
   * A source that cannot be read is logged and listed as failed; its facts are
   * absent from the result.
   *
   * @param string[]|null $sourceIds
   *   Only load these sources, or NULL for every enabled source.
   *
   * @return array{facts: array<int, \Drupal\fact_drift\Value\Fact>, loaded: array<int, string>, failed: array<int, string>}
   *   The facts, the IDs of the sources read successfully, and the IDs of the
   *   sources that could not be read.
   */
  public function loadFacts(?array $sourceIds = NULL): array {
    $result = ['facts' => [], 'loaded' => [], 'failed' => []];
    foreach ($this->enabledSources($sourceIds) as $source) {
      try {
        array_push($result['facts'], ...$this->sourceFacts($source));
        $result['loaded'][] = (string) $source->id();
      }
      catch (\RuntimeException | PluginException $exception) {
        $this->logger->error('Source @id could not be read: @message', [
          '@id' => $source->id(),
          '@message' => $exception->getMessage(),
        ]);
        $result['failed'][] = (string) $source->id();
      }
    }
    return $result;
  }

  /**
   * Describes every source: its plugin, fact count and last load.
   *
   * @return array<int, array{id: string, label: string, plugin: string, facts: int, last_load: int|null}>
   *   One entry per source; last_load is a timestamp, or NULL when the source
   *   has not been loaded.
   */
  public function sourceStatus(): array {
    $rows = [];
    $sources = $this->sourceStorage()->loadMultiple();
    ksort($sources);
    foreach ($sources as $source) {
      assert($source instanceof Source);
      $count = 0;
      $lastLoad = NULL;
      try {
        if ($source->status()) {
          $count = count($this->sourceFacts($source));
        }
        $item = $this->cache->get($this->cacheId($source, $this->plugin($source)));
        if ($item) {
          $count = count($item->data);
          $lastLoad = (int) $item->created;
        }
      }
      catch (\RuntimeException | PluginException $exception) {
        $this->logger->error('Source @id could not be read: @message', [
          '@id' => $source->id(),
          '@message' => $exception->getMessage(),
        ]);
      }
      $definition = $this->sourceManager->getDefinition($source->getPluginId(), FALSE);
      $rows[] = [
        'id' => (string) $source->id(),
        'label' => (string) $source->label(),
        'plugin' => (string) ($definition['label'] ?? $source->getPluginId()),
        'facts' => $count,
        'last_load' => $lastLoad,
      ];
    }
    return $rows;
  }

  /**
   * Scans one node.
   *
   * @param \Drupal\node\NodeInterface $node
   *   The node.
   * @param string[]|null $sourceIds
   *   Only use these sources, or NULL for every enabled source.
   *
   * @return array{claims: int, opened: int, closed: int, failed: array<int, string>}
   *   The counts and the IDs of sources that could not be read. All counts are
   *   zero when the node is unpublished or its type is not configured.
   */
  public function scanNode(NodeInterface $node, ?array $sourceIds = NULL): array {
    if (!$this->isScannable($node)) {
      return $this->zeros() + ['failed' => []];
    }
    $loaded = $this->loadFacts($sourceIds);
    return $this->scanWithFacts($node, $loaded['facts'], $loaded['loaded']) + ['failed' => $loaded['failed']];
  }

  /**
   * Scans every published node of the configured content types.
   *
   * @param string[]|null $sourceIds
   *   Only use these sources, or NULL for every enabled source.
   * @param int|null $limit
   *   The maximum number of nodes to scan, or NULL for all.
   *
   * @return array{claims: int, opened: int, closed: int, failed: array<int, string>}
   *   The totals and the IDs of sources that could not be read.
   */
  public function scanAll(?array $sourceIds = NULL, ?int $limit = NULL): array {
    $totals = $this->zeros();
    $types = $this->settings()->get('content_types') ?? [];
    if (!$types) {
      return $totals + ['failed' => []];
    }
    $loaded = $this->loadFacts($sourceIds);
    $storage = $this->nodeStorage();
    $query = $storage->getQuery()
      ->accessCheck(FALSE)
      ->condition('status', 1)
      ->condition('type', $types, 'IN')
      ->sort('nid');
    if ($limit !== NULL) {
      $query->range(0, $limit);
    }
    foreach (array_chunk(array_values($query->execute()), self::CHUNK_SIZE) as $chunk) {
      foreach ($storage->loadMultiple($chunk) as $node) {
        assert($node instanceof NodeInterface);
        $counts = $this->scanWithFacts($node, $loaded['facts'], $loaded['loaded']);
        foreach ($counts as $name => $count) {
          $totals[$name] += $count;
        }
      }
      $storage->resetCache($chunk);
    }
    return $totals + ['failed' => $loaded['failed']];
  }

  /**
   * Runs the pipeline over one node with facts already loaded.
   *
   * @param \Drupal\node\NodeInterface $node
   *   The node.
   * @param \Drupal\fact_drift\Value\Fact[] $facts
   *   The loaded facts.
   * @param string[] $loadedSourceIds
   *   The IDs of the sources the facts came from.
   *
   * @return array{claims: int, opened: int, closed: int}
   *   The counts.
   */
  protected function scanWithFacts(NodeInterface $node, array $facts, array $loadedSourceIds): array {
    $counts = $this->zeros();
    if (!$this->isScannable($node) || !$loadedSourceIds) {
      return $counts;
    }
    $settings = $this->settings();
    $fieldTypes = $settings->get('field_types') ?? [];
    $extractors = $this->extractors($settings->get('extractors') ?? []);
    $default = $node->getUntranslated();
    $mismatches = [];
    foreach ($default->getFieldDefinitions() as $name => $definition) {
      if (!in_array($definition->getType(), $fieldTypes, TRUE)) {
        continue;
      }
      $text = $this->plainText($default->get($name));
      if ($text === '') {
        continue;
      }
      $claims = [];
      foreach ($extractors as $extractor) {
        array_push($claims, ...$extractor->extract($text));
      }
      $counts['claims'] += count($claims);
      foreach ($this->comparator->attribute($claims, $facts, $text) as [$fact, $claim]) {
        if (!$this->comparator->matches($fact, $claim)) {
          $mismatches[] = new Mismatch((string) $name, $fact, $claim);
        }
      }
    }
    $synced = $this->recorder->sync($node, $mismatches, $loadedSourceIds);
    $counts['opened'] = $synced['opened'];
    $counts['closed'] = $synced['closed'];
    return $counts;
  }

  /**
   * Tells whether a node is published and of a configured content type.
   *
   * @param \Drupal\node\NodeInterface $node
   *   The node.
   *
   * @return bool
   *   TRUE when the node should be scanned.
   */
  protected function isScannable(NodeInterface $node): bool {
    $default = $node->getUntranslated();
    return $default->isPublished() && in_array($default->bundle(), $this->settings()->get('content_types') ?? [], TRUE);
  }

  /**
   * Reduces a field's values to plain text.
   *
   * @param \Drupal\Core\Field\FieldItemListInterface<\Drupal\Core\Field\FieldItemInterface> $items
   *   The field items.
   *
   * @return string
   *   The values with markup stripped, entities decoded and whitespace
   *   collapsed.
   */
  protected function plainText(FieldItemListInterface $items): string {
    $parts = [];
    foreach ($items as $item) {
      $parts[] = (string) ($item->value ?? '');
    }
    $spaced = (string) preg_replace('/<[^>]*>/', ' ', implode(' ', $parts));
    $text = Html::decodeEntities(strip_tags($spaced));
    return trim((string) preg_replace('/\s+/u', ' ', $text));
  }

  /**
   * Creates the configured extractors.
   *
   * @param string[] $ids
   *   The extractor plugin IDs from the settings.
   *
   * @return \Drupal\fact_drift\ClaimExtractor\ClaimExtractorInterface[]
   *   The extractors, in weight order.
   */
  protected function extractors(array $ids): array {
    $extractors = [];
    foreach (array_keys($this->extractorManager->getDefinitions()) as $id) {
      if (in_array($id, $ids, TRUE)) {
        $extractor = $this->extractorManager->createInstance($id);
        assert($extractor instanceof ClaimExtractorInterface);
        $extractors[] = $extractor;
      }
    }
    return $extractors;
  }

  /**
   * Gets the facts of one source, from the cache when possible.
   *
   * @param \Drupal\fact_drift\Entity\Source $source
   *   The source.
   *
   * @return \Drupal\fact_drift\Value\Fact[]
   *   The facts, attributed to the source.
   *
   * @throws \RuntimeException
   *   When the source cannot be read.
   */
  protected function sourceFacts(Source $source): array {
    $plugin = $this->plugin($source);
    $cid = $this->cacheId($source, $plugin);
    $item = $this->cache->get($cid);
    if ($item) {
      return $item->data;
    }
    $claimTypes = $source->getClaimTypes();
    $facts = [];
    foreach ($plugin->facts($source->getPluginSettings()) as $fact) {
      if ($claimTypes && !in_array($fact->claimType, $claimTypes, TRUE)) {
        continue;
      }
      $facts[] = $fact->withSourceId((string) $source->id());
    }
    $this->cache->set($cid, $facts, Cache::PERMANENT, Cache::mergeTags([self::FACTS_TAG], $source->getCacheTags()));
    return $facts;
  }

  /**
   * Builds the cache ID of a source's facts.
   *
   * @param \Drupal\fact_drift\Entity\Source $source
   *   The source.
   * @param \Drupal\fact_drift\FactSource\FactSourceInterface $plugin
   *   The source's plugin; its fingerprint is part of the ID, so a changed
   *   file is reloaded.
   *
   * @return string
   *   The cache ID.
   */
  protected function cacheId(Source $source, FactSourceInterface $plugin): string {
    return 'fact_drift:facts:' . $source->id() . ':' . md5($plugin->fingerprint($source->getPluginSettings()));
  }

  /**
   * Creates the plugin that reads a source.
   *
   * @param \Drupal\fact_drift\Entity\Source $source
   *   The source.
   *
   * @return \Drupal\fact_drift\FactSource\FactSourceInterface
   *   The plugin.
   */
  protected function plugin(Source $source): FactSourceInterface {
    $plugin = $this->sourceManager->createInstance($source->getPluginId());
    assert($plugin instanceof FactSourceInterface);
    return $plugin;
  }

  /**
   * Loads the enabled sources, ordered by ID.
   *
   * @param string[]|null $sourceIds
   *   Only these sources, or NULL for all.
   *
   * @return \Drupal\fact_drift\Entity\Source[]
   *   The enabled sources.
   */
  protected function enabledSources(?array $sourceIds): array {
    $sources = $sourceIds === NULL ? $this->sourceStorage()->loadMultiple() : $this->sourceStorage()->loadMultiple($sourceIds);
    ksort($sources);
    return array_values(array_filter($sources, static fn ($source): bool => $source instanceof Source && $source->status()));
  }

  /**
   * Gets the zeroed counts.
   *
   * @return array{claims: int, opened: int, closed: int}
   *   The counts.
   */
  protected function zeros(): array {
    return ['claims' => 0, 'opened' => 0, 'closed' => 0];
  }

  /**
   * Gets the module settings.
   *
   * @return \Drupal\Core\Config\ImmutableConfig
   *   The settings.
   */
  protected function settings() {
    return $this->configFactory->get('fact_drift.settings');
  }

  /**
   * Gets the source storage.
   *
   * @return \Drupal\Core\Entity\EntityStorageInterface
   *   The storage.
   */
  protected function sourceStorage(): EntityStorageInterface {
    return $this->entityTypeManager->getStorage('fact_drift_source');
  }

  /**
   * Gets the node storage.
   *
   * @return \Drupal\Core\Entity\EntityStorageInterface
   *   The storage.
   */
  protected function nodeStorage(): EntityStorageInterface {
    return $this->entityTypeManager->getStorage('node');
  }

}
