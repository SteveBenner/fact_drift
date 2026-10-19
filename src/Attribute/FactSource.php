<?php

namespace Drupal\fact_drift\Attribute;

use Drupal\Component\Plugin\Attribute\Plugin;
use Drupal\Core\StringTranslation\TranslatableMarkup;

/**
 * Defines a FactSource plugin attribute.
 *
 * A FactSource plugin reads facts from one kind of canonical source.
 *
 * @see \Drupal\fact_drift\FactSource\FactSourceManager
 * @see \Drupal\fact_drift\FactSource\FactSourceInterface
 */
#[\Attribute(\Attribute::TARGET_CLASS)]
class FactSource extends Plugin {

  /**
   * Constructs a FactSource attribute.
   *
   * @param string $id
   *   The plugin ID.
   * @param \Drupal\Core\StringTranslation\TranslatableMarkup $label
   *   The human-readable name of the plugin.
   * @param \Drupal\Core\StringTranslation\TranslatableMarkup|null $description
   *   (optional) A short description of the plugin.
   * @param class-string|null $deriver
   *   (optional) The deriver class.
   */
  public function __construct(
    public readonly string $id,
    public readonly TranslatableMarkup $label,
    public readonly ?TranslatableMarkup $description = NULL,
    public readonly ?string $deriver = NULL,
  ) {
  }

}
