<?php

namespace Drupal\Tests\fact_drift_ai\Kernel;

use Psr\Log\AbstractLogger;

/**
 * A logger that keeps what it is given, for tests to read.
 */
class CollectingLogger extends AbstractLogger {

  /**
   * The entries logged.
   *
   * @var list<array{level: string, message: string, context: array<string, mixed>}>
   */
  public array $entries = [];

  /**
   * {@inheritdoc}
   *
   * @param mixed $level
   *   The level.
   * @param string|\Stringable $message
   *   The message.
   * @param array<string, mixed> $context
   *   The context.
   */
  public function log($level, string|\Stringable $message, array $context = []): void {
    $this->entries[] = ['level' => (string) $level, 'message' => (string) $message, 'context' => $context];
  }

}
