<?php

namespace Drupal\fact_drift\Attribute;

use Drupal\Component\Plugin\Attribute\Plugin;
use Drupal\Core\StringTranslation\TranslatableMarkup;

/**
 * Defines a ClaimExtractor plugin attribute.
 *
 * A plain ClaimExtractor plugin finds claims of one type in text. One with a
 * NULL claim_type finds only the types it lists in claim_types.
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
   * @param string|null $claim_type
   *   The one type of claim the plugin finds and labels, for example phone or
   *   hours, or NULL for an extractor that finds only types others declare.
   * @param int $weight
   *   (optional) The order among extractors; lower runs first.
   * @param class-string|null $deriver
   *   (optional) The deriver class.
   * @param string[] $claim_types
   *   (optional) The claim types an extractor with a NULL claim_type finds.
   * @param \Drupal\Core\StringTranslation\TranslatableMarkup|null $marker
   *   (optional) The short text shown beside the findings of a contextual
   *   extractor in the report. Plain extractors have none.
   * @param \Drupal\Core\StringTranslation\TranslatableMarkup|null $claim_label
   *   (optional) The label of the claim type, shown in the report and its
   *   filters. Defaults to the plugin label.
   */
  public function __construct(
    public readonly string $id,
    public readonly TranslatableMarkup $label,
    public readonly ?string $claim_type,
    public readonly int $weight = 0,
    public readonly ?string $deriver = NULL,
    public readonly array $claim_types = [],
    public readonly ?TranslatableMarkup $marker = NULL,
    public readonly ?TranslatableMarkup $claim_label = NULL,
  ) {
  }

}
