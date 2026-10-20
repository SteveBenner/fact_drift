<?php

namespace Drupal\fact_drift_ai;

/**
 * The outcome of one guarded call to the model.
 */
final class CallResult {

  /**
   * The signal for work that did not finish and is to be retried later.
   */
  public const DEFERRED = 'deferred';

  /**
   * The signal for work that is blocked in this scan and not retried.
   */
  public const HELD = 'held';

  /**
   * The signal for one window that failed and is skipped.
   */
  public const REJECTED = 'rejected';

  /**
   * Constructs a call result.
   *
   * @param string|null $text
   *   The reply text, or NULL when there is no reply.
   * @param string|null $signal
   *   NULL for a reply, else deferred, held or rejected.
   * @param int $delay
   *   For a deferral, the seconds to wait before retrying.
   * @param string|null $reason
   *   For a signal, why it was given.
   * @param int|null $inputTokens
   *   The prompt tokens, when the provider reports them.
   * @param int|null $outputTokens
   *   The completion tokens, when the provider reports them.
   */
  public function __construct(
    public readonly ?string $text = NULL,
    public readonly ?string $signal = NULL,
    public readonly int $delay = 0,
    public readonly ?string $reason = NULL,
    public readonly ?int $inputTokens = NULL,
    public readonly ?int $outputTokens = NULL,
  ) {
  }

}
