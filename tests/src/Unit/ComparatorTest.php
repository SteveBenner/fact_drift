<?php

namespace Drupal\Tests\fact_drift\Unit;

use Drupal\fact_drift\Comparator;
use Drupal\fact_drift\Value\Claim;
use Drupal\fact_drift\Value\Fact;
use Drupal\Tests\UnitTestCase;

/**
 * Tests the normalisation and comparison rules of the comparator.
 *
 * @group fact_drift
 * @covers \Drupal\fact_drift\Comparator
 */
class ComparatorTest extends UnitTestCase {

  /**
   * The comparator under test.
   */
  protected Comparator $comparator;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->comparator = new Comparator();
  }

  /**
   * Tests that phone numbers in common formats reduce to ten digits.
   *
   * @param string $raw
   *   The phone number as written.
   * @param string|null $expected
   *   The expected normal form, or NULL when it cannot be read.
   *
   * @dataProvider phoneProvider
   */
  public function testNormalisePhone(string $raw, ?string $expected): void {
    $this->assertSame($expected, $this->comparator->normalisePhone($raw));
  }

  /**
   * Provides phone numbers and their normal forms.
   *
   * @return array<string, array{0: string, 1: string|null}>
   *   The raw value and the expected result, keyed by description.
   */
  public static function phoneProvider(): array {
    return [
      'parentheses' => ['(909) 555-0100', '9095550100'],
      'dashes' => ['909-555-0100', '9095550100'],
      'dots' => ['909.555.0100', '9095550100'],
      'spaces' => ['909 555 0100', '9095550100'],
      'leading one' => ['1-909-555-0100', '9095550100'],
      'plus one' => ['+1 909 555 0100', '9095550100'],
      'extension' => ['+1 909.555.0100 ext. 12', '9095550100x12'],
      'short extension' => ['909 555 0100 x7', '9095550100x7'],
      'seven digits' => ['555-0100', NULL],
      'eleven digits without a one' => ['12345678901', '2345678901'],
      'no digits' => ['call us', NULL],
      'empty' => ['', NULL],
    ];
  }

  /**
   * Tests that opening hours reduce to the canonical grammar.
   *
   * @param string $raw
   *   The hours as written.
   * @param string|null $expected
   *   The expected canonical form, or NULL when they cannot be read.
   *
   * @dataProvider hoursProvider
   */
  public function testNormaliseHours(string $raw, ?string $expected): void {
    $this->assertSame($expected, $this->comparator->normaliseHours($raw));
  }

  /**
   * Provides hours and their canonical forms.
   *
   * @return array<string, array{0: string, 1: string|null}>
   *   The raw value and the expected result, keyed by description.
   */
  public static function hoursProvider(): array {
    return [
      'two segments' => ['Mon-Fri 8am-5pm; Sat 9am-12pm', 'Mon-Fri 08:00-17:00; Sat 09:00-12:00'],
      'long day names inherit the afternoon' => ['Monday to Friday 9-5pm', 'Mon-Fri 09:00-17:00'],
      'already canonical' => ['Mon-Fri 08:00-17:00', 'Mon-Fri 08:00-17:00'],
      'start inherits the meridiem of the end' => ['Mon-Fri 11-1pm', 'Mon-Fri 11:00-13:00'],
      'midnight start' => ['Tue 12am-12pm', 'Tue 00:00-12:00'],
      'noon start' => ['Wed 12pm-6pm', 'Wed 12:00-18:00'],
      'and between segments' => ['Mon-Fri 8am-5pm and Sat 9am-12pm', 'Mon-Fri 08:00-17:00; Sat 09:00-12:00'],
      'dotted meridiem with minutes' => ['Mon-Fri 8:30 a.m. - 5:00 p.m.', 'Mon-Fri 08:30-17:00'],
      'no meridiem anywhere' => ['9-5', NULL],
      'ambiguous mixed meridiem' => ['Mon-Fri 9am-5', NULL],
      'end before start' => ['Mon 5pm-9am', NULL],
      'twenty-four hour without zero' => ['Mon-Fri 9:00-5:00', NULL],
      'prose' => ['Closed Sundays', NULL],
      'empty' => ['', NULL],
    ];
  }

  /**
   * Tests that matches() accepts only the current value.
   */
  public function testMatches(): void {
    $fact = $this->fact('9095550100', ['9095550199']);
    $this->assertTrue($this->comparator->matches($fact, $this->claim('9095550100')));
    $this->assertFalse($this->comparator->matches($fact, $this->claim('9095550199')));
    $this->assertFalse($this->comparator->matches($fact, $this->claim('9095550123')));
  }

  /**
   * Tests that a retired value is a mismatch even if it equals the value.
   */
  public function testMatchesRejectsFormerValueEqualToCurrent(): void {
    $fact = $this->fact('9095550100', ['9095550100']);
    $this->assertFalse($this->comparator->matches($fact, $this->claim('9095550100')));
  }

  /**
   * Tests that a short text is returned whole as the snippet.
   */
  public function testSnippetOfShortTextIsWhole(): void {
    $this->assertSame('Call 909-555-0100 now', $this->comparator->snippet('Call 909-555-0100 now', 5, 12));
  }

  /**
   * Tests that a long text is cut around the claim with ellipses.
   */
  public function testSnippetOfLongTextIsBounded(): void {
    $text = str_repeat('word ', 100) . '909-555-0100 ' . str_repeat('word ', 100);
    $snippet = $this->comparator->snippet($text, 500, 12);
    $this->assertStringContainsString('909-555-0100', $snippet);
    $this->assertLessThanOrEqual(300, mb_strlen($snippet));
    $this->assertStringStartsWith('…', $snippet);
    $this->assertStringEndsWith('…', $snippet);
  }

  /**
   * Builds a phone fact.
   *
   * @param string $value
   *   The current value.
   * @param string[] $former
   *   The retired values.
   *
   * @return \Drupal\fact_drift\Value\Fact
   *   The fact.
   */
  protected function fact(string $value, array $former): Fact {
    return new Fact('clinics', 'riverside', 'Riverside Family Clinic', [], 'phone', $value, $former);
  }

  /**
   * Builds a phone claim.
   *
   * @param string $value
   *   The normalised value.
   *
   * @return \Drupal\fact_drift\Value\Claim
   *   The claim.
   */
  protected function claim(string $value): Claim {
    return new Claim('phone', $value, $value, 0, strlen($value), $value);
  }

}
