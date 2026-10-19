<?php

namespace Drupal\fact_drift\ClaimExtractor;

use Drupal\Component\Utility\SortArray;
use Drupal\Core\Cache\CacheBackendInterface;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\Plugin\DefaultPluginManager;
use Drupal\fact_drift\Attribute\ClaimExtractor;

/**
 * Discovers claim extractor plugins.
 */
class ClaimExtractorManager extends DefaultPluginManager {

  /**
   * Constructs a ClaimExtractorManager.
   *
   * @param \Traversable<string, string|string[]> $namespaces
   *   The namespaces searched for plugins.
   * @param \Drupal\Core\Cache\CacheBackendInterface $cache_backend
   *   The cache backend for plugin definitions.
   * @param \Drupal\Core\Extension\ModuleHandlerInterface $module_handler
   *   The module handler.
   */
  public function __construct(\Traversable $namespaces, CacheBackendInterface $cache_backend, ModuleHandlerInterface $module_handler) {
    parent::__construct('Plugin/ClaimExtractor', $namespaces, $module_handler, ClaimExtractorInterface::class, ClaimExtractor::class);
    $this->alterInfo('fact_drift_extractor_info');
    $this->setCacheBackend($cache_backend, 'fact_drift_extractor_plugins');
  }

  /**
   * {@inheritdoc}
   *
   * @return array<string, array<string, mixed>>
   *   The plugin definitions sorted by weight.
   */
  protected function findDefinitions() {
    $definitions = parent::findDefinitions();
    uasort($definitions, static fn (array $a, array $b): int => SortArray::sortByKeyInt($a, $b, 'weight'));
    return $definitions;
  }

}
