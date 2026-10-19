<?php

namespace Drupal\fact_drift\Entity;

use Drupal\Core\Entity\ContentEntityBase;
use Drupal\Core\Entity\EntityTypeInterface;
use Drupal\Core\Field\BaseFieldDefinition;
use Drupal\node\NodeInterface;

/**
 * Defines the fact drift finding entity.
 *
 * A finding is a stored mismatch between a claim in published content and the
 * fact it refers to.
 *
 * @ContentEntityType(
 *   id = "fact_drift_finding",
 *   label = @Translation("Fact drift finding"),
 *   label_collection = @Translation("Fact drift findings"),
 *   label_singular = @Translation("fact drift finding"),
 *   label_plural = @Translation("fact drift findings"),
 *   handlers = {
 *     "access" = "Drupal\fact_drift\Entity\FindingAccessControlHandler",
 *     "views_data" = "Drupal\views\EntityViewsData",
 *   },
 *   base_table = "fact_drift_finding",
 *   admin_permission = "administer fact drift",
 *   entity_keys = {
 *     "id" = "id",
 *     "uuid" = "uuid",
 *   },
 * )
 */
class Finding extends ContentEntityBase {

  public const STATE_OPEN = 'open';

  public const STATE_CLOSED = 'closed';

  public const STATE_DISMISSED = 'dismissed';

  /**
   * {@inheritdoc}
   */
  public static function baseFieldDefinitions(EntityTypeInterface $entity_type): array {
    $fields = parent::baseFieldDefinitions($entity_type);

    $fields['node'] = BaseFieldDefinition::create('entity_reference')
      ->setLabel(t('Page'))
      ->setDescription(t('The node the claim was found in.'))
      ->setRequired(TRUE)
      ->setSetting('target_type', 'node');

    $fields['field_name'] = BaseFieldDefinition::create('string')
      ->setLabel(t('Field'))
      ->setDescription(t('The machine name of the field the claim was found in.'))
      ->setRequired(TRUE)
      ->setSetting('max_length', 255);

    $fields['claim_type'] = BaseFieldDefinition::create('string')
      ->setLabel(t('Claim type'))
      ->setRequired(TRUE)
      ->setSetting('max_length', 64);

    $fields['fact_key'] = BaseFieldDefinition::create('string')
      ->setLabel(t('Fact key'))
      ->setDescription(t("The fact's key within its source."))
      ->setRequired(TRUE)
      ->setSetting('max_length', 255);

    $fields['source'] = BaseFieldDefinition::create('entity_reference')
      ->setLabel(t('Source'))
      ->setRequired(TRUE)
      ->setSetting('target_type', 'fact_drift_source');

    $fields['expected'] = BaseFieldDefinition::create('string')
      ->setLabel(t('Expected'))
      ->setDescription(t('The normalised fact value.'))
      ->setSetting('max_length', 255);

    $fields['found'] = BaseFieldDefinition::create('string')
      ->setLabel(t('Found'))
      ->setDescription(t('The normalised claim value.'))
      ->setSetting('max_length', 255);

    $fields['snippet'] = BaseFieldDefinition::create('string_long')
      ->setLabel(t('Snippet'))
      ->setDescription(t('Up to 300 characters of surrounding plain text.'));

    $fields['state'] = BaseFieldDefinition::create('list_string')
      ->setLabel(t('State'))
      ->setRequired(TRUE)
      ->setSetting('allowed_values', [
        self::STATE_OPEN => 'Open',
        self::STATE_CLOSED => 'Closed',
        self::STATE_DISMISSED => 'Dismissed',
      ])
      ->setDefaultValue(self::STATE_OPEN);

    $fields['dismiss_reason'] = BaseFieldDefinition::create('string_long')
      ->setLabel(t('Dismiss reason'));

    $fields['created'] = BaseFieldDefinition::create('created')
      ->setLabel(t('Created'))
      ->setDescription(t('When the mismatch was first seen.'));

    $fields['changed'] = BaseFieldDefinition::create('changed')
      ->setLabel(t('Changed'));

    $fields['closed'] = BaseFieldDefinition::create('timestamp')
      ->setLabel(t('Closed'))
      ->setDescription(t('When the mismatch was closed.'));

    return $fields;
  }

  /**
   * {@inheritdoc}
   */
  public function label(): string {
    return (string) $this->getClaimType() . ' ' . $this->getExpected() . ' / ' . $this->getFound();
  }

  /**
   * Gets the ID of the node the claim was found in.
   *
   * @return int|null
   *   The node ID.
   */
  public function getNodeId(): ?int {
    $items = $this->get('node');
    return $items->isEmpty() ? NULL : (int) $items->target_id;
  }

  /**
   * Gets the node the claim was found in.
   *
   * @return \Drupal\node\NodeInterface|null
   *   The node, or NULL when it no longer exists.
   */
  public function getNode(): ?NodeInterface {
    $node = $this->get('node')->entity;
    return $node instanceof NodeInterface ? $node : NULL;
  }

  /**
   * Gets the machine name of the field the claim was found in.
   *
   * @return string
   *   The field name.
   */
  public function getFieldName(): string {
    return (string) $this->get('field_name')->value;
  }

  /**
   * Gets the claim type.
   *
   * @return string
   *   The claim type.
   */
  public function getClaimType(): string {
    return (string) $this->get('claim_type')->value;
  }

  /**
   * Gets the fact's key within its source.
   *
   * @return string
   *   The fact key.
   */
  public function getFactKey(): string {
    return (string) $this->get('fact_key')->value;
  }

  /**
   * Gets the ID of the source the fact came from.
   *
   * @return string
   *   The source ID.
   */
  public function getSourceId(): string {
    return (string) $this->get('source')->target_id;
  }

  /**
   * Gets the normalised fact value.
   *
   * @return string
   *   The expected value.
   */
  public function getExpected(): string {
    return (string) $this->get('expected')->value;
  }

  /**
   * Gets the normalised claim value.
   *
   * @return string
   *   The found value.
   */
  public function getFound(): string {
    return (string) $this->get('found')->value;
  }

  /**
   * Gets the snippet of surrounding plain text.
   *
   * @return string
   *   The snippet.
   */
  public function getSnippet(): string {
    return (string) $this->get('snippet')->value;
  }

  /**
   * Gets the lifecycle state.
   *
   * @return string
   *   One of open, closed or dismissed.
   */
  public function getState(): string {
    return (string) $this->get('state')->value;
  }

  /**
   * Sets the lifecycle state.
   *
   * @param string $state
   *   One of open, closed or dismissed.
   *
   * @return $this
   */
  public function setState(string $state): static {
    $this->set('state', $state);
    return $this;
  }

  /**
   * Gets the time the mismatch was first seen.
   *
   * @return int
   *   The timestamp.
   */
  public function getCreatedTime(): int {
    return (int) $this->get('created')->value;
  }

}
