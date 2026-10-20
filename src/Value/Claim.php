<?php

namespace Drupal\fact_drift\Value;

/**
 * One value found in published content.
 */
final class Claim {

  /**
   * Constructs a claim.
   *
   * @param string $claimType
   *   The type of claim, for example phone or hours.
   * @param string $raw
   *   The text as written.
   * @param string $value
   *   The normalised value.
   * @param int $offset
   *   The character offset of the claim in the plain text.
   * @param int $length
   *   The length of the claim in characters.
   * @param string $snippet
   *   Up to 300 characters of surrounding plain text.
   * @param string $extractor
   *   (optional) The ID of the extractor plugin that produced the claim; the
   *   scanner stamps it, so extractors need not set it.
   */
  public function __construct(
    public readonly string $claimType,
    public readonly string $raw,
    public readonly string $value,
    public readonly int $offset,
    public readonly int $length,
    public readonly string $snippet,
    public readonly string $extractor = '',
  ) {
  }

  /**
   * Returns a copy of the claim attributed to an extractor.
   *
   * @param string $extractor
   *   The ID of the extractor plugin.
   *
   * @return static
   *   A claim with every other value unchanged.
   */
  public function withExtractor(string $extractor): static {
    return new static($this->claimType, $this->raw, $this->value, $this->offset, $this->length, $this->snippet, $extractor);
  }

}
