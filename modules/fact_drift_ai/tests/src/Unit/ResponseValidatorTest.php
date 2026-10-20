<?php

namespace Drupal\Tests\fact_drift_ai\Unit;

use Drupal\fact_drift\Comparator;
use Drupal\fact_drift_ai\ResponseValidator;
use Drupal\Tests\UnitTestCase;

/**
 * Tests the validation and grounding of a model's reply.
 *
 * @group fact_drift_ai
 * @covers \Drupal\fact_drift_ai\ResponseValidator
 */
class ResponseValidatorTest extends UnitTestCase {

  /**
   * The validator under test.
   */
  protected ResponseValidator $validator;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->validator = new ResponseValidator(new Comparator());
  }

  /**
   * Encodes a reply of claims.
   *
   * @param array<int, mixed> $claims
   *   The claims.
   *
   * @return string
   *   The JSON reply.
   */
  protected function reply(array $claims): string {
    return json_encode(['claims' => $claims], JSON_THROW_ON_ERROR);
  }

  /**
   * Tests that a reply that is not JSON is rejected whole.
   */
  public function testBadJsonIsRejectedWhole(): void {
    $result = $this->validator->validate('Sure! Call 909-555-0100.', 'Call 909-555-0100.', ['phone']);
    $this->assertSame([], $result['claims']);
    $this->assertSame(['invalid_json' => 1], $result['rejections']);
  }

  /**
   * Tests that a reply of the wrong shape is rejected whole.
   *
   * @dataProvider badSchemaProvider
   */
  public function testBadSchemaIsRejectedWhole(string $reply): void {
    $result = $this->validator->validate($reply, 'Call 909-555-0100.', ['phone']);
    $this->assertSame([], $result['claims']);
    $this->assertSame(['invalid_schema' => 1], $result['rejections']);
  }

  /**
   * Provides replies that parse but are not {"claims": [...]}.
   *
   * @return array<string, array{string}>
   *   The replies.
   */
  public static function badSchemaProvider(): array {
    return [
      'a string' => ['"hello"'],
      'a number' => ['42'],
      'null' => ['null'],
      'no claims key' => ['{"phone": "9095550100"}'],
      'claims is a string' => ['{"claims": "none"}'],
      'claims is an object' => ['{"claims": {"a": {"type": "phone"}}}'],
      'a bare list' => ['[{"type": "phone"}]'],
    ];
  }

  /**
   * Tests that an empty claims array is a valid reply with nothing in it.
   */
  public function testEmptyClaimsIsValid(): void {
    $result = $this->validator->validate('{"claims": []}', 'Nothing here.', ['phone']);
    $this->assertSame(['claims' => [], 'rejections' => []], $result);
  }

  /**
   * Tests that a grounded claim carries the window text, value and offsets.
   */
  public function testGroundedClaimCarriesTheQuoteAsWrittenAndTheNormalisedValue(): void {
    $window = 'Call us at (909) 555-0100 today.';
    $result = $this->validator->validate($this->reply([
      ['type' => 'phone', 'quote' => '(909) 555-0100', 'value' => '909 555 0100'],
    ]), $window, ['phone', 'hours']);
    $this->assertSame([], $result['rejections']);
    $this->assertSame([
      ['type' => 'phone', 'quote' => '(909) 555-0100', 'value' => '9095550100', 'offset' => 11, 'length' => 14],
    ], $result['claims']);
  }

  /**
   * Tests that a quote missing from the window is rejected.
   */
  public function testQuoteNotOnThePageIsRejected(): void {
    $result = $this->validator->validate($this->reply([
      ['type' => 'phone', 'quote' => '(909) 555-0199', 'value' => '9095550199'],
      ['type' => 'phone', 'quote' => '909-555-0100', 'value' => '9095550100'],
    ]), 'Call 909-555-0100.', ['phone']);
    $this->assertSame(['quote_not_found' => 1], $result['rejections']);
    $this->assertCount(1, $result['claims']);
    $this->assertSame('9095550100', $result['claims'][0]['value']);
  }

  /**
   * Tests that nothing but whitespace runs is loosened when matching.
   */
  public function testQuoteMatchIsWhitespaceTolerantAndNothingElse(): void {
    $window = "Open Monday  to\nFriday 8 am - 5 pm.";
    $result = $this->validator->validate($this->reply([
      ['type' => 'hours', 'quote' => 'Monday to Friday 8 am - 5 pm', 'value' => 'Mon-Fri 08:00-17:00'],
    ]), $window, ['hours']);
    $this->assertSame([], $result['rejections']);
    $this->assertSame("Monday  to\nFriday 8 am - 5 pm", $result['claims'][0]['quote']);
    $this->assertSame(5, $result['claims'][0]['offset']);
    $this->assertSame(mb_strlen("Monday  to\nFriday 8 am - 5 pm"), $result['claims'][0]['length']);

    $loosened = $this->validator->validate($this->reply([
      ['type' => 'hours', 'quote' => 'monday to friday 8 am - 5 pm', 'value' => 'Mon-Fri 08:00-17:00'],
      ['type' => 'hours', 'quote' => 'Monday to Friday 8am - 5pm', 'value' => 'Mon-Fri 08:00-17:00'],
      ['type' => 'hours', 'quote' => 'Monday to Friday 8 am - 5 pm, daily', 'value' => 'Mon-Fri 08:00-17:00'],
    ]), $window, ['hours']);
    $this->assertSame([], $loosened['claims']);
    $this->assertSame(['quote_not_found' => 3], $loosened['rejections']);
  }

  /**
   * Tests that a value that does not normalise is rejected.
   */
  public function testUnreadableValueIsRejected(): void {
    $result = $this->validator->validate($this->reply([
      ['type' => 'phone', 'quote' => '909-555-0100', 'value' => 'nine oh nine'],
      ['type' => 'phone', 'quote' => '555-0100', 'value' => '5550100'],
    ]), 'Call 909-555-0100 or 555-0100.', ['phone']);
    $this->assertSame([], $result['claims']);
    $this->assertSame(['value_unreadable' => 2], $result['rejections']);

    $hours = $this->validator->validate($this->reply([
      ['type' => 'hours', 'quote' => 'whenever', 'value' => 'whenever we feel like it'],
    ]), 'Open whenever.', ['hours']);
    $this->assertSame(['value_unreadable' => 1], $hours['rejections']);
  }

  /**
   * Tests that a type that was not requested is rejected.
   */
  public function testUnrequestedTypeIsRejected(): void {
    $result = $this->validator->validate($this->reply([
      ['type' => 'hours', 'quote' => 'Mon-Fri 8-5', 'value' => 'Mon-Fri 08:00-17:00'],
      ['type' => 'email', 'quote' => 'a@b.c', 'value' => 'a@b.c'],
    ]), 'Open Mon-Fri 8-5. Mail a@b.c', ['phone']);
    $this->assertSame([], $result['claims']);
    $this->assertSame(['type_not_requested' => 2], $result['rejections']);
  }

  /**
   * Tests the shapes of a claim that are invalid.
   *
   * @dataProvider invalidClaimProvider
   */
  public function testInvalidClaimsAreRejected(mixed $item): void {
    $result = $this->validator->validate($this->reply([$item]), 'Call 909-555-0100.', ['phone']);
    $this->assertSame([], $result['claims']);
    $this->assertSame(['invalid_claim' => 1], $result['rejections']);
  }

  /**
   * Provides invalid claims.
   *
   * @return array<string, array{mixed}>
   *   The claims.
   */
  public static function invalidClaimProvider(): array {
    return [
      'not an object' => ['909-555-0100'],
      'no type' => [['quote' => '909-555-0100', 'value' => '9095550100']],
      'type not a string' => [['type' => 7, 'quote' => '909-555-0100', 'value' => '9095550100']],
      'no quote' => [['type' => 'phone', 'value' => '9095550100']],
      'no value' => [['type' => 'phone', 'quote' => '909-555-0100']],
      'empty quote' => [['type' => 'phone', 'quote' => '', 'value' => '9095550100']],
      'blank quote' => [['type' => 'phone', 'quote' => '   ', 'value' => '9095550100']],
      'empty value' => [['type' => 'phone', 'quote' => '909-555-0100', 'value' => '']],
      'quote not a string' => [['type' => 'phone', 'quote' => 9095550100, 'value' => '9095550100']],
      'value not a string' => [['type' => 'phone', 'quote' => '909-555-0100', 'value' => 9095550100]],
      'quote of 301 characters' => [['type' => 'phone', 'quote' => str_repeat('a', 301), 'value' => '9095550100']],
    ];
  }

  /**
   * Tests that a quote of exactly 300 characters is still accepted.
   */
  public function testQuoteOfTheLimitIsAccepted(): void {
    $quote = '909-555-0100 ' . str_repeat('x', 287);
    $this->assertSame(300, mb_strlen($quote));
    $result = $this->validator->validate($this->reply([
      ['type' => 'phone', 'quote' => $quote, 'value' => '909-555-0100'],
    ]), 'See ' . $quote . ' now', ['phone']);
    $this->assertSame([], $result['rejections']);
    $this->assertCount(1, $result['claims']);
  }

  /**
   * Tests that a rejected claim does not affect the others.
   */
  public function testRejectedClaimsDoNotAffectTheRest(): void {
    $result = $this->validator->validate($this->reply([
      ['type' => 'phone', 'quote' => 'nowhere', 'value' => '9095550100'],
      ['type' => 'phone', 'quote' => '909-555-0100', 'value' => '909-555-0100'],
      ['type' => 'hours', 'quote' => 'Mon-Fri 8-5', 'value' => 'Mon-Fri 08:00-17:00'],
    ]), 'Call 909-555-0100. Open Mon-Fri 8-5.', ['phone', 'hours']);
    $this->assertSame(['quote_not_found' => 1], $result['rejections']);
    $this->assertSame(['phone', 'hours'], array_column($result['claims'], 'type'));
    $this->assertSame(['9095550100', 'Mon-Fri 08:00-17:00'], array_column($result['claims'], 'value'));
  }

  /**
   * Tests that a repeated quote grounds to successive occurrences.
   */
  public function testRepeatedQuoteGroundsToSuccessiveOccurrences(): void {
    $window = 'Call 909-555-0100 or, after hours, 909-555-0100.';
    $claim = ['type' => 'phone', 'quote' => '909-555-0100', 'value' => '9095550100'];
    $result = $this->validator->validate($this->reply([$claim, $claim, $claim]), $window, ['phone']);
    $this->assertSame([5, 35], array_column($result['claims'], 'offset'));
    $this->assertSame(['quote_not_found' => 1], $result['rejections']);
  }

  /**
   * Tests that offsets and lengths count characters, not bytes.
   */
  public function testOffsetsAreCharacterOffsets(): void {
    $window = 'Café Zoë – ruf an: 909-555-0100 – danke';
    $result = $this->validator->validate($this->reply([
      ['type' => 'phone', 'quote' => '909-555-0100', 'value' => '9095550100'],
    ]), $window, ['phone']);
    $claim = $result['claims'][0];
    $this->assertSame(mb_strpos($window, '909'), $claim['offset']);
    $this->assertNotSame(strpos($window, '909'), $claim['offset']);
    $this->assertSame(12, $claim['length']);
    $this->assertSame('909-555-0100', mb_substr($window, $claim['offset'], $claim['length']));
  }

  /**
   * Tests that a quote with regex characters matches literally.
   */
  public function testQuoteIsMatchedLiterally(): void {
    $window = 'Call (909) 555x0100 or (909) 555.0100.';
    $result = $this->validator->validate($this->reply([
      ['type' => 'phone', 'quote' => '(909) 555.0100', 'value' => '9095550100'],
    ]), $window, ['phone']);
    $this->assertSame(mb_strpos($window, '(909) 555.0100'), $result['claims'][0]['offset']);
  }

}
