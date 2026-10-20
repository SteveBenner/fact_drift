<?php

namespace Drupal\Tests\fact_drift_ai\Unit;

use Drupal\fact_drift_ai\Prefilter;
use Drupal\Tests\UnitTestCase;

/**
 * Tests the cue prefilter, the windowing and the offset mapping.
 *
 * @group fact_drift_ai
 * @covers \Drupal\fact_drift_ai\Prefilter
 */
class PrefilterTest extends UnitTestCase {

  /**
   * The prefilter under test.
   */
  protected Prefilter $prefilter;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->prefilter = new Prefilter();
  }

  /**
   * Asserts that every window is a verbatim slice of the text at its offset.
   *
   * @param string $text
   *   The field text.
   * @param array<int, array{text: string, offset: int}> $windows
   *   The windows.
   */
  protected function assertWindowsAreSlices(string $text, array $windows): void {
    foreach ($windows as $window) {
      $this->assertSame($window['text'], mb_substr($text, $window['offset'], mb_strlen($window['text'])));
    }
  }

  /**
   * Tests that a short text without the prefilter is one window.
   */
  public function testPrefilterOffPacksTheWholeText(): void {
    $text = 'Welcome to our clinic. We care about you.';
    $windows = $this->prefilter->windows($text, FALSE, 4000);
    $this->assertSame([['text' => $text, 'offset' => 0]], $windows);
  }

  /**
   * Tests that nothing is a window when there is no text.
   */
  public function testEmptyTextHasNoWindows(): void {
    $this->assertSame([], $this->prefilter->windows('', TRUE, 4000));
    $this->assertSame([], $this->prefilter->windows('', FALSE, 4000));
    $this->assertSame([], $this->prefilter->windows("  \n\n  ", FALSE, 4000));
  }

  /**
   * Tests which words and shapes are cues.
   *
   * @dataProvider cueProvider
   */
  public function testCues(string $text, bool $kept): void {
    $windows = $this->prefilter->windows($text, TRUE, 4000);
    $this->assertCount($kept ? 1 : 0, $windows);
  }

  /**
   * Provides texts with and without a cue.
   *
   * @return array<string, array{string, bool}>
   *   The texts and whether the prefilter keeps them.
   */
  public static function cueProvider(): array {
    return [
      'a digit' => ['Room 12 is on the left', TRUE],
      'a day name' => ['Come and see us on Monday', TRUE],
      'a day abbreviation' => ['See you Thu', TRUE],
      'weekday' => ['A weekday visit is best', TRUE],
      'weekend' => ['Not on the weekend', TRUE],
      'daily' => ['We are here daily', TRUE],
      'noon' => ['Until noon', TRUE],
      'midnight' => ['Until midnight', TRUE],
      'morning' => ['In the morning', TRUE],
      'evening' => ['Some evening slots', TRUE],
      'a.m.' => ['Doors open at nine a.m. sharp', TRUE],
      'pm' => ['Until five pm', TRUE],
      'o clock' => ['At five o\'clock', TRUE],
      'phone' => ['Our phone line', TRUE],
      'call' => ['Please call ahead', TRUE],
      'tel' => ['Tel here', TRUE],
      'fax' => ['By fax only', TRUE],
      'hours' => ['Our hours are flexible', TRUE],
      'open' => ['We are open', TRUE],
      'closed' => ['We are closed', TRUE],
      'upper case' => ['PHONE US', TRUE],
      'no cue' => ['Welcome to our clinic. We care about you.', FALSE],
      'a cue inside a word' => ['Telephony is a calling', FALSE],
    ];
  }

  /**
   * Tests that the full names of the days are cues.
   *
   * @dataProvider dayNameProvider
   */
  public function testFullDayNamesAreCues(string $day): void {
    $this->assertCount(1, $this->prefilter->windows('Come and see us on ' . $day . '.', TRUE, 4000));
  }

  /**
   * Provides the day names the cue pattern misses.
   *
   * @return array<string, array{string}>
   *   The names.
   */
  public static function dayNameProvider(): array {
    $days = ['Tuesday', 'Wednesday', 'Thursday', 'Saturday'];
    return array_combine($days, array_map(static fn (string $day): array => [$day], $days));
  }

  /**
   * Tests that a dropped paragraph ends a window and offsets stay exact.
   */
  public function testDroppedParagraphEndsTheWindow(): void {
    $text = "Call 909-555-0100.\n\nWelcome to our clinic.\n\nOpen Mon-Fri 8-5.";
    $windows = $this->prefilter->windows($text, TRUE, 4000);
    $this->assertSame([
      ['text' => 'Call 909-555-0100.', 'offset' => 0],
      ['text' => 'Open Mon-Fri 8-5.', 'offset' => mb_strpos($text, 'Open')],
    ], $windows);
    $this->assertWindowsAreSlices($text, $windows);
  }

  /**
   * Tests that consecutive kept paragraphs are packed into one window.
   */
  public function testConsecutiveKeptParagraphsArePackedTogether(): void {
    $text = "Call 909-555-0100.\n\nOpen Mon-Fri 8-5.\n\nWelcome to our clinic.";
    $windows = $this->prefilter->windows($text, TRUE, 4000);
    $this->assertCount(1, $windows);
    $this->assertSame(0, $windows[0]['offset']);
    $this->assertSame("Call 909-555-0100.\n\nOpen Mon-Fri 8-5.", $windows[0]['text']);
  }

  /**
   * Tests that a window never exceeds the size and holds contiguous text.
   */
  public function testWindowsRespectTheSize(): void {
    $paragraph = 'Call 909-555-0100 now please.';
    $text = implode("\n\n", array_fill(0, 6, $paragraph));
    $windows = $this->prefilter->windows($text, TRUE, 70);
    $this->assertGreaterThan(1, count($windows));
    foreach ($windows as $window) {
      $this->assertLessThanOrEqual(70, mb_strlen($window['text']));
    }
    $this->assertWindowsAreSlices($text, $windows);
    $this->assertSame(6, substr_count(implode('', array_column($windows, 'text')), '909-555-0100'));
  }

  /**
   * Tests that a long paragraph is cut at sentence ends first.
   */
  public function testLongParagraphIsCutAtSentenceEnds(): void {
    $text = 'We open at 8. We close at 5. Call 909-555-0100. Visit soon.';
    $windows = $this->prefilter->windows($text, FALSE, 31);
    $this->assertSame(['We open at 8. We close at 5.', 'Call 909-555-0100. Visit soon.'], array_column($windows, 'text'));
    $this->assertWindowsAreSlices($text, $windows);
  }

  /**
   * Tests that a sentence longer than the size is cut at the size.
   */
  public function testLongSentenceIsCutAtTheSize(): void {
    $text = str_repeat('a', 25);
    $windows = $this->prefilter->windows($text, FALSE, 10);
    $this->assertSame([
      ['text' => str_repeat('a', 10), 'offset' => 0],
      ['text' => str_repeat('a', 10), 'offset' => 10],
      ['text' => str_repeat('a', 5), 'offset' => 20],
    ], $windows);
  }

  /**
   * Tests that the pieces of a paragraph keep its kept or dropped status.
   */
  public function testPiecesKeepTheStatusOfTheirParagraph(): void {
    $text = 'Welcome to our clinic. We care about you. We love our patients. Come in.';
    $this->assertSame([], $this->prefilter->windows($text, TRUE, 30));
    $with = $text . "\n\nCall 909-555-0100 and ask.";
    $kept = $this->prefilter->windows($with, TRUE, 30);
    $this->assertSame(['Call 909-555-0100 and ask.'], array_column($kept, 'text'));
    $this->assertWindowsAreSlices($with, $kept);
  }

  /**
   * Tests that text with no blank lines is judged one sentence at a time.
   */
  public function testTextWithoutBlankLinesIsJudgedBySentence(): void {
    $text = 'Welcome to our clinic. Call 909-555-0100. We love our patients.';
    $windows = $this->prefilter->windows($text, TRUE, 4000);
    $this->assertSame([['text' => 'Call 909-555-0100.', 'offset' => mb_strpos($text, 'Call')]], $windows);
  }

  /**
   * Tests that offsets are in characters, not bytes.
   */
  public function testOffsetsAreCharacterOffsets(): void {
    $text = 'Café Zoë – schön hier. Ruf an: 909-555-0100. Danke!';
    $windows = $this->prefilter->windows($text, TRUE, 4000);
    $this->assertCount(1, $windows);
    $this->assertSame(mb_strpos($text, 'Ruf'), $windows[0]['offset']);
    $this->assertNotSame(strpos($text, 'Ruf'), $windows[0]['offset']);
    $this->assertWindowsAreSlices($text, $windows);
  }

  /**
   * Tests that leading and trailing whitespace is trimmed from a window.
   */
  public function testWindowsAreTrimmedWithTheOffsetAdjusted(): void {
    $text = "   Call 909-555-0100.  \n\n";
    $windows = $this->prefilter->windows($text, FALSE, 4000);
    $this->assertSame([['text' => 'Call 909-555-0100.', 'offset' => 3]], $windows);
  }

}
