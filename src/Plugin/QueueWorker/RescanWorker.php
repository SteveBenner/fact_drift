<?php

namespace Drupal\fact_drift\Plugin\QueueWorker;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\Queue\Attribute\QueueWorker;
use Drupal\Core\Queue\DelayedRequeueException;
use Drupal\Core\Queue\QueueWorkerBase;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\fact_drift\Scanner;
use Drupal\node\NodeInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Rescans one node in the background.
 *
 * The queue item is an array with the key nid.
 *
 * @phpstan-consistent-constructor
 */
#[QueueWorker(
  id: 'fact_drift_rescan',
  title: new TranslatableMarkup('Fact drift rescan'),
  cron: ['time' => 30],
)]
class RescanWorker extends QueueWorkerBase implements ContainerFactoryPluginInterface {

  /**
   * Constructs a RescanWorker.
   *
   * @param array<string, mixed> $configuration
   *   The plugin configuration.
   * @param string $plugin_id
   *   The plugin ID.
   * @param mixed $plugin_definition
   *   The plugin definition.
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entityTypeManager
   *   The entity type manager.
   * @param \Drupal\fact_drift\Scanner $scanner
   *   The scanner.
   */
  public function __construct(array $configuration, $plugin_id, $plugin_definition, protected EntityTypeManagerInterface $entityTypeManager, protected Scanner $scanner) {
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
      $container->get('entity_type.manager'),
      $container->get('fact_drift.scanner'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function processItem($data): void {
    $node = $this->entityTypeManager->getStorage('node')->load((int) ($data['nid'] ?? 0));
    if (!$node instanceof NodeInterface) {
      return;
    }
    $result = $this->scanner->scanNode($node);
    if ($result['deferred_delay'] > 0) {
      throw new DelayedRequeueException($result['deferred_delay'], (string) $result['deferred_reason']);
    }
  }

}
