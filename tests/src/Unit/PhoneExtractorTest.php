<?php

namespace Drupal\Tests\fact_drift\Unit;

use Drupal\fact_drift\Comparator;
use Drupal\fact_drift\Plugin\ClaimExtractor\PhoneExtractor;
use Drupal\Tests\UnitTestCase;

/**
 * Tests the phone claim extractor.
 *
 * @group fact_drift
 * @covers \Drupal\fact_drift\Plugin\ClaimExtractor\PhoneExtractor
 */
class PhoneExtractorTest extends UnitTestCase {

  /**
   * The extractor under test.
   */
  protected PhoneExtractor $extractor;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->extractor = new PhoneExtractor([], 'phone', [], new Comparator());
  }

  /**
   * Tests the value, raw text, offset and length of one claim.
   */
  public function testClaimCarriesOffsetAndLength(): void {
    $text = 'Call (909) 555-0100 today';
    $claims = $this->extractor->extract($text);
    $this->assertCount(1, $claims);
    $this->assertSame('phone', $claims[0]->claimType);
    $this->assertSame('(909) 555-0100', $claims[0]->raw);
    $this->assertSame('9095550100', $claims[0]->value);
    $this->assertSame(5, $claims[0]->offset);
    $this->assertSame(14, $claims[0]->length);
    $this->assertSame($claims[0]->raw, mb_substr($text, $claims[0]->offset, $claims[0]->length));
  }

  /**
   * Tests that the offset counts characters, not bytes.
   */
  public function testOffsetCountsCharacters(): void {
    $text = 'Café — call 909-555-0100';
    $claims = $this->extractor->extract($text);
    $this->assertCount(1, $claims);
    $this->assertSame('909-555-0100', mb_substr($text, $claims[0]->offset, $claims[0]->length));
  }

  /**
   * Tests that every number in a text becomes a claim, in order.
   */
  public function testSeveralNumbers(): void {
    $claims = $this->extractor->extract('Main 909-555-0100, after hours 909.555.0142, fax 1-909-555-0163.');
    $this->assertSame(['9095550100', '9095550142', '9095550163'], array_map(static fn ($claim): string => $claim->value, $claims));
    $this->assertLessThan($claims[1]->offset, $claims[0]->offset);
    $this->assertLessThan($claims[2]->offset, $claims[1]->offset);
  }

  /**
   * Tests that the snippet of a short text is the text itself.
   */
  public function testSnippetOfShortText(): void {
    $text = 'Call 909-555-0100 today';
    $claims = $this->extractor->extract($text);
    $this->assertSame($text, $claims[0]->snippet);
  }

  /**
   * Tests that the snippet of a long text is bounded and holds the claim.
   */
  public function testSnippetOfLongText(): void {
    $text = str_repeat('lorem ipsum ', 60) . 'call 909-555-0100 now ' . str_repeat('dolor sit ', 60);
    $claims = $this->extractor->extract($text);
    $this->assertCount(1, $claims);
    $this->assertStringContainsString('909-555-0100', $claims[0]->snippet);
    $this->assertLessThanOrEqual(300, mb_strlen($claims[0]->snippet));
  }

  /**
   * Tests that extension suffixes stay with the number.
   */
  public function testExtensionIsPartOfTheClaim(): void {
    $claims = $this->extractor->extract('Dial 909-555-0100 ext. 12 please.');
    $this->assertCount(1, $claims);
    $this->assertSame('9095550100x12', $claims[0]->value);
  }

  /**
   * Tests that text which is not a phone number yields no claim.
   *
   * @param string $text
   *   The text.
   *
   * @dataProvider nonNumberProvider
   */
  public function testNonNumbersAreIgnored(string $text): void {
    $this->assertSame([], $this->extractor->extract($text));
  }

  /**
   * Provides text with no phone number in it.
   *
   * @return array<string, array{0: string}>
   *   The text, keyed by description.
   */
  public static function nonNumberProvider(): array {
    return [
      'empty' => [''],
      'prose' => ['We are open every weekday.'],
      'seven digits' => ['Dial 555-0100 locally.'],
      'long digit run' => ['Order 99909555010012345 shipped.'],
      'date' => ['Founded 2019-04-12.'],
      'too few digits' => ['Room 909-55-0100'],
    ];
  }

}
