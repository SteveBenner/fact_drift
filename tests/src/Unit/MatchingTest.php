<?php

namespace Drupal\Tests\fact_drift\Unit;

use Drupal\fact_drift\Comparator;
use Drupal\fact_drift\Plugin\ClaimExtractor\PhoneExtractor;
use Drupal\fact_drift\Value\Fact;
use Drupal\Tests\UnitTestCase;

/**
 * Tests how claims are attributed to named facts.
 *
 * @group fact_drift
 * @covers \Drupal\fact_drift\Comparator::attribute
 */
class MatchingTest extends UnitTestCase {

  /**
   * The comparator under test.
   */
  protected Comparator $comparator;

  /**
   * The extractor that supplies claims.
   */
  protected PhoneExtractor $extractor;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->comparator = new Comparator();
    $this->extractor = new PhoneExtractor([], 'phone', [], $this->comparator);
  }

  /**
   * Builds the Riverside phone fact.
   *
   * @return \Drupal\fact_drift\Value\Fact
   *   The fact.
   */
  protected function riverside(): Fact {
    return new Fact('clinics', 'riverside', 'Riverside Family Clinic', ['Riverside Clinic'], 'phone', '9095550100', ['9095550199']);
  }

  /**
   * Attributes the phone numbers of a text to the Riverside fact.
   *
   * @param string $text
   *   The plain text.
   *
   * @return array<int, array{0: \Drupal\fact_drift\Value\Fact, 1: \Drupal\fact_drift\Value\Claim}>
   *   The attributed pairs.
   */
  protected function attribute(string $text): array {
    return $this->comparator->attribute($this->extractor->extract($text), [$this->riverside()], $text);
  }

  /**
   * Tests that a number shortly after the name belongs to the fact.
   */
  public function testNumberWithinReachOfNameIsAttributed(): void {
    $pairs = $this->attribute('Riverside Family Clinic is open daily. Call 909-555-0123 today.');
    $this->assertCount(1, $pairs);
    $this->assertSame('9095550123', $pairs[0][1]->value);
  }

  /**
   * Tests that a number beyond the reach of the name is not attributed.
   */
  public function testNumberBeyondReachOfNameIsDropped(): void {
    $text = 'Riverside Family Clinic ' . str_repeat('x', 220) . ' Call 909-555-0123 today.';
    $this->assertSame([], $this->attribute($text));
  }

  /**
   * Tests that the reach is measured from the end of the name.
   */
  public function testNumberJustInsideReachIsAttributed(): void {
    $padding = str_repeat('x', 198);
    $text = 'Riverside Family Clinic ' . $padding . ' 909-555-0123';
    $this->assertCount(1, $this->attribute($text));
  }

  /**
   * Tests that an alias attributes a number as the name does.
   */
  public function testAliasAttributesNumber(): void {
    $pairs = $this->attribute('Visit the riverside clinic. Phone 909-555-0123.');
    $this->assertCount(1, $pairs);
    $this->assertSame('riverside', $pairs[0][0]->key);
  }

  /**
   * Tests that a retired value is attributed without the name nearby.
   */
  public function testRetiredValueIsAttributedWithoutName(): void {
    $pairs = $this->attribute('For appointments dial 909-555-0199 any weekday.');
    $this->assertCount(1, $pairs);
    $this->assertSame('9095550199', $pairs[0][1]->value);
    $this->assertFalse($this->comparator->matches($pairs[0][0], $pairs[0][1]));
  }

  /**
   * Tests that a claim matching no fact is dropped.
   */
  public function testUnmatchedClaimIsDropped(): void {
    $this->assertSame([], $this->attribute('The weather office can be reached on 909-555-0123.'));
  }

  /**
   * Tests that a name does not match inside a longer word.
   */
  public function testNameInsideLongerWordDoesNotMatch(): void {
    $this->assertSame([], $this->attribute('Superriverside Family Clinic phone 909-555-0123.'));
    $this->assertSame([], $this->attribute('Riverside Family Clinics phone 909-555-0123.'));
  }

  /**
   * Tests that a claim found by name and as a retired value counts once.
   */
  public function testClaimIsDeduplicated(): void {
    $pairs = $this->attribute('Riverside Family Clinic: 909-555-0199.');
    $this->assertCount(1, $pairs);
  }

  /**
   * Tests that two numbers after the name are each attributed once.
   */
  public function testEachNameOccurrenceClaimsItsNearestNumber(): void {
    $pairs = $this->attribute('Riverside Family Clinic 909-555-0123. Riverside Clinic 909-555-0124.');
    $this->assertCount(2, $pairs);
  }

}
