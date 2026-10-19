<?php

namespace Drupal\fact_drift;

use Drupal\Core\Config\Entity\ConfigEntityListBuilder;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Entity\EntityTypeInterface;
use Drupal\fact_drift\Entity\Source;
use Drupal\fact_drift\FactSource\FactSourceManager;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Lists the configured fact sources.
 *
 * @phpstan-consistent-constructor
 */
class SourceListBuilder extends ConfigEntityListBuilder {

  /**
   * Constructs a SourceListBuilder.
   *
   * @param \Drupal\Core\Entity\EntityTypeInterface $entity_type
   *   The entity type definition.
   * @param \Drupal\Core\Entity\EntityStorageInterface $storage
   *   The source storage.
   * @param \Drupal\fact_drift\FactSource\FactSourceManager $sourceManager
   *   The fact source plugin manager.
   */
  public function __construct(EntityTypeInterface $entity_type, EntityStorageInterface $storage, protected FactSourceManager $sourceManager) {
    parent::__construct($entity_type, $storage);
  }

  /**
   * {@inheritdoc}
   */
  public static function createInstance(ContainerInterface $container, EntityTypeInterface $entity_type): static {
    return new static(
      $entity_type,
      $container->get('entity_type.manager')->getStorage($entity_type->id()),
      $container->get('fact_drift.source_manager'),
    );
  }

  /**
   * {@inheritdoc}
   *
   * @return array<string, mixed>
   *   The header cells.
   */
  public function buildHeader(): array {
    $header['label'] = $this->t('Label');
    $header['id'] = $this->t('Machine name');
    $header['plugin'] = $this->t('Plugin');
    $header['claim_types'] = $this->t('Claim types');
    $header['status'] = $this->t('Enabled');
    return $header + parent::buildHeader();
  }

  /**
   * {@inheritdoc}
   *
   * @return array<string, mixed>
   *   The row cells.
   */
  public function buildRow(EntityInterface $entity): array {
    assert($entity instanceof Source);
    $definition = $this->sourceManager->getDefinition($entity->getPluginId(), FALSE);
    $row['label'] = $entity->label();
    $row['id'] = $entity->id();
    $row['plugin'] = $definition['label'] ?? $entity->getPluginId();
    $row['claim_types'] = implode(', ', $entity->getClaimTypes());
    $row['status'] = $entity->status() ? $this->t('Yes') : $this->t('No');
    return $row + parent::buildRow($entity);
  }

}
