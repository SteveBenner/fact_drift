<?php

namespace Drupal\fact_drift_ai\Plugin\ClaimExtractor;

use Drupal\Core\Cache\Cache;
use Drupal\Core\Cache\CacheBackendInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\Plugin\PluginBase;
use Drupal\Core\Session\AnonymousUserSession;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\fact_drift\Attribute\ClaimExtractor;
use Drupal\fact_drift\ClaimExtractor\ContextualClaimExtractorInterface;
use Drupal\fact_drift\Comparator;
use Drupal\fact_drift\Value\Claim;
use Drupal\fact_drift\Value\ExtractionContext;
use Drupal\fact_drift_ai\CallGuard;
use Drupal\fact_drift_ai\CallResult;
use Drupal\fact_drift_ai\Prefilter;
use Drupal\fact_drift_ai\Prompt;
use Drupal\fact_drift_ai\ResponseValidator;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Finds phone numbers and opening hours in prose by asking a model.
 *
 * It runs after the plain extractors, only on allowed fields of pages that
 * anonymous visitors can view, and every claim it returns is grounded in the
 * text of the field.
 *
 * @phpstan-consistent-constructor
 */
#[ClaimExtractor(
  id: 'llm',
  label: new TranslatableMarkup('AI extractor'),
  claim_type: NULL,
  weight: 100,
  claim_types: ['phone', 'hours'],
  marker: new TranslatableMarkup('AI'),
)]
class LlmExtractor extends PluginBase implements ContextualClaimExtractorInterface, ContainerFactoryPluginInterface {

  /**
   * Constructs an LlmExtractor.
   *
   * @param array<string, mixed> $configuration
   *   The plugin configuration.
   * @param string $plugin_id
   *   The plugin ID.
   * @param mixed $plugin_definition
   *   The plugin definition.
   * @param \Drupal\Core\Config\ConfigFactoryInterface $configFactory
   *   The config factory.
   * @param \Drupal\fact_drift\Comparator $comparator
   *   The comparator that cuts snippets.
   * @param \Drupal\fact_drift_ai\CallGuard $guard
   *   The call guard.
   * @param \Drupal\fact_drift_ai\Prefilter $prefilter
   *   The prefilter that builds windows.
   * @param \Drupal\fact_drift_ai\Prompt $prompt
   *   The prompt.
   * @param \Drupal\fact_drift_ai\ResponseValidator $validator
   *   The response validator.
   * @param \Drupal\Core\Cache\CacheBackendInterface $cache
   *   The cache bin of validated results.
   */
  public function __construct(
    array $configuration,
    $plugin_id,
    $plugin_definition,
    protected ConfigFactoryInterface $configFactory,
    protected Comparator $comparator,
    protected CallGuard $guard,
    protected Prefilter $prefilter,
    protected Prompt $prompt,
    protected ResponseValidator $validator,
    protected CacheBackendInterface $cache,
  ) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
  }

  /**
   * Creates the plugin from the container.
   *
   * @param \Symfony\Component\DependencyInjection\ContainerInterface $container
   *   The service container.
   * @param array<string, mixed> $configuration
   *   The plugin configuration.
   * @param string $plugin_id
   *   The plugin ID.
   * @param mixed $plugin_definition
   *   The plugin definition.
   *
   * @return static
   *   The plugin.
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition): static {
    return new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('config.factory'),
      $container->get('fact_drift.comparator'),
      $container->get('fact_drift_ai.call_guard'),
      $container->get('fact_drift_ai.prefilter'),
      $container->get('fact_drift_ai.prompt'),
      $container->get('fact_drift_ai.response_validator'),
      $container->get('cache.fact_drift_llm'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function isActive(): bool {
    return (bool) $this->configFactory->get('fact_drift_ai.settings')->get('enabled');
  }

  /**
   * {@inheritdoc}
   */
  public function extract(string $text): array {
    return [];
  }

  /**
   * {@inheritdoc}
   */
  public function extractInContext(string $text, ExtractionContext $context): array {
    $held = $this->guard->gate();
    if ($held !== NULL) {
      $this->guard->countHold();
      $context->hold($held);
      return [];
    }
    $settings = $this->configFactory->get('fact_drift_ai.settings');
    if (!$this->mayBeSent($context, $settings->get('allowlist') ?? [])) {
      return [];
    }
    $mode = (string) $settings->get('mode');
    $types = $this->requestedTypes($mode, $context);
    if (!$types) {
      return [];
    }
    $claims = [];
    $windows = $this->prefilter->windows($text, (bool) $settings->get('prefilter'), (int) $settings->get('max_chars'));
    foreach ($windows as $window) {
      $items = $this->windowItems($window['text'], $types, $context);
      if ($items === NULL) {
        break;
      }
      foreach ($items as $item) {
        $offset = $window['offset'] + $item['offset'];
        $claims[] = new Claim(
          $item['type'],
          mb_substr($window['text'], $item['offset'], $item['length']),
          $item['value'],
          $offset,
          $item['length'],
          $this->comparator->snippet($text, $offset, $item['length']),
        );
      }
    }
    if ($mode === 'alongside') {
      $claims = array_values(array_filter($claims, fn (Claim $claim): bool => !$this->overlapsPrior($claim, $context->priorClaims)));
    }
    return $claims;
  }

  /**
   * {@inheritdoc}
   */
  public function runSummary(): array {
    return $this->guard->runSummary();
  }

  /**
   * Tells whether a field of a node may be sent to the model at all.
   *
   * @param \Drupal\fact_drift\Value\ExtractionContext $context
   *   The node and field.
   * @param array<int, array<string, mixed>> $allowlist
   *   The allowed fields.
   *
   * @return bool
   *   TRUE when the anonymous user can view the node and the field is a
   *   configurable field on the allowlist for the node's bundle. An allowlist
   *   entry naming a base field is refused.
   */
  protected function mayBeSent(ExtractionContext $context, array $allowlist): bool {
    if (!$context->node->access('view', new AnonymousUserSession())) {
      return FALSE;
    }
    foreach ($allowlist as $allowed) {
      if (($allowed['bundle'] ?? NULL) === $context->node->bundle() && ($allowed['field'] ?? NULL) === $context->fieldName) {
        $definition = $context->node->getFieldDefinition($context->fieldName);
        return $definition !== NULL && !$definition->getFieldStorageDefinition()->isBaseField();
      }
    }
    return FALSE;
  }

  /**
   * Chooses the claim types to ask for.
   *
   * @param string $mode
   *   The mode, fallback or alongside.
   * @param \Drupal\fact_drift\Value\ExtractionContext $context
   *   The context carrying the claims found so far.
   *
   * @return string[]
   *   In fallback mode the declared types no earlier claim of the field has;
   *   in alongside mode all declared types.
   */
  protected function requestedTypes(string $mode, ExtractionContext $context): array {
    $types = array_values($this->getPluginDefinition()['claim_types'] ?? []);
    if ($mode === 'alongside') {
      return $types;
    }
    $found = array_map(static fn (Claim $claim): string => $claim->claimType, $context->priorClaims);
    return array_values(array_diff($types, $found));
  }

  /**
   * Gets the validated claims of one window, from the cache or the model.
   *
   * @param string $window
   *   The window text.
   * @param string[] $types
   *   The claim types to ask for.
   * @param \Drupal\fact_drift\Value\ExtractionContext $context
   *   The context, on which deferred or held work is recorded.
   *
   * @return list<array{type: string, value: string, offset: int, length: int}>|null
   *   The claims with window-relative character offsets, or NULL when the
   *   field must stop because it was deferred or held.
   */
  protected function windowItems(string $window, array $types, ExtractionContext $context): ?array {
    $cid = $this->cacheId($window, $types);
    $cached = $this->cache->get($cid);
    if ($cached) {
      $this->guard->countCacheHit();
      return $cached->data;
    }
    $result = $this->guard->call($this->prompt->input($window, $types));
    if ($result->signal === CallResult::DEFERRED) {
      $context->defer($result->delay, (string) $result->reason);
      return NULL;
    }
    if ($result->signal === CallResult::HELD) {
      $context->hold((string) $result->reason);
      return NULL;
    }
    if ($result->text === NULL) {
      return [];
    }
    $validated = $this->validator->validate($result->text, $window, $types);
    $this->guard->countRejections($validated['rejections']);
    $items = array_map(static fn (array $claim): array => [
      'type' => $claim['type'],
      'value' => $claim['value'],
      'offset' => $claim['offset'],
      'length' => $claim['length'],
    ], $validated['claims']);
    if (!isset($validated['rejections']['invalid_json']) && !isset($validated['rejections']['invalid_schema'])) {
      $this->cache->set($cid, $items, Cache::PERMANENT);
    }
    return $items;
  }

  /**
   * Builds the cache ID of a window: a hash, never the text.
   *
   * @param string $window
   *   The window text.
   * @param string[] $types
   *   The claim types asked for.
   *
   * @return string
   *   The cache ID.
   */
  protected function cacheId(string $window, array $types): string {
    $settings = $this->configFactory->get('fact_drift_ai.settings');
    sort($types);
    return 'llm:' . hash('sha256', json_encode([
      $window,
      (string) $settings->get('provider'),
      (string) $settings->get('model'),
      $types,
      Prompt::VERSION,
    ], JSON_THROW_ON_ERROR));
  }

  /**
   * Tells whether a claim overlaps the span of any earlier claim.
   *
   * @param \Drupal\fact_drift\Value\Claim $claim
   *   The claim.
   * @param \Drupal\fact_drift\Value\Claim[] $prior
   *   The earlier claims of the field.
   *
   * @return bool
   *   TRUE when the spans share at least one character.
   */
  protected function overlapsPrior(Claim $claim, array $prior): bool {
    foreach ($prior as $other) {
      if ($claim->offset < $other->offset + $other->length && $other->offset < $claim->offset + $claim->length) {
        return TRUE;
      }
    }
    return FALSE;
  }

}
