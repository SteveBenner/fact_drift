<?php

namespace Drupal\fact_drift\Value;

use Drupal\node\NodeInterface;

/**
 * What a contextual extractor is given for one field, and where it reports.
 */
final class ExtractionContext {

  /**
   * The largest delay asked for, in seconds.
   */
  protected int $delay = 0;

  /**
   * The reason given with the largest deferral.
   */
  protected ?string $deferredReason = NULL;

  /**
   * Whether the field is deferred.
   */
  protected bool $deferred = FALSE;

  /**
   * Whether the field is held.
   */
  protected bool $held = FALSE;

  /**
   * The reason given with the hold.
   */
  protected ?string $heldReason = NULL;

  /**
   * Constructs an extraction context.
   *
   * @param \Drupal\node\NodeInterface $node
   *   The node being scanned.
   * @param string $fieldName
   *   The machine name of the field being scanned.
   * @param \Drupal\fact_drift\Value\Claim[] $priorClaims
   *   The claims earlier extractors found in the field.
   */
  public function __construct(
    public readonly NodeInterface $node,
    public readonly string $fieldName,
    public readonly array $priorClaims,
  ) {
  }

  /**
   * Marks the field deferred: unfinished in this scan, to be retried later.
   *
   * @param int $delaySeconds
   *   The seconds to wait before the retry; the largest one asked for is kept.
   * @param string $reason
   *   Why the work was deferred.
   */
  public function defer(int $delaySeconds, string $reason): void {
    $this->deferred = TRUE;
    if ($delaySeconds > $this->delay || $this->deferredReason === NULL) {
      $this->delay = max($delaySeconds, $this->delay);
      $this->deferredReason = $reason;
    }
  }

  /**
   * Marks the field held: blocked in this scan and not retried.
   *
   * @param string $reason
   *   Why the work is blocked.
   */
  public function hold(string $reason): void {
    $this->held = TRUE;
    $this->heldReason = $reason;
  }

  /**
   * Tells whether the field was deferred.
   *
   * @return bool
   *   TRUE when defer() was called.
   */
  public function isDeferred(): bool {
    return $this->deferred;
  }

  /**
   * Tells whether the field was held.
   *
   * @return bool
   *   TRUE when hold() was called.
   */
  public function isHeld(): bool {
    return $this->held;
  }

  /**
   * Gets the largest deferral asked for.
   *
   * @return int
   *   The delay in seconds, or 0 when none was asked for.
   */
  public function delay(): int {
    return $this->delay;
  }

  /**
   * Gets the reason of the largest deferral, else of the hold.
   *
   * @return string|null
   *   The reason, or NULL when the field was neither deferred nor held.
   */
  public function reason(): ?string {
    return $this->deferredReason ?? $this->heldReason;
  }

}
