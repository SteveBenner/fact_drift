<?php

namespace Drupal\Tests\fact_drift\Unit;

use Drupal\fact_drift\Comparator;
use Drupal\fact_drift\Plugin\ClaimExtractor\HoursExtractor;
use Drupal\Tests\UnitTestCase;

/**
 * Tests the hours claim extractor and the normaliser it relies on.
 *
 * @group fact_drift
 * @covers \Drupal\fact_drift\Plugin\ClaimExtractor\HoursExtractor
 * @covers \Drupal\fact_drift\Comparator::normaliseHours
 */
class HoursExtractorTest extends UnitTestCase {

  /**
   * The extractor under test.
   */
  protected HoursExtractor $extractor;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->extractor = new HoursExtractor([], 'hours', [], new Comparator());
  }

  /**
   * Tests that a run of segments becomes one claim.
   */
  public function testMultiSegmentRunIsOneClaim(): void {
    $text = 'We are open Mon-Fri 8am-5pm; Sat 9am-12pm for your visit.';
    $claims = $this->extractor->extract($text);
    $this->assertCount(1, $claims);
    $this->assertSame('hours', $claims[0]->claimType);
    $this->assertSame('Mon-Fri 08:00-17:00; Sat 09:00-12:00', $claims[0]->value);
    $this->assertSame('Mon-Fri 8am-5pm; Sat 9am-12pm', $claims[0]->raw);
    $this->assertSame(12, $claims[0]->offset);
    $this->assertSame('Mon-Fri 8am-5pm; Sat 9am-12pm', mb_substr($text, $claims[0]->offset, $claims[0]->length));
  }

  /**
   * Tests that segments joined by and are one claim.
   */
  public function testAndJoinsSegments(): void {
    $claims = $this->extractor->extract('Hours: Mon-Fri 8am-5pm and Sat 9am-12pm.');
    $this->assertCount(1, $claims);
    $this->assertSame('Mon-Fri 08:00-17:00; Sat 09:00-12:00', $claims[0]->value);
  }

  /**
   * Tests that segments separated by prose are separate claims.
   */
  public function testSegmentsSeparatedByProseAreSeparateClaims(): void {
    $claims = $this->extractor->extract('Mon-Fri 8am-5pm at the clinic, but the lab is open Sat 9am-12pm only.');
    $this->assertCount(2, $claims);
    $this->assertSame('Mon-Fri 08:00-17:00', $claims[0]->value);
    $this->assertSame('Sat 09:00-12:00', $claims[1]->value);
    $this->assertLessThan($claims[1]->offset, $claims[0]->offset);
  }

  /**
   * Tests that the offset counts characters, not bytes.
   */
  public function testOffsetCountsCharacters(): void {
    $text = 'Café — open Mon-Fri 8am-5pm';
    $claims = $this->extractor->extract($text);
    $this->assertCount(1, $claims);
    $this->assertSame('Mon-Fri 8am-5pm', mb_substr($text, $claims[0]->offset, $claims[0]->length));
  }

  /**
   * Tests that text which is not readable hours yields no claim.
   *
   * @param string $text
   *   The text.
   *
   * @dataProvider unreadableProvider
   */
  public function testUnreadableTextYieldsNoClaim(string $text): void {
    $this->assertSame([], $this->extractor->extract($text));
  }

  /**
   * Provides text that holds no readable hours.
   *
   * @return array<string, array{0: string}>
   *   The text, keyed by description.
   */
  public static function unreadableProvider(): array {
    return [
      'empty' => [''],
      'prose' => ['Closed Sundays and holidays.'],
      'no days' => ['Open 8am-5pm.'],
      'no meridiem' => ['Mon-Fri 9-5'],
      'ambiguous meridiem' => ['Mon-Fri 9am-5'],
      'end before start' => ['Mon 5pm-9am'],
    ];
  }

  /**
   * Tests that the extractor normalises the claim through the comparator.
   */
  public function testClaimValueIsCanonical(): void {
    $claims = $this->extractor->extract('Monday to Friday 9-5pm');
    $this->assertCount(1, $claims);
    $this->assertSame('Mon-Fri 09:00-17:00', $claims[0]->value);
  }

}
