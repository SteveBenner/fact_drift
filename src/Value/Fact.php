<?php

namespace Drupal\fact_drift\Value;

/**
 * One true value from the canonical source.
 */
final class Fact {

  /**
   * Constructs a fact.
   *
   * @param string $sourceId
   *   The ID of the source entity the fact came from.
   * @param string $key
   *   The fact's key within its source.
   * @param string $name
   *   The name the fact is known by, for example a clinic's name.
   * @param string[] $aliases
   *   Other names the fact is known by.
   * @param string $claimType
   *   The type of claim the fact answers, for example phone or hours.
   * @param string $value
   *   The normalised true value.
   * @param string[] $formerValues
   *   Normalised values the fact used to have.
   */
  public function __construct(
    public readonly string $sourceId,
    public readonly string $key,
    public readonly string $name,
    public readonly array $aliases,
    public readonly string $claimType,
    public readonly string $value,
    public readonly array $formerValues = [],
  ) {
  }

  /**
   * Returns a copy of the fact attributed to the given source.
   *
   * @param string $sourceId
   *   The ID of the source entity.
   *
   * @return static
   *   The copy.
   */
  public function withSourceId(string $sourceId): static {
    return new static($sourceId, $this->key, $this->name, $this->aliases, $this->claimType, $this->value, $this->formerValues);
  }

}
