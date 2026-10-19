<?php

namespace Drupal\fact_drift\FactSource;

use Drupal\Core\Cache\CacheBackendInterface;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\Plugin\DefaultPluginManager;
use Drupal\fact_drift\Attribute\FactSource;

/**
 * Discovers fact source plugins.
 */
class FactSourceManager extends DefaultPluginManager {

  /**
   * Constructs a FactSourceManager.
   *
   * @param \Traversable<string, string|string[]> $namespaces
   *   The namespaces searched for plugins.
   * @param \Drupal\Core\Cache\CacheBackendInterface $cache_backend
   *   The cache backend for plugin definitions.
   * @param \Drupal\Core\Extension\ModuleHandlerInterface $module_handler
   *   The module handler.
   */
  public function __construct(\Traversable $namespaces, CacheBackendInterface $cache_backend, ModuleHandlerInterface $module_handler) {
    parent::__construct('Plugin/FactSource', $namespaces, $module_handler, FactSourceInterface::class, FactSource::class);
    $this->alterInfo('fact_drift_source_info');
    $this->setCacheBackend($cache_backend, 'fact_drift_source_plugins');
  }

}
