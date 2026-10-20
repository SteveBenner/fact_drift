<?php

namespace Drupal\Tests\fact_drift_ai\Kernel;

use Drupal\fact_drift_ai\CallGuard;
use Drupal\fact_drift_ai\CallResult;
use Drupal\fact_drift_ai\Prompt;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests the guard around every model call, against the fake provider.
 *
 * @group fact_drift_ai
 * @covers \Drupal\fact_drift_ai\CallGuard
 * @covers \Drupal\fact_drift_ai\CallResult
 */
#[RunTestsInSeparateProcesses]
class CallGuardTest extends AiKernelTestBase {

  /**
   * The microseconds the guard asked to wait.
   *
   * @var int[]
   */
  protected array $slept = [];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->slept = [];
    $this->guard()->setSleeper(function (int $microseconds): void {
      $this->slept[] = $microseconds;
    });
  }

  /**
   * Makes one guarded call with a fixed window.
   *
   * @return \Drupal\fact_drift_ai\CallResult
   *   The result.
   */
  protected function call(): CallResult {
    return $this->guard()->call((new Prompt())->input('Call 909-555-0100.', ['phone']));
  }

  /**
   * Tests that a reply comes back with its tokens and the call is recorded.
   */
  public function testReplyIsReturnedWithTokens(): void {
    $this->script([['reply' => '{"claims": []}', 'usage' => ['input' => 120, 'output' => 7]]]);
    $result = $this->call();
    $this->assertNull($result->signal);
    $this->assertSame('{"claims": []}', $result->text);
    $this->assertSame(120, $result->inputTokens);
    $this->assertSame(7, $result->outputTokens);
    $summary = $this->guard()->runSummary();
    $this->assertSame(1, $summary['calls']);
    $this->assertSame(120, $summary['input_tokens']);
    $this->assertSame(7, $summary['output_tokens']);
    $this->assertCount(1, $this->calls());
  }

  /**
   * Tests what the provider receives with every call.
   */
  public function testCallCarriesTagFilterSchemaModelAndTimeouts(): void {
    $this->script([['reply' => '{"claims": []}']]);
    $this->call();
    $recorded = $this->calls()[0];
    $this->assertContains('fact_drift_ai', $recorded['tags']);
    $this->assertTrue($recorded['full_trust']);
    $this->assertSame('scripted', $recorded['model']);
    $this->assertSame('fact_drift_claims', $recorded['schema']['name']);
    $this->assertSame(['phone'], $recorded['schema']['schema']['properties']['claims']['items']['properties']['type']['enum']);
    $this->assertSame(['timeout' => 3, 'connect_timeout' => 3], $recorded['http_client_options']);
    $this->assertSame("<page_text>\nCall 909-555-0100.\n</page_text>", $recorded['user']);
  }

  /**
   * Tests that reasoning none sends reasoning_effort none.
   */
  public function testReasoningSettingDecidesReasoningEffort(): void {
    $this->script([['reply' => '{"claims": []}']]);
    $this->call();
    $this->assertSame('none', $this->calls()[0]['configuration']['reasoning_effort']);
  }

  /**
   * Tests that the provider default sends no reasoning_effort.
   */
  public function testProviderDefaultReasoningSendsNothing(): void {
    $this->configureAi(['reasoning' => 'provider_default']);
    $this->script([['reply' => '{"claims": []}']]);
    $this->call();
    $this->assertArrayNotHasKey('reasoning_effort', $this->calls()[0]['configuration']);
  }

  /**
   * Tests that the override of the reasoning setting wins.
   */
  public function testReasoningOverrideWins(): void {
    $this->guard()->setReasoning('provider_default');
    $this->script([['reply' => '{"claims": []}']]);
    $this->call();
    $this->assertArrayNotHasKey('reasoning_effort', $this->calls()[0]['configuration']);
  }

  /**
   * Tests that the model override is the model the provider receives.
   */
  public function testModelOverrideIsUsed(): void {
    $this->guard()->setModel('other-model');
    $this->script([['reply' => '{"claims": []}']]);
    $this->call();
    $this->assertSame('other-model', $this->calls()[0]['model']);
  }

  /**
   * Tests that the kill switch stops the call, even between two calls.
   */
  public function testKillSwitchStopsEveryCall(): void {
    $this->script([['reply' => '{"claims": []}'], ['reply' => '{"claims": []}']]);
    $this->assertNull($this->call()->signal);
    $this->killSwitch(TRUE);
    $result = $this->call();
    $this->assertSame(CallResult::HELD, $result->signal);
    $this->assertSame('kill_switch', $result->reason);
    $this->assertCount(1, $this->calls());
    $this->assertSame(1, $this->guard()->runSummary()['held']);
  }

  /**
   * Tests that turning the extractor off stops the call.
   */
  public function testDisabledStopsTheCall(): void {
    $this->configureAi(['enabled' => FALSE]);
    $this->script([['reply' => '{"claims": []}']]);
    $result = $this->call();
    $this->assertSame(CallResult::HELD, $result->signal);
    $this->assertSame('kill_switch', $result->reason);
    $this->assertSame([], $this->calls());
  }

  /**
   * Tests that the evaluation can run with the extractor off.
   */
  public function testEnabledIsNotRequiredWhenTheRunSaysSo(): void {
    $this->configureAi(['enabled' => FALSE]);
    $this->guard()->setEnabledRequired(FALSE);
    $this->script([['reply' => '{"claims": []}']]);
    $this->assertNull($this->call()->signal);
    $this->killSwitch(TRUE);
    $this->assertSame('kill_switch', $this->call()->reason);
  }

  /**
   * Tests that no provider, no model or an unusable provider means no call.
   *
   * @param array<string, mixed> $settings
   *   Settings that leave the extractor unconfigured.
   *
   * @dataProvider unconfiguredProvider
   */
  public function testUnconfiguredMakesNoCall(array $settings): void {
    $this->configureAi($settings);
    $this->script([['reply' => '{"claims": []}']]);
    $result = $this->call();
    $this->assertSame(CallResult::HELD, $result->signal);
    $this->assertSame('unconfigured', $result->reason);
    $this->assertSame([], $this->calls());
    $this->assertSame('unconfigured', $this->guard()->gate());
    $this->assertFalse($this->guard()->isConfigured());
  }

  /**
   * Provides settings that leave the extractor unconfigured.
   *
   * @return array<string, array{array<string, mixed>}>
   *   The settings.
   */
  public static function unconfiguredProvider(): array {
    return [
      'no provider' => [['provider' => '']],
      'no model' => [['model' => '']],
      'a provider that does not exist' => [['provider' => 'no_such_provider']],
    ];
  }

  /**
   * Tests that a provider the ai module reports as not usable means no call.
   */
  public function testUnusableProviderMakesNoCall(): void {
    $this->container->get('state')->set('fact_drift_ai_test.usable', FALSE);
    $this->script([['reply' => '{"claims": []}']]);
    $result = $this->call();
    $this->assertSame('unconfigured', $result->reason);
    $this->assertSame([], $this->calls());
  }

  /**
   * Tests that each open side path holds the call and names no page text.
   *
   * @param string $config
   *   The configuration object that is overridden.
   * @param array<string, mixed> $values
   *   Its values.
   * @param string $named
   *   The setting the guard names.
   *
   * @dataProvider sidePathProvider
   */
  public function testSidePathHoldsTheCall(string $config, array $values, string $named): void {
    $this->overrideConfig($config, $values);
    $this->script([['reply' => '{"claims": []}']]);
    $result = $this->call();
    $this->assertSame(CallResult::HELD, $result->signal);
    $this->assertSame('side_path', $result->reason);
    $this->assertSame([], $this->calls());
    $this->assertSame([$named], $this->guard()->sidePaths());
  }

  /**
   * Provides the side paths.
   *
   * @return array<string, array{string, array<string, mixed>, string}>
   *   The configuration, its values and the setting named.
   */
  public static function sidePathProvider(): array {
    return [
      'prompt logging' => ['ai_logging.settings', ['prompt_logging' => TRUE], 'ai_logging.settings:prompt_logging'],
      'prompt logging that excludes another tag' => [
        'ai_logging.settings',
        ['prompt_logging' => TRUE, 'prompt_logging_excluded_tags' => ['other']],
        'ai_logging.settings:prompt_logging',
      ],
      'observability input' => [
        'ai_observability.settings',
        ['log_input' => TRUE],
        'ai_observability.settings:log_input',
      ],
      'external moderation' => [
        'ai.external_moderation',
        ['moderations' => ['openai_moderation']],
        'ai.external_moderation:moderations',
      ],
    ];
  }

  /**
   * Tests that excluding the tag from prompt logging lets the call through.
   */
  public function testExcludingTheTagFromPromptLoggingLetsTheCallThrough(): void {
    $this->overrideConfig('ai_logging.settings', [
      'prompt_logging' => TRUE,
      'prompt_logging_excluded_tags' => 'other, fact_drift_ai',
    ]);
    $this->script([['reply' => '{"claims": []}']]);
    $this->assertNull($this->call()->signal);
    $this->overrideConfig('ai_logging.settings', [
      'prompt_logging' => TRUE,
      'prompt_logging_excluded_tags' => ['fact_drift_ai'],
    ]);
    $this->assertNull($this->guard()->gate());
  }

  /**
   * Tests that a spent budget defers by an hour and makes no further call.
   */
  public function testBudgetExhaustionDefers(): void {
    $this->configureAi(['max_calls_per_run' => 2]);
    $this->script(array_fill(0, 3, ['reply' => '{"claims": []}']));
    $this->assertNull($this->call()->signal);
    $this->assertNull($this->call()->signal);
    $result = $this->call();
    $this->assertSame(CallResult::DEFERRED, $result->signal);
    $this->assertSame(3600, $result->delay);
    $this->assertSame('budget', $result->reason);
    $this->assertCount(2, $this->calls());
    $summary = $this->guard()->runSummary();
    $this->assertSame(2, $summary['calls']);
    $this->assertSame(1, $summary['deferred_budget']);
  }

  /**
   * Tests that the budget override replaces the setting.
   */
  public function testBudgetOverrideReplacesTheSetting(): void {
    $this->guard()->setBudget(1);
    $this->script(array_fill(0, 2, ['reply' => '{"claims": []}']));
    $this->assertNull($this->call()->signal);
    $this->assertSame('budget', $this->call()->reason);
  }

  /**
   * Tests that a retry counts against the budget.
   */
  public function testRetriesCountAgainstTheBudget(): void {
    $this->configureAi(['max_calls_per_run' => 2, 'max_retries' => 5]);
    $this->script(array_fill(0, 5, ['throw' => 'server', 'status' => 503]));
    $result = $this->call();
    $this->assertSame('budget', $result->reason);
    $this->assertCount(2, $this->calls());
    $this->assertSame(2, $this->guard()->runSummary()['retries']);
  }

  /**
   * Tests that a 429 defers by the Retry-After and stops further calls.
   */
  public function testRateLimitDefersWithRetryAfterAndStopsFurtherCalls(): void {
    $this->script([['throw' => 'rate_limit', 'retry_after' => 17], ['reply' => '{"claims": []}']]);
    $result = $this->call();
    $this->assertSame(CallResult::DEFERRED, $result->signal);
    $this->assertSame(17, $result->delay);
    $this->assertSame('rate_limited', $result->reason);
    $this->assertCount(1, $this->calls());

    $again = $this->call();
    $this->assertSame('rate_limited', $again->reason);
    $this->assertSame(17, $again->delay);
    $this->assertCount(1, $this->calls());
    $this->assertCount(1, $this->scriptLeft());
    $summary = $this->guard()->runSummary();
    $this->assertSame(1, $summary['calls']);
    $this->assertSame(2, $summary['deferred_rate_limited']);
    $this->assertSame(0, $summary['retries']);
    $this->assertCount(0, $this->slept);
  }

  /**
   * Tests that a 429 without a Retry-After defers by sixty seconds.
   */
  public function testRateLimitWithoutRetryAfterDefersBySixtySeconds(): void {
    $this->script([['throw' => 'rate_limit']]);
    $result = $this->call();
    $this->assertSame(60, $result->delay);
    $this->assertSame('rate_limited', $result->reason);
  }

  /**
   * Tests that a server error is retried with a jittered wait and can succeed.
   */
  public function testServerErrorIsRetried(): void {
    $this->script([['throw' => 'server', 'status' => 503], ['reply' => '{"claims": []}']]);
    $result = $this->call();
    $this->assertNull($result->signal);
    $this->assertSame('{"claims": []}', $result->text);
    $this->assertCount(2, $this->calls());
    $this->assertSame(1, $this->guard()->runSummary()['retries']);
    $this->assertCount(1, $this->slept);
    $this->assertGreaterThanOrEqual(0, $this->slept[0]);
    $this->assertLessThanOrEqual(1000000, $this->slept[0]);
  }

  /**
   * Tests that a timeout is retried like a server error.
   */
  public function testTimeoutIsRetried(): void {
    $this->script([['throw' => 'timeout'], ['throw' => 'timeout'], ['reply' => '{"claims": []}']]);
    $result = $this->call();
    $this->assertNull($result->signal);
    $this->assertCount(3, $this->calls());
    $this->assertSame(2, $this->guard()->runSummary()['retries']);
    $this->assertCount(2, $this->slept);
    $this->assertLessThanOrEqual(1000000, $this->slept[0]);
    $this->assertLessThanOrEqual(2000000, $this->slept[1]);
  }

  /**
   * Tests that retries are bounded and a survivor opens the breaker.
   */
  public function testExhaustedRetriesOpenTheBreaker(): void {
    $this->script(array_fill(0, 6, ['throw' => 'server', 'status' => 502]));
    $result = $this->call();
    $this->assertSame(CallResult::DEFERRED, $result->signal);
    $this->assertSame(900, $result->delay);
    $this->assertSame('outage', $result->reason);
    $this->assertCount(3, $this->calls());
    $this->assertSame(2, $this->guard()->runSummary()['retries']);

    $next = $this->call();
    $this->assertSame('outage', $next->reason);
    $this->assertSame(900, $next->delay);
    $this->assertCount(3, $this->calls());
    $this->assertSame(2, $this->guard()->runSummary()['deferred_outage']);
  }

  /**
   * Tests that max_retries zero means no retry at all.
   */
  public function testNoRetriesWhenTheSettingIsZero(): void {
    $this->configureAi(['max_retries' => 0]);
    $this->script(array_fill(0, 3, ['throw' => 'timeout']));
    $this->assertSame('outage', $this->call()->reason);
    $this->assertCount(1, $this->calls());
    $this->assertCount(0, $this->slept);
  }

  /**
   * Tests that a refused connection opens the breaker without a retry.
   */
  public function testConnectionFailureOpensTheBreakerWithoutRetry(): void {
    $this->script([['throw' => 'refused'], ['reply' => '{"claims": []}']]);
    $result = $this->call();
    $this->assertSame('outage', $result->reason);
    $this->assertSame(900, $result->delay);
    $this->assertCount(1, $this->calls());
    $this->assertSame(0, $this->guard()->runSummary()['retries']);
    $this->assertCount(0, $this->slept);
  }

  /**
   * Tests that another provider error rejects the window and nothing else.
   */
  public function testOtherProviderErrorRejectsWithoutDeferring(): void {
    $this->script([['throw' => 'bad_request'], ['reply' => '{"claims": []}']]);
    $result = $this->call();
    $this->assertSame(CallResult::REJECTED, $result->signal);
    $this->assertSame('provider_error', $result->reason);
    $this->assertCount(1, $this->calls());
    $this->assertSame(1, $this->guard()->runSummary()['rejected_provider_error']);
    $this->assertNull($this->call()->signal);
    $this->assertCount(2, $this->calls());
  }

  /**
   * Tests that a status in the 4xx range other than 429 is not retried.
   */
  public function testClientErrorsAreNotRetried(): void {
    $this->script(array_fill(0, 3, ['throw' => 'bad_request']));
    $this->call();
    $this->assertCount(0, $this->slept);
    $this->assertSame(0, $this->guard()->runSummary()['retries']);
  }

  /**
   * Tests that the minimum interval is waited out between calls.
   */
  public function testMinimumIntervalIsEnforced(): void {
    $this->configureAi(['min_interval_ms' => 800]);
    $this->script(array_fill(0, 2, ['reply' => '{"claims": []}']));
    $this->call();
    $this->assertCount(0, $this->slept);
    $this->call();
    $this->assertCount(1, $this->slept);
    $this->assertGreaterThan(0, $this->slept[0]);
    $this->assertLessThanOrEqual(800000, $this->slept[0]);
  }

  /**
   * Tests that no wait happens when the interval is zero.
   */
  public function testNoWaitWithoutAnInterval(): void {
    $this->script(array_fill(0, 2, ['reply' => '{"claims": []}']));
    $this->call();
    $this->call();
    $this->assertCount(0, $this->slept);
  }

  /**
   * Tests that the provider is created once per process.
   */
  public function testProviderIsCreatedOnce(): void {
    $this->script(array_fill(0, 2, ['reply' => '{"claims": []}']));
    $this->call();
    $this->call();
    $calls = $this->calls();
    $this->assertCount(2, $calls);
    $this->assertSame($calls[0]['configuration'], $calls[1]['configuration']);
    $this->assertSame(2, $this->guard()->runSummary()['calls']);
  }

  /**
   * Tests the run summary entry: one line, counters only, no page text.
   */
  public function testDestructLogsOneSummaryWithoutPageText(): void {
    $logger = new CollectingLogger();
    $guard = new CallGuard($this->container->get('ai.provider'), $this->container->get('config.factory'), $logger);
    $guard->destruct();
    $this->assertCount(0, $logger->entries);

    $reply = '{"claims": [{"type": "phone", "quote": "909-555-0100", "value": "9095550100"}]}';
    $this->script([['reply' => $reply, 'usage' => ['input' => 50, 'output' => 5]]]);
    $guard->call((new Prompt())->input('Secret clinic text 909-555-0100.', ['phone']));
    $guard->countRejections(['quote_not_found' => 2]);
    $guard->destruct();
    $this->assertCount(1, $logger->entries);
    ['level' => $level, 'message' => $message, 'context' => $context] = $logger->entries[0];
    $this->assertSame('info', $level);
    $this->assertStringContainsString('Run summary', $message);
    $summary = (string) $context['@summary'];
    $this->assertStringContainsString('calls 1', $summary);
    $this->assertStringContainsString('rejected_quote_not_found 2', $summary);
    $this->assertStringContainsString('input_tokens 50', $summary);
    $this->assertStringNotContainsString('Secret', $summary . $message);
    $this->assertStringNotContainsString('909-555-0100', $summary . $message);
    $this->assertStringNotContainsString('claims', $summary . $message);
  }

  /**
   * Tests that a held run is summarised too.
   */
  public function testDestructLogsHeldRuns(): void {
    $this->killSwitch(TRUE);
    $logger = new CollectingLogger();
    $guard = new CallGuard($this->container->get('ai.provider'), $this->container->get('config.factory'), $logger);
    $this->assertSame(CallResult::HELD, $guard->call((new Prompt())->input('x', ['phone']))->signal);
    $guard->destruct();
    $this->assertCount(1, $logger->entries);
    $this->assertStringContainsString('held 1', (string) $logger->entries[0]['context']['@summary']);
  }

}
