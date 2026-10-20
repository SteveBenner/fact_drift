<?php

namespace Drupal\Tests\fact_drift_ai\Unit;

use Drupal\Component\Plugin\Factory\FactoryInterface;
use Drupal\fact_drift_ai\CallGuard;
use Drupal\Tests\UnitTestCase;
use Psr\Log\LoggerInterface;

/**
 * Tests the call guard's backoff bounds, jitter, gate and side paths.
 *
 * @group fact_drift_ai
 * @covers \Drupal\fact_drift_ai\CallGuard
 */
class CallGuardTest extends UnitTestCase {

  /**
   * Builds a guard over stubbed configuration.
   *
   * @param array<string, array<string, mixed>> $config
   *   The config objects by name, merged over the quiet defaults.
   *
   * @return \Drupal\fact_drift_ai\CallGuard
   *   The guard.
   */
  protected function guard(array $config = []): CallGuard {
    $config += [
      'fact_drift.settings' => ['kill_switch' => FALSE],
      'fact_drift_ai.settings' => ['enabled' => TRUE, 'provider' => '', 'model' => ''],
      'ai_logging.settings' => [],
      'ai_observability.settings' => [],
      'ai.external_moderation' => [],
    ];
    return new CallGuard(
      $this->createMock(FactoryInterface::class),
      $this->getConfigFactoryStub($config),
      $this->createMock(LoggerInterface::class),
    );
  }

  /**
   * Tests the longest wait before each retry.
   *
   * @dataProvider ceilingProvider
   */
  public function testBackoffCeilingDoublesFromOneSecondAndStopsAtThirty(int $retry, int $seconds): void {
    $this->assertSame($seconds * 1000000, CallGuard::backoffCeiling($retry));
  }

  /**
   * Provides retry numbers and the ceiling in seconds.
   *
   * @return array<string, array{int, int}>
   *   The retry number and the ceiling.
   */
  public static function ceilingProvider(): array {
    return [
      'negative counts as the first' => [-3, 1],
      'first' => [0, 1],
      'second' => [1, 2],
      'third' => [2, 4],
      'fourth' => [3, 8],
      'fifth' => [4, 16],
      'sixth is capped' => [5, 30],
      'far beyond is capped' => [40, 30],
    ];
  }

  /**
   * Tests that every wait lies between zero and the ceiling.
   */
  public function testBackoffStaysWithinBounds(): void {
    foreach ([0, 1, 2, 3, 5, 12] as $retry) {
      $ceiling = CallGuard::backoffCeiling($retry);
      for ($i = 0; $i < 200; $i++) {
        $wait = CallGuard::backoffMicroseconds($retry);
        $this->assertGreaterThanOrEqual(0, $wait);
        $this->assertLessThanOrEqual($ceiling, $wait);
      }
    }
  }

  /**
   * Tests that the wait is jittered across the whole range, not fixed.
   */
  public function testBackoffIsJittered(): void {
    $waits = [];
    for ($i = 0; $i < 300; $i++) {
      $waits[] = CallGuard::backoffMicroseconds(3);
    }
    $this->assertGreaterThan(100, count(array_unique($waits)));
    $this->assertLessThan(CallGuard::backoffCeiling(3) / 4, min($waits));
    $this->assertGreaterThan(CallGuard::backoffCeiling(3) * 3 / 4, max($waits));
  }

  /**
   * Tests that no side path is open on quiet configuration.
   */
  public function testNoSidePathsOnQuietConfiguration(): void {
    $this->assertSame([], $this->guard()->sidePaths());
  }

  /**
   * Tests that each side path is named by its setting.
   */
  public function testEachSidePathNamesItsSetting(): void {
    $this->assertSame(
      ['ai_logging.settings:prompt_logging'],
      $this->guard(['ai_logging.settings' => ['prompt_logging' => TRUE]])->sidePaths(),
    );
    $this->assertSame(
      ['ai_observability.settings:log_input'],
      $this->guard(['ai_observability.settings' => ['log_input' => TRUE]])->sidePaths(),
    );
    $this->assertSame(
      ['ai.external_moderation:moderations'],
      $this->guard(['ai.external_moderation' => ['moderations' => ['openai_moderation']]])->sidePaths(),
    );
    $this->assertSame([], $this->guard(['ai.external_moderation' => ['moderations' => []]])->sidePaths());
    $this->assertSame([
      'ai_logging.settings:prompt_logging',
      'ai_observability.settings:log_input',
      'ai.external_moderation:moderations',
    ], $this->guard([
      'ai_logging.settings' => ['prompt_logging' => TRUE],
      'ai_observability.settings' => ['log_input' => TRUE],
      'ai.external_moderation' => ['moderations' => ['x']],
    ])->sidePaths());
  }

  /**
   * Tests that excluding the tag closes the prompt logging side path.
   *
   * @dataProvider excludedTagsProvider
   */
  public function testExcludedTagClosesPromptLogging(mixed $excluded, bool $open): void {
    $guard = $this->guard([
      'ai_logging.settings' => [
        'prompt_logging' => TRUE,
        'prompt_logging_excluded_tags' => $excluded,
      ],
    ]);
    $this->assertSame($open ? ['ai_logging.settings:prompt_logging'] : [], $guard->sidePaths());
  }

  /**
   * Provides excluded-tag settings and whether the side path stays open.
   *
   * @return array<string, array{mixed, bool}>
   *   The setting and whether prompt logging still holds the field.
   */
  public static function excludedTagsProvider(): array {
    return [
      'a sequence with the tag' => [['other', 'fact_drift_ai'], FALSE],
      'a sequence without the tag' => [['other', 'another'], TRUE],
      'a string with the tag' => ['other, fact_drift_ai', FALSE],
      'a string with the tag in capitals' => ['FACT_DRIFT_AI', FALSE],
      'a string without the tag' => ['other,fact_drift', TRUE],
      'an empty string' => ['', TRUE],
      'null' => [NULL, TRUE],
      'an empty sequence' => [[], TRUE],
    ];
  }

  /**
   * Tests that the excluded tag does not close the other side paths.
   */
  public function testExcludedTagDoesNotCloseOtherSidePaths(): void {
    $guard = $this->guard([
      'ai_logging.settings' => ['prompt_logging' => TRUE, 'prompt_logging_excluded_tags' => ['fact_drift_ai']],
      'ai_observability.settings' => ['log_input' => TRUE],
    ]);
    $this->assertSame(['ai_observability.settings:log_input'], $guard->sidePaths());
  }

  /**
   * Tests that the kill switch is read from the base module's settings.
   */
  public function testKillSwitchReadsTheBaseSetting(): void {
    $this->assertFalse($this->guard()->killSwitchOn());
    $this->assertTrue($this->guard(['fact_drift.settings' => ['kill_switch' => TRUE]])->killSwitchOn());
  }

  /**
   * Tests that the kill switch and the enabled setting each close the gate.
   */
  public function testKillSwitchAndEnabledEachCloseTheGate(): void {
    $this->assertSame('kill_switch', $this->guard(['fact_drift.settings' => ['kill_switch' => TRUE]])->gate());
    $disabled = ['enabled' => FALSE, 'provider' => 'p', 'model' => 'm'];
    $this->assertSame('kill_switch', $this->guard(['fact_drift_ai.settings' => $disabled])->gate());
    $notRequired = $this->guard(['fact_drift_ai.settings' => ['enabled' => FALSE, 'provider' => '', 'model' => '']]);
    $notRequired->setEnabledRequired(FALSE);
    $this->assertSame('unconfigured', $notRequired->gate());
    $killed = $this->guard(['fact_drift.settings' => ['kill_switch' => TRUE]]);
    $killed->setEnabledRequired(FALSE);
    $this->assertSame('kill_switch', $killed->gate());
  }

  /**
   * Tests that no provider or model means not configured.
   */
  public function testNoProviderOrModelIsUnconfigured(): void {
    $this->assertFalse($this->guard()->isConfigured());
    $this->assertFalse($this->guard(['fact_drift_ai.settings' => ['enabled' => TRUE, 'provider' => 'p', 'model' => '']])->isConfigured());
    $this->assertFalse($this->guard(['fact_drift_ai.settings' => ['enabled' => TRUE, 'provider' => '', 'model' => 'm']])->isConfigured());
    $this->assertSame('unconfigured', $this->guard()->gate());
  }

  /**
   * Tests that the model override stands in for an unset model.
   */
  public function testModelOverrideCountsAsModel(): void {
    $guard = $this->guard(['fact_drift_ai.settings' => ['enabled' => TRUE, 'provider' => 'p', 'model' => '']]);
    $guard->setModel('override');
    $this->assertSame('unconfigured', $guard->gate());
    $guard->setModel(NULL);
    $this->assertFalse($guard->isConfigured());
  }

  /**
   * Tests that counters start at zero and rejections are named in the summary.
   */
  public function testRunSummaryListsCountersAndRejections(): void {
    $guard = $this->guard();
    $summary = $guard->runSummary();
    $this->assertSame(0, $summary['calls']);
    $this->assertSame(0, $summary['held']);
    $this->assertSame(0, $summary['input_tokens']);
    $guard->countHold();
    $guard->countCacheHit();
    $guard->countRejections(['quote_not_found' => 2, 'invalid_json' => 1]);
    $guard->countRejections(['quote_not_found' => 1]);
    $summary = $guard->runSummary();
    $this->assertSame(1, $summary['held']);
    $this->assertSame(1, $summary['cache_hits']);
    $this->assertSame(1, $summary['rejected_invalid_json']);
    $this->assertSame(3, $summary['rejected_quote_not_found']);
  }

}
