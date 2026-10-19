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
   */
  public function __construct(
    public readonly string $claimType,
    public readonly string $raw,
    public readonly string $value,
    public readonly int $offset,
    public readonly int $length,
    public readonly string $snippet,
  ) {
  }

}
