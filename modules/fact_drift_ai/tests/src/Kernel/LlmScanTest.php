<?php

namespace Drupal\Tests\fact_drift_ai\Kernel;

use Drupal\node\NodeInterface;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests the AI extractor inside the scan: findings, deferral and outages.
 *
 * @group fact_drift_ai
 * @covers \Drupal\fact_drift_ai\Plugin\ClaimExtractor\LlmExtractor
 * @covers \Drupal\fact_drift_ai\CallGuard
 */
#[RunTestsInSeparateProcesses]
class LlmScanTest extends AiKernelTestBase {

  /**
   * A body with a phone number the patterns read and hours they cannot.
   */
  protected const MIXED = 'Riverside Family Clinic: 909-555-0123. Riverside Family Clinic is open from eight in the morning until five in the afternoon, Monday through Friday.';

  /**
   * Scans a node.
   *
   * @param \Drupal\node\NodeInterface $node
   *   The node.
   *
   * @return array<string, mixed>
   *   The scan result.
   */
  protected function scan(NodeInterface $node): array {
    return $this->container->get('fact_drift.scanner')->scanNode($node);
  }

  /**
   * Tests that a claim only the model finds becomes a finding of the model.
   */
  public function testAiFindingIsRecordedWithItsExtractor(): void {
    $this->script([['reply' => self::HOURS_REPLY]]);
    $result = $this->scan($this->createPage());
    $this->assertSame(1, $result['opened']);
    $this->assertSame(0, $result['deferred_delay']);
    $this->assertSame(['llm:Mon-Fri 08:00-17:00'], $this->findings());
    $this->assertSame(1, $result['summaries']['llm']['calls']);
  }

  /**
   * Tests that the patterns find their findings and the model the rest.
   */
  public function testPlainAndAiFindingsAreBothRecorded(): void {
    $this->script([['reply' => self::HOURS_REPLY]]);
    $this->scan($this->createPage(self::MIXED));
    $this->assertSame(['llm:Mon-Fri 08:00-17:00', 'phone:9095550123'], $this->findings());
  }

  /**
   * Tests that fallback asks only for the types the patterns found none of.
   */
  public function testFallbackAsksOnlyForTheMissingType(): void {
    $this->script([['reply' => self::HOURS_REPLY]]);
    $this->scan($this->createPage(self::MIXED));
    $calls = $this->calls();
    $this->assertCount(1, $calls);
    $this->assertSame(['hours'], $calls[0]['schema']['schema']['properties']['claims']['items']['properties']['type']['enum']);
  }

  /**
   * Tests that a sweep that changes nothing makes no second call.
   */
  public function testSecondSweepIsServedFromTheCache(): void {
    $this->script([['reply' => self::HOURS_REPLY]]);
    $node = $this->createPage();
    $this->scan($node);
    $result = $this->scan($node);
    $this->assertCount(1, $this->calls());
    $this->assertSame(0, $result['opened']);
    $this->assertSame(['llm:Mon-Fri 08:00-17:00'], $this->findings());
    $this->assertSame(1, $result['summaries']['llm']['cache_hits']);
    $this->assertSame(1, $result['summaries']['llm']['calls']);
  }

  /**
   * Tests that an outage still records the patterns' findings, and defers.
   */
  public function testOutageStillRecordsRegexFindingsAndKeepsAiFindingsOpen(): void {
    $this->script([['reply' => self::HOURS_REPLY]]);
    $node = $this->createPage(self::MIXED);
    $this->scan($node);
    $this->assertSame(['llm:Mon-Fri 08:00-17:00', 'phone:9095550123'], $this->findings());

    $this->container->get('cache.fact_drift_llm')->deleteAll();
    $this->script([['throw' => 'refused']]);
    $body = str_replace('909-555-0123', '(909) 555-0100', self::MIXED);
    $node->set('body', ['value' => '<p>' . $body . '</p>', 'format' => 'plain_text']);
    $node->save();
    $result = $this->scan($node);
    $this->assertSame(900, $result['deferred_delay']);
    $this->assertSame('outage', $result['deferred_reason']);
    $this->assertSame(1, $result['closed']);
    $this->assertSame(['llm:Mon-Fri 08:00-17:00'], $this->findings());

    $body = str_replace('909-555-0123', '909-555-0188', self::MIXED);
    $node->set('body', ['value' => '<p>' . $body . '</p>', 'format' => 'plain_text']);
    $node->save();
    $result = $this->scan($node);
    $this->assertSame(900, $result['deferred_delay']);
    $this->assertSame(1, $result['opened']);
    $this->assertSame(['llm:Mon-Fri 08:00-17:00', 'phone:9095550188'], $this->findings());
    $this->assertSame(2, $result['summaries']['llm']['deferred_outage']);
  }

  /**
   * Tests that a deferred field keeps its AI findings open.
   */
  public function testDeferredFieldKeepsAiFindingsOpen(): void {
    $this->script([['reply' => self::HOURS_REPLY]]);
    $node = $this->createPage();
    $this->scan($node);
    $this->container->get('cache.fact_drift_llm')->deleteAll();
    $this->script([['throw' => 'rate_limit', 'retry_after' => 30]]);
    $result = $this->scan($node);
    $this->assertSame(30, $result['deferred_delay']);
    $this->assertSame('rate_limited', $result['deferred_reason']);
    $this->assertSame(0, $result['closed']);
    $this->assertSame(['llm:Mon-Fri 08:00-17:00'], $this->findings());
  }

  /**
   * Tests that the kill switch makes no call and keeps findings, with no delay.
   */
  public function testKillSwitchHoldsTheFieldAndKeepsFindings(): void {
    $this->script([['reply' => self::HOURS_REPLY]]);
    $node = $this->createPage();
    $this->scan($node);
    $this->killSwitch(TRUE);
    $this->container->get('cache.fact_drift_llm')->deleteAll();
    $this->script([['reply' => self::HOURS_REPLY]]);
    $result = $this->scan($node);
    $this->assertCount(1, $this->calls());
    $this->assertSame(0, $result['deferred_delay']);
    $this->assertSame(0, $result['closed']);
    $this->assertSame(['llm:Mon-Fri 08:00-17:00'], $this->findings());
    $this->assertGreaterThanOrEqual(1, $result['summaries']['llm']['held']);
  }

  /**
   * Tests that an extractor that is turned off lets its findings close.
   */
  public function testTurningTheExtractorOffClosesItsFindings(): void {
    $this->script([['reply' => self::HOURS_REPLY]]);
    $node = $this->createPage();
    $this->scan($node);
    $this->configureAi(['enabled' => FALSE]);
    $this->script([['reply' => self::HOURS_REPLY]]);
    $result = $this->scan($node);
    $this->assertSame(1, $result['closed']);
    $this->assertSame([], $this->findings());
    $this->assertArrayNotHasKey('llm', $result['summaries']);
    $this->assertCount(1, $this->calls());
  }

  /**
   * Tests that a full scan counts the nodes deferred by a spent budget.
   */
  public function testScanAllCountsDeferredNodesByReason(): void {
    $this->configureAi(['max_calls_per_run' => 0]);
    $this->script([['reply' => self::HOURS_REPLY]]);
    $this->createPage();
    $result = $this->container->get('fact_drift.scanner')->scanAll();
    $this->assertSame(['budget' => 1], $result['deferred']);
    $this->assertSame(1, $result['summaries']['llm']['deferred_budget']);
    $this->assertSame([], $this->calls());
  }

  /**
   * Tests that a page anonymous visitors cannot view is never sent.
   */
  public function testPageHiddenFromAnonymousIsNeverSent(): void {
    $this->script([['reply' => self::HOURS_REPLY]]);
    $node = $this->createPage();
    $this->scan($node);
    $this->assertCount(1, $this->calls());

    $this->anonymousMayView(FALSE);
    $this->container->get('cache.fact_drift_llm')->deleteAll();
    $this->script([['reply' => self::HOURS_REPLY]]);
    $result = $this->scan($node);
    $this->assertCount(1, $this->calls());
    $this->assertSame(1, $result['closed']);
    $this->assertSame([], $this->findings());
  }

  /**
   * Tests that an unpublished page is never scanned, so never sent.
   */
  public function testUnpublishedPageIsNeverSent(): void {
    $this->script([['reply' => self::HOURS_REPLY]]);
    $result = $this->scan($this->createPage(self::HOURS_PROSE, FALSE));
    $this->assertSame(0, $result['claims']);
    $this->assertSame([], $this->calls());
  }

  /**
   * Tests that only allowed fields are sent, and nothing else of the page.
   */
  public function testOnlyAllowedFieldsAreSent(): void {
    $this->script([['reply' => self::HOURS_REPLY], ['reply' => self::HOURS_REPLY]]);
    $this->scan($this->createPage(self::HOURS_PROSE, TRUE, 'Notes ZQX: we are open Saturday 9 to 12, call 909-555-0101.'));
    $calls = $this->calls();
    $this->assertCount(1, $calls);
    $everything = json_encode($calls);
    $this->assertStringNotContainsString('ZQX', (string) $everything);
    $this->assertStringNotContainsString('909-555-0101', (string) $everything);
    $this->assertStringContainsString('Monday through Friday', $calls[0]['user']);
  }

  /**
   * Tests that allowing a second field sends that field in its own call.
   */
  public function testAllowingAnotherFieldSendsIt(): void {
    $this->configureAi(['allowlist' => [['bundle' => 'page', 'field' => 'field_notes']]]);
    $this->script([['reply' => '{"claims": []}']]);
    $this->scan($this->createPage(self::HOURS_PROSE, TRUE, 'Notes ZQX: open Saturday 9 to 12.'));
    $calls = $this->calls();
    $this->assertCount(1, $calls);
    $this->assertStringContainsString('Notes ZQX', $calls[0]['user']);
    $this->assertStringNotContainsString('Monday through Friday', $calls[0]['user']);
  }

}
