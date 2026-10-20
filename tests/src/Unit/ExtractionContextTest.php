<?php

namespace Drupal\Tests\fact_drift\Unit;

use Drupal\fact_drift\Value\Claim;
use Drupal\fact_drift\Value\ExtractionContext;
use Drupal\node\NodeInterface;
use Drupal\Tests\UnitTestCase;

/**
 * Tests where a contextual extractor records deferred and held work.
 *
 * @group fact_drift
 * @covers \Drupal\fact_drift\Value\ExtractionContext
 */
class ExtractionContextTest extends UnitTestCase {

  /**
   * Builds a context for a mocked node.
   *
   * @param \Drupal\fact_drift\Value\Claim[] $claims
   *   The prior claims.
   *
   * @return \Drupal\fact_drift\Value\ExtractionContext
   *   The context.
   */
  protected function context(array $claims = []): ExtractionContext {
    return new ExtractionContext($this->createMock(NodeInterface::class), 'body', $claims);
  }

  /**
   * Tests that a new context carries its inputs and reports no deferral.
   */
  public function testFreshContext(): void {
    $claim = new Claim('phone', '909-555-0100', '9095550100', 5, 12, 'Call 909-555-0100', 'phone');
    $context = $this->context([$claim]);
    $this->assertSame('body', $context->fieldName);
    $this->assertSame([$claim], $context->priorClaims);
    $this->assertFalse($context->isDeferred());
    $this->assertFalse($context->isHeld());
    $this->assertSame(0, $context->delay());
    $this->assertNull($context->reason());
  }

  /**
   * Tests that defer keeps the largest delay and the reason given with it.
   */
  public function testDeferKeepsTheLargestDelayAndItsReason(): void {
    $context = $this->context();
    $context->defer(900, 'outage');
    $context->defer(60, 'rate_limited');
    $this->assertTrue($context->isDeferred());
    $this->assertFalse($context->isHeld());
    $this->assertSame(900, $context->delay());
    $this->assertSame('outage', $context->reason());
    $context->defer(3600, 'budget');
    $this->assertSame(3600, $context->delay());
    $this->assertSame('budget', $context->reason());
  }

  /**
   * Tests that hold marks the field held without asking for a delay.
   */
  public function testHoldMarksHeld(): void {
    $context = $this->context();
    $context->hold('kill_switch');
    $this->assertTrue($context->isHeld());
    $this->assertFalse($context->isDeferred());
    $this->assertSame(0, $context->delay());
    $this->assertSame('kill_switch', $context->reason());
  }

}
