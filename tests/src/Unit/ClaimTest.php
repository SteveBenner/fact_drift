<?php

namespace Drupal\Tests\fact_drift\Unit;

use Drupal\fact_drift\Value\Claim;
use Drupal\Tests\UnitTestCase;

/**
 * Tests the claim value object.
 *
 * @group fact_drift
 * @covers \Drupal\fact_drift\Value\Claim
 */
class ClaimTest extends UnitTestCase {

  /**
   * Tests that a claim has no extractor until one is stamped on it.
   */
  public function testExtractorDefaultsToEmpty(): void {
    $claim = new Claim('phone', '909-555-0100', '9095550100', 5, 12, 'Call 909-555-0100');
    $this->assertSame('', $claim->extractor);
  }

  /**
   * Tests that withExtractor returns a copy that keeps every other value.
   */
  public function testWithExtractorKeepsEveryOtherValue(): void {
    $claim = new Claim('phone', '909-555-0100', '9095550100', 5, 12, 'Call 909-555-0100');
    $stamped = $claim->withExtractor('llm');
    $this->assertNotSame($claim, $stamped);
    $this->assertSame('', $claim->extractor);
    $this->assertSame('llm', $stamped->extractor);
    $this->assertSame('phone', $stamped->claimType);
    $this->assertSame('909-555-0100', $stamped->raw);
    $this->assertSame('9095550100', $stamped->value);
    $this->assertSame(5, $stamped->offset);
    $this->assertSame(12, $stamped->length);
    $this->assertSame('Call 909-555-0100', $stamped->snippet);
  }

}
