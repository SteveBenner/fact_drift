<?php

namespace Drupal\fact_drift;

use Drupal\fact_drift\Value\Claim;
use Drupal\fact_drift\Value\Fact;

/**
 * Normalises phone and hours values, attributes claims to facts, and compares.
 */
class Comparator {

  /**
   * The maximum length of a snippet, in characters.
   */
  public const SNIPPET_LENGTH = 300;

  /**
   * How many characters after a fact's name a claim may start to belong to it.
   */
  public const NAME_REACH = 200;

  /**
   * Regular expression body matching one day name, case-insensitively.
   */
  public const DAY_PATTERN = '(?:monday|mon|tuesday|tues|tue|wednesday|weds|wed|thursday|thurs|thur|thu|friday|fri|saturday|sat|sunday|sun)(?![a-z])';

  /**
   * Regular expression body matching one hours segment, case-insensitively.
   *
   * Named groups: d1, d2, h1, m1, p1, h2, m2 and p2. It expects the flags i
   * and u.
   */
  public const HOURS_SEGMENT_PATTERN = '(?<![\p{L}\p{N}])(?<d1>' . self::DAY_PATTERN . ')(?:\s*(?:-|–|—|to|through|thru)\s*(?<d2>' . self::DAY_PATTERN . '))?\s*,?\s*:?\s*(?<h1>\d{1,2})(?::(?<m1>\d{2}))?(?:\s*(?<p1>[ap])(?:\.m\.?|m)(?![a-z]))?\s*(?:-|–|—|to|until)\s*(?<h2>\d{1,2})(?::(?<m2>\d{2}))?(?:\s*(?<p2>[ap])(?:\.m\.?|m)(?![a-z]))?(?!\d)';

  /**
   * Regular expression body matching the gap allowed between two segments.
   */
  public const HOURS_SEPARATOR_PATTERN = '\s*(?:[;,]\s*)?(?:and\b\s*)?';

  /**
   * The canonical three-letter name of each day, keyed by every accepted form.
   */
  private const DAY_NAMES = [
    'mon' => 'Mon',
    'monday' => 'Mon',
    'tue' => 'Tue',
    'tues' => 'Tue',
    'tuesday' => 'Tue',
    'wed' => 'Wed',
    'weds' => 'Wed',
    'wednesday' => 'Wed',
    'thu' => 'Thu',
    'thur' => 'Thu',
    'thurs' => 'Thu',
    'thursday' => 'Thu',
    'fri' => 'Fri',
    'friday' => 'Fri',
    'sat' => 'Sat',
    'saturday' => 'Sat',
    'sun' => 'Sun',
    'sunday' => 'Sun',
  ];

  /**
   * Normalises a phone number.
   *
   * @param string $raw
   *   The phone number as written.
   *
   * @return string|null
   *   Ten digits, followed by x and the extension digits when an extension is
   *   present; NULL unless exactly ten digits remain before any extension.
   */
  public function normalisePhone(string $raw): ?string {
    $extension = '';
    if (preg_match('/\s*(?:extension|ext\.?|x)\s*(\d+)\s*$/i', $raw, $matches, PREG_OFFSET_CAPTURE)) {
      $extension = $matches[1][0];
      $raw = substr($raw, 0, $matches[0][1]);
    }
    $digits = preg_replace('/\D/', '', $raw);
    if (strlen($digits) === 11 && $digits[0] === '1') {
      $digits = substr($digits, 1);
    }
    if (strlen($digits) !== 10) {
      return NULL;
    }
    return $extension === '' ? $digits : $digits . 'x' . $extension;
  }

  /**
   * Normalises opening hours.
   *
   * @param string $raw
   *   The hours as written, for example "Mon-Fri 8am-5pm; Sat 9am-12pm".
   *
   * @return string|null
   *   The canonical form, for example "Mon-Fri 08:00-17:00; Sat 09:00-12:00";
   *   NULL when any part of the text cannot be read.
   */
  public function normaliseHours(string $raw): ?string {
    $pattern = '/\G\s*' . self::HOURS_SEGMENT_PATTERN . '/iu';
    $separator = '/\G' . self::HOURS_SEPARATOR_PATTERN . '/iu';
    $position = 0;
    $length = strlen($raw);
    $segments = [];
    do {
      if (!preg_match($pattern, $raw, $matches, 0, $position)) {
        return NULL;
      }
      $segment = $this->canonicalSegment($matches);
      if ($segment === NULL) {
        return NULL;
      }
      $segments[] = $segment;
      $position += strlen($matches[0]);
      if (preg_match($separator, $raw, $gap, 0, $position)) {
        $position += strlen($gap[0]);
      }
    } while ($position < $length);
    return implode('; ', $segments);
  }

  /**
   * Attributes claims to facts.
   *
   * A fact's name or alias in the text claims the first claim of the fact's
   * type within 200 characters after it. Any claim whose value is a former
   * value of a fact belongs to that fact wherever it appears. A claim that
   * matches no fact is dropped.
   *
   * @param \Drupal\fact_drift\Value\Claim[] $claims
   *   The claims found in the text.
   * @param \Drupal\fact_drift\Value\Fact[] $facts
   *   The facts to attribute to.
   * @param string $text
   *   The plain text the claims were found in.
   *
   * @return array<int, array{0: \Drupal\fact_drift\Value\Fact, 1: \Drupal\fact_drift\Value\Claim}>
   *   A list of pairs, each a Fact followed by the Claim attributed to it.
   */
  public function attribute(array $claims, array $facts, string $text): array {
    $pairs = [];
    foreach ($facts as $fact) {
      $typed = array_values(array_filter($claims, static fn (Claim $claim): bool => $claim->claimType === $fact->claimType));
      usort($typed, static fn (Claim $a, Claim $b): int => $a->offset <=> $b->offset);
      $chosen = [];
      foreach ($this->nameOccurrences($fact, $text) as [$position, $length]) {
        $end = $position + $length;
        foreach ($typed as $claim) {
          if ($claim->offset >= $end && $claim->offset <= $end + self::NAME_REACH) {
            $chosen[$claim->offset] = $claim;
            break;
          }
        }
      }
      foreach ($typed as $claim) {
        if (in_array($claim->value, $fact->formerValues, TRUE)) {
          $chosen[$claim->offset] = $claim;
        }
      }
      ksort($chosen);
      foreach ($chosen as $claim) {
        $pairs[] = [$fact, $claim];
      }
    }
    return $pairs;
  }

  /**
   * Tells whether a claim agrees with a fact.
   *
   * @param \Drupal\fact_drift\Value\Fact $fact
   *   The fact.
   * @param \Drupal\fact_drift\Value\Claim $claim
   *   The claim attributed to the fact.
   *
   * @return bool
   *   TRUE when the claim equals the fact's value and is not a former value.
   */
  public function matches(Fact $fact, Claim $claim): bool {
    return $claim->value === $fact->value && !in_array($claim->value, $fact->formerValues, TRUE);
  }

  /**
   * Cuts a snippet of plain text around a claim.
   *
   * @param string $text
   *   The plain text.
   * @param int $offset
   *   The character offset of the claim.
   * @param int $length
   *   The length of the claim in characters.
   *
   * @return string
   *   Up to 300 characters centred on the claim, cut at word boundaries where
   *   possible, with an ellipsis at each cut end.
   */
  public function snippet(string $text, int $offset, int $length): string {
    $total = mb_strlen($text);
    if ($total <= self::SNIPPET_LENGTH) {
      return $text;
    }
    $window = self::SNIPPET_LENGTH - 2;
    $start = max(0, $offset + intdiv($length, 2) - intdiv($window, 2));
    $end = $start + $window;
    if ($end > $total) {
      $end = $total;
      $start = max(0, $end - $window);
    }
    if ($start > 0) {
      $space = mb_strpos($text, ' ', $start);
      if ($space !== FALSE && $space < $offset) {
        $start = $space + 1;
      }
    }
    if ($end < $total) {
      $space = mb_strrpos(mb_substr($text, 0, $end), ' ');
      if ($space !== FALSE && $space >= $offset + $length) {
        $end = $space;
      }
    }
    return ($start > 0 ? '…' : '') . mb_substr($text, $start, $end - $start) . ($end < $total ? '…' : '');
  }

  /**
   * Finds the word-bounded, case-insensitive occurrences of a fact's names.
   *
   * @param \Drupal\fact_drift\Value\Fact $fact
   *   The fact.
   * @param string $text
   *   The plain text.
   *
   * @return array<int, array{0: int, 1: int}>
   *   A list of [character offset, character length] pairs.
   */
  private function nameOccurrences(Fact $fact, string $text): array {
    $occurrences = [];
    $names = array_unique(array_filter([$fact->name, ...$fact->aliases], static fn (string $name): bool => trim($name) !== ''));
    foreach ($names as $name) {
      $pattern = '/(?<![\p{L}\p{N}_])' . preg_quote(trim($name), '/') . '(?![\p{L}\p{N}_])/iu';
      if (!preg_match_all($pattern, $text, $matches, PREG_OFFSET_CAPTURE)) {
        continue;
      }
      foreach ($matches[0] as [$found, $byteOffset]) {
        $occurrences[] = [mb_strlen(substr($text, 0, $byteOffset)), mb_strlen($found)];
      }
    }
    return $occurrences;
  }

  /**
   * Builds the canonical form of one matched hours segment.
   *
   * @param array<int|string, string> $matches
   *   The named groups of a match of the hours segment pattern.
   *
   * @return string|null
   *   The canonical segment, or NULL when it cannot be read.
   */
  private function canonicalSegment(array $matches): ?string {
    $range = $this->resolveTimes(
      $matches['h1'],
      $matches['m1'] ?? '',
      strtolower($matches['p1'] ?? ''),
      $matches['h2'],
      $matches['m2'] ?? '',
      strtolower($matches['p2'] ?? ''),
    );
    if ($range === NULL) {
      return NULL;
    }
    $first = self::DAY_NAMES[strtolower($matches['d1'])];
    $days = $first;
    if (($matches['d2'] ?? '') !== '') {
      $last = self::DAY_NAMES[strtolower($matches['d2'])];
      if ($last !== $first) {
        $days = $first . '-' . $last;
      }
    }
    return $days . ' ' . $this->formatMinutes($range[0]) . '-' . $this->formatMinutes($range[1]);
  }

  /**
   * Resolves the two times of a range to minutes since midnight.
   *
   * @param string $hour1
   *   The start hour as written.
   * @param string $minute1
   *   The start minutes as written, or an empty string.
   * @param string $meridiem1
   *   The start meridiem letter, a or p, or an empty string.
   * @param string $hour2
   *   The end hour as written.
   * @param string $minute2
   *   The end minutes as written, or an empty string.
   * @param string $meridiem2
   *   The end meridiem letter, a or p, or an empty string.
   *
   * @return int[]|null
   *   The start and end in minutes, or NULL when the range cannot be read or
   *   does not start before it ends.
   */
  private function resolveTimes(string $hour1, string $minute1, string $meridiem1, string $hour2, string $minute2, string $meridiem2): ?array {
    if ($meridiem1 !== '' && $meridiem2 !== '') {
      $start = $this->twelveHour($hour1, $minute1, $meridiem1);
      $end = $this->twelveHour($hour2, $minute2, $meridiem2);
    }
    elseif ($meridiem2 !== '') {
      $end = $this->twelveHour($hour2, $minute2, $meridiem2);
      $start = $this->twelveHour($hour1, $minute1, $meridiem2);
      if ($start !== NULL && $end !== NULL && $start > $end) {
        $start = $this->twelveHour($hour1, $minute1, 'a');
      }
    }
    elseif ($meridiem1 === '' && $minute1 !== '' && $minute2 !== '') {
      $start = $this->twentyFourHour($hour1, $minute1);
      $end = $this->twentyFourHour($hour2, $minute2);
    }
    else {
      return NULL;
    }
    if ($start === NULL || $end === NULL || $start >= $end) {
      return NULL;
    }
    return [$start, $end];
  }

  /**
   * Converts a 12-hour time to minutes since midnight.
   *
   * @param string $hour
   *   The hour, 1 to 12.
   * @param string $minute
   *   The minutes, or an empty string for none.
   * @param string $meridiem
   *   The meridiem letter, a or p.
   *
   * @return int|null
   *   The minutes since midnight, or NULL when out of range.
   */
  private function twelveHour(string $hour, string $minute, string $meridiem): ?int {
    $hours = (int) $hour;
    $minutes = $minute === '' ? 0 : (int) $minute;
    if ($hours < 1 || $hours > 12 || $minutes > 59) {
      return NULL;
    }
    $hours %= 12;
    if ($meridiem === 'p') {
      $hours += 12;
    }
    return $hours * 60 + $minutes;
  }

  /**
   * Converts a 24-hour time to minutes since midnight.
   *
   * @param string $hour
   *   The hour, 0 to 23.
   * @param string $minute
   *   The minutes.
   *
   * @return int|null
   *   The minutes since midnight, or NULL when out of range.
   */
  private function twentyFourHour(string $hour, string $minute): ?int {
    $hours = (int) $hour;
    $minutes = (int) $minute;
    if ($hours > 23 || $minutes > 59) {
      return NULL;
    }
    return $hours * 60 + $minutes;
  }

  /**
   * Formats minutes since midnight as HH:MM.
   *
   * @param int $minutes
   *   The minutes since midnight.
   *
   * @return string
   *   The time as HH:MM.
   */
  private function formatMinutes(int $minutes): string {
    return sprintf('%02d:%02d', intdiv($minutes, 60), $minutes % 60);
  }

}
