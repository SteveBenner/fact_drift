<?php

namespace Drupal\fact_drift_ai;

/**
 * Picks the passages of a field worth asking about and cuts them into windows.
 */
class Prefilter {

  /**
   * The words that make a passage worth asking about, besides digits.
   */
  public const CUE_PATTERN = '/\d|\b(?:monday|tuesday|wednesday|thursday|friday|saturday|sunday|mon|tue|tues|wed|weds|thu|thur|thurs|fri|sat|sun)s?\b|\b(?:weekday|weekend|daily|noon|midnight|morning|evening|am|pm|phone|call|tel|fax|hours|open|closed)s?\b|\b[ap]\.m\.|o\'clock/iu';

  /**
   * Splits the field text into windows, one per call.
   *
   * With the prefilter on, the text is cut into paragraphs (blank lines; or
   * sentences when the text has none), and only paragraphs with a digit, a
   * day or time word or a cue word are kept. A window is always one
   * contiguous piece of the text, so a dropped paragraph ends the window
   * before it. Kept paragraphs are packed in order into windows of at most
   * the given size; a longer paragraph is cut at sentence ends, then at the
   * size. With the prefilter off, the whole text is packed the same way.
   *
   * @param string $text
   *   The field's plain text.
   * @param bool $prefilter
   *   Whether to keep only the passages with a cue.
   * @param int $maxChars
   *   The most characters in one window.
   *
   * @return list<array{text: string, offset: int}>
   *   The windows in order, each with the character offset of its first
   *   character in the field text.
   */
  public function windows(string $text, bool $prefilter, int $maxChars): array {
    $maxChars = max(1, $maxChars);
    $windows = [];
    $current = NULL;
    foreach ($this->units($text, $maxChars) as $unit) {
      $keep = !$prefilter || $unit['keep'];
      if ($current !== NULL && (!$keep || $unit['offset'] !== $current['end'] || $current['length'] + $unit['length'] > $maxChars)) {
        $windows[] = $current;
        $current = NULL;
      }
      if (!$keep) {
        continue;
      }
      if ($current === NULL) {
        $current = ['text' => '', 'offset' => $unit['offset'], 'end' => $unit['offset'], 'length' => 0];
      }
      $current['text'] .= $unit['text'];
      $current['end'] = $unit['offset'] + $unit['length'];
      $current['length'] += $unit['length'];
    }
    if ($current !== NULL) {
      $windows[] = $current;
    }
    $result = [];
    foreach ($windows as $window) {
      $trimmed = ltrim($window['text']);
      if (trim($trimmed) === '') {
        continue;
      }
      $result[] = [
        'text' => rtrim($trimmed),
        'offset' => $window['offset'] + mb_strlen($window['text']) - mb_strlen($trimmed),
      ];
    }
    return $result;
  }

  /**
   * Cuts the text into contiguous units, none longer than the window size.
   *
   * @param string $text
   *   The field's plain text.
   * @param int $maxChars
   *   The most characters in one unit.
   *
   * @return list<array{text: string, offset: int, length: int, keep: bool}>
   *   The units in order, which together make up the whole text, each with
   *   its character offset and whether its paragraph has a cue.
   */
  protected function units(string $text, int $maxChars): array {
    $paragraphs = $this->split($text, '/(?<=\n\n)(?!\n)/u');
    if (count($paragraphs) === 1) {
      $paragraphs = $this->split($text, '/(?<=[.!?])(?=\s+\S)/u');
    }
    $units = [];
    foreach ($paragraphs as $paragraph) {
      $keep = (bool) preg_match(self::CUE_PATTERN, $paragraph['text']);
      $pieces = $paragraph['length'] > $maxChars ? $this->cut($paragraph, $maxChars) : [$paragraph];
      foreach ($pieces as $piece) {
        $units[] = $piece + ['keep' => $keep];
      }
    }
    return $units;
  }

  /**
   * Cuts a paragraph at sentence ends, then at the window size.
   *
   * @param array{text: string, offset: int, length: int} $paragraph
   *   The paragraph.
   * @param int $maxChars
   *   The most characters in one piece.
   *
   * @return list<array{text: string, offset: int, length: int}>
   *   The pieces, none longer than the size, which together make up the
   *   paragraph.
   */
  protected function cut(array $paragraph, int $maxChars): array {
    $pieces = [];
    foreach ($this->split($paragraph['text'], '/(?<=[.!?])(?=\s+\S)/u') as $sentence) {
      $offset = $paragraph['offset'] + $sentence['offset'];
      if ($sentence['length'] <= $maxChars) {
        $pieces[] = ['text' => $sentence['text'], 'offset' => $offset, 'length' => $sentence['length']];
        continue;
      }
      foreach (mb_str_split($sentence['text'], $maxChars) as $index => $chunk) {
        $pieces[] = ['text' => $chunk, 'offset' => $offset + $index * $maxChars, 'length' => mb_strlen($chunk)];
      }
    }
    return $pieces;
  }

  /**
   * Splits text at zero-width matches of a pattern.
   *
   * @param string $text
   *   The text.
   * @param string $pattern
   *   A pattern whose matches have no width.
   *
   * @return list<array{text: string, offset: int, length: int}>
   *   The pieces, which together make up the text, with character offsets.
   */
  protected function split(string $text, string $pattern): array {
    $parts = preg_split($pattern, $text, -1, PREG_SPLIT_NO_EMPTY | PREG_SPLIT_OFFSET_CAPTURE);
    if ($parts === FALSE || $parts === []) {
      return $text === '' ? [] : [['text' => $text, 'offset' => 0, 'length' => mb_strlen($text)]];
    }
    $pieces = [];
    $byte = 0;
    $char = 0;
    foreach ($parts as [$piece, $start]) {
      $char += mb_strlen(substr($text, $byte, $start - $byte));
      $byte = $start;
      $pieces[] = ['text' => $piece, 'offset' => $char, 'length' => mb_strlen($piece)];
    }
    return $pieces;
  }

}
