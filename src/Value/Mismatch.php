<?php

namespace Drupal\fact_drift\Value;

/**
 * A claim that contradicts the fact it refers to, found in one field.
 */
final class Mismatch {

  /**
   * Constructs a mismatch.
   *
   * @param string $fieldName
   *   The machine name of the field the claim was found in.
   * @param \Drupal\fact_drift\Value\Fact $fact
   *   The fact the claim refers to.
   * @param \Drupal\fact_drift\Value\Claim $claim
   *   The claim that contradicts the fact.
   */
  public function __construct(
    public readonly string $fieldName,
    public readonly Fact $fact,
    public readonly Claim $claim,
  ) {
  }

}
