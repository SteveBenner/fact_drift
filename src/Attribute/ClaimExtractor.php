<?php

namespace Drupal\fact_drift\Attribute;

use Drupal\Component\Plugin\Attribute\Plugin;
use Drupal\Core\StringTranslation\TranslatableMarkup;

/**
 * Defines a ClaimExtractor plugin attribute.
 *
 * A ClaimExtractor plugin finds claims of one type in text.
 *
 * @see \Drupal\fact_drift\ClaimExtractor\ClaimExtractorManager
 * @see \Drupal\fact_drift\ClaimExtractor\ClaimExtractorInterface
 */
#[\Attribute(\Attribute::TARGET_CLASS)]
class ClaimExtractor extends Plugin {

  /**
   * Constructs a ClaimExtractor attribute.
   *
   * @param string $id
   *   The plugin ID.
   * @param \Drupal\Core\StringTranslation\TranslatableMarkup $label
   *   The human-readable name of the plugin.
   * @param string $claim_type
   *   The type of claim the plugin finds, for example phone or hours.
   * @param int $weight
   *   (optional) The order among extractors; lower runs first.
   * @param class-string|null $deriver
   *   (optional) The deriver class.
   */
  public function __construct(
    public readonly string $id,
    public readonly TranslatableMarkup $label,
    public readonly string $claim_type,
    public readonly int $weight = 0,
    public readonly ?string $deriver = NULL,
  ) {
  }

}
