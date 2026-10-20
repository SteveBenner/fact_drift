<?php

namespace Drupal\fact_drift_ai;

use Drupal\fact_drift\Comparator;

/**
 * Parses a model's reply and grounds each claim in the window it came from.
 */
class ResponseValidator {

  /**
   * The longest quote accepted, in characters.
   */
  public const MAX_QUOTE_LENGTH = 300;

  /**
   * Constructs a ResponseValidator.
   *
   * @param \Drupal\fact_drift\Comparator $comparator
   *   The comparator that normalises phone and hours values.
   */
  public function __construct(protected Comparator $comparator) {
  }

  /**
   * Validates a reply against the window and the requested types.
   *
   * A reply that is not a JSON object with a claims array is rejected whole.
   * Otherwise each claim is checked on its own, and a rejected claim does not
   * affect the rest. Offsets and lengths are in characters, like the offsets
   * of the pattern extractors' claims.
   *
   * @param string $reply
   *   The model's reply.
   * @param string $window
   *   The window text the model was shown.
   * @param string[] $requestedTypes
   *   The claim types the model was asked for.
   *
   * @return array{claims: list<array{type: string, quote: string, value: string, offset: int, length: int}>, rejections: array<string, int>}
   *   The grounded claims, with the quote as it appears in the window and the
   *   normalised value, and the number of rejections by reason.
   */
  public function validate(string $reply, string $window, array $requestedTypes): array {
    $result = ['claims' => [], 'rejections' => []];
    $data = json_decode($reply, TRUE);
    if (json_last_error() !== JSON_ERROR_NONE) {
      $result['rejections']['invalid_json'] = 1;
      return $result;
    }
    if (!is_array($data) || !isset($data['claims']) || !is_array($data['claims']) || !array_is_list($data['claims'])) {
      $result['rejections']['invalid_schema'] = 1;
      return $result;
    }
    $cursors = [];
    foreach ($data['claims'] as $item) {
      $claim = $this->validateClaim($item, $window, $requestedTypes, $cursors, $reason);
      if ($claim === NULL) {
        $result['rejections'][$reason] = ($result['rejections'][$reason] ?? 0) + 1;
        continue;
      }
      $result['claims'][] = $claim;
    }
    return $result;
  }

  /**
   * Validates one claim of a reply.
   *
   * @param mixed $item
   *   The decoded claim.
   * @param string $window
   *   The window text.
   * @param string[] $requestedTypes
   *   The claim types the model was asked for.
   * @param array<string, int> $cursors
   *   The byte position after the last match of each quote, so a repeated
   *   quote grounds to the next occurrence. Updated on success.
   * @param string|null $reason
   *   Set to the rejection reason when the claim is rejected.
   *
   * @return array{type: string, quote: string, value: string, offset: int, length: int}|null
   *   The grounded claim, or NULL when it is rejected.
   */
  protected function validateClaim(mixed $item, string $window, array $requestedTypes, array &$cursors, ?string &$reason): ?array {
    $reason = 'invalid_claim';
    if (!is_array($item) || !isset($item['type']) || !is_string($item['type'])) {
      return NULL;
    }
    if (!in_array($item['type'], $requestedTypes, TRUE)) {
      $reason = 'type_not_requested';
      return NULL;
    }
    $quote = $item['quote'] ?? NULL;
    $value = $item['value'] ?? NULL;
    if (!is_string($quote) || !is_string($value) || trim($quote) === '' || trim($value) === '' || mb_strlen($quote) > self::MAX_QUOTE_LENGTH) {
      return NULL;
    }
    $found = $this->ground($quote, $window, $cursors[$quote] ?? 0);
    if ($found === NULL) {
      $reason = 'quote_not_found';
      return NULL;
    }
    $normalised = match ($item['type']) {
      'phone' => $this->comparator->normalisePhone(trim($value)),
      'hours' => $this->comparator->normaliseHours(trim($value)),
      default => NULL,
    };
    if ($normalised === NULL) {
      $reason = 'value_unreadable';
      return NULL;
    }
    $cursors[$quote] = $found['end'];
    return [
      'type' => $item['type'],
      'quote' => $found['text'],
      'value' => $normalised,
      'offset' => mb_strlen(substr($window, 0, $found['start'])),
      'length' => mb_strlen($found['text']),
    ];
  }

  /**
   * Finds a quote in the window, comparing runs of whitespace as one space.
   *
   * @param string $quote
   *   The quote.
   * @param string $window
   *   The window text.
   * @param int $from
   *   The byte position to search from.
   *
   * @return array{text: string, start: int, end: int}|null
   *   The matched text of the window with its byte start and end, or NULL when
   *   the quote does not occur from that position.
   */
  protected function ground(string $quote, string $window, int $from): ?array {
    $words = preg_split('/\s+/u', trim($quote), -1, PREG_SPLIT_NO_EMPTY);
    if (!$words) {
      return NULL;
    }
    $pattern = '/' . implode('\s+', array_map(static fn (string $word): string => preg_quote($word, '/'), $words)) . '/u';
    if (!preg_match($pattern, $window, $matches, PREG_OFFSET_CAPTURE, $from)) {
      return NULL;
    }
    return [
      'text' => $matches[0][0],
      'start' => $matches[0][1],
      'end' => $matches[0][1] + strlen($matches[0][0]),
    ];
  }

}
