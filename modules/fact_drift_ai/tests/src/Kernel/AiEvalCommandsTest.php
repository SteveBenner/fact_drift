<?php

namespace Drupal\Tests\fact_drift_ai\Kernel;

use Drupal\Component\Serialization\Yaml;
use Drupal\fact_drift_ai\Drush\Commands\AiEvalCommands;
use Drush\Log\DrushLoggerManager;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Console\Output\ConsoleOutput;

/**
 * Tests the evaluation command against the fake provider.
 *
 * @group fact_drift_ai
 * @covers \Drupal\fact_drift_ai\Drush\Commands\AiEvalCommands
 */
#[RunTestsInSeparateProcesses]
class AiEvalCommandsTest extends AiKernelTestBase {

  /**
   * What the command wrote to standard output.
   */
  protected BufferedOutput $stdout;

  /**
   * What the command wrote to standard error.
   */
  protected BufferedOutput $stderr;

  /**
   * Builds the command with captured output streams.
   *
   * @return \Drupal\fact_drift_ai\Drush\Commands\AiEvalCommands
   *   The command.
   */
  protected function command(): AiEvalCommands {
    $this->stdout = new BufferedOutput();
    $this->stderr = new BufferedOutput();
    $output = new class($this->stdout, $this->stderr) extends ConsoleOutput {

      /**
       * Constructs the output.
       */
      public function __construct(protected BufferedOutput $out, BufferedOutput $err) {
        parent::__construct();
        $this->setErrorOutput($err);
      }

      /**
       * {@inheritdoc}
       */
      protected function doWrite(string $message, bool $newline): void {
        $this->out->write($message, $newline);
      }

    };
    $command = AiEvalCommands::create($this->container);
    $command->setInput(new ArrayInput([]));
    $command->setOutput($output);
    $command->setLogger(new DrushLoggerManager());
    return $command;
  }

  /**
   * Gets the cases of the fixture.
   *
   * @return array<int, array<string, mixed>>
   *   The cases.
   */
  protected function cases(): array {
    return Yaml::decode((string) file_get_contents(dirname(__DIR__, 2) . '/fixtures/eval.yml'));
  }

  /**
   * Reads the call budget the command set on the guard.
   *
   * @return int|null
   *   The budget.
   */
  protected function budget(): ?int {
    $property = new \ReflectionProperty($this->guard(), 'budget');
    return $property->getValue($this->guard());
  }

  /**
   * Tests that the default budget leaves room for the retries of every case.
   */
  public function testDefaultBudgetIsCasesTimesOnePlusRetries(): void {
    $count = count($this->cases());
    $this->assertSame(30, $count);
    $this->script(array_fill(0, $count, ['reply' => '{"claims": []}']));
    $rows = $this->command()->aiEval();
    $this->assertCount($count, $rows->getArrayCopy());
    $this->assertSame($count * 3, $this->budget());
  }

  /**
   * Tests that the default budget follows the retries setting.
   */
  public function testDefaultBudgetFollowsMaxRetries(): void {
    $this->configureAi(['max_retries' => 0]);
    $this->script(array_fill(0, 30, ['reply' => '{"claims": []}']));
    $this->command()->aiEval();
    $this->assertSame(30, $this->budget());
  }

  /**
   * Tests that the budget never passes two hundred.
   */
  public function testDefaultBudgetIsCappedAtTwoHundred(): void {
    $this->configureAi(['max_retries' => 7]);
    $this->script(array_fill(0, 30, ['reply' => '{"claims": []}']));
    $this->command()->aiEval();
    $this->assertSame(200, $this->budget());
  }

  /**
   * Tests that max-calls replaces the default and stops the run.
   */
  public function testMaxCallsReplacesTheDefault(): void {
    $this->script(array_fill(0, 30, ['reply' => '{"claims": []}']));
    $rows = $this->command()->aiEval(['max-calls' => 4]);
    $this->assertSame(4, $this->budget());
    $this->assertCount(4, $rows->getArrayCopy());
    $this->assertCount(4, $this->calls());
    $this->assertStringContainsString('4 cases', $this->stderr->fetch());
  }

  /**
   * Tests that a value above the cap is cut to it.
   */
  public function testMaxCallsIsCapped(): void {
    $this->script(array_fill(0, 30, ['reply' => '{"claims": []}']));
    $this->command()->aiEval(['max-calls' => 5000]);
    $this->assertSame(200, $this->budget());
  }

  /**
   * Tests that the summary goes to standard error and stdout stays clean.
   */
  public function testSummaryGoesToStandardError(): void {
    $this->script(array_fill(0, 30, ['reply' => '{"claims": []}']));
    $this->command()->aiEval(['format' => 'json']);
    $this->assertSame('', $this->stdout->fetch());
    $summary = $this->stderr->fetch();
    $this->assertStringContainsString('Model scripted, reasoning none, 30 cases: precision 0.000, recall 0.000', $summary);
    $this->assertStringContainsString('rejections: none.', $summary);
  }

  /**
   * Tests the precision and recall of a run, scored on type and value pairs.
   */
  public function testPrecisionAndRecallAreScored(): void {
    $cases = $this->cases();
    $expected = 0;
    foreach ($cases as $case) {
      $expected += count($case['expected']);
    }
    $first = $cases[0];
    $quote = 'open weekdays from eight in the morning until five in the afternoon';
    $this->assertStringContainsString($quote, $first['text']);
    $steps = array_fill(0, 30, ['reply' => '{"claims": []}']);
    $steps[0] = [
      'reply' => json_encode([
        'claims' => [
      ['type' => 'hours', 'quote' => $quote, 'value' => 'Mon-Fri 08:00-17:00'],
      ['type' => 'hours', 'quote' => 'invented words', 'value' => 'Sat 09:00-12:00'],
        ],
      ], JSON_THROW_ON_ERROR),
    ];
    $this->script($steps);
    $rows = $this->command()->aiEval()->getArrayCopy();
    $this->assertSame(1, $rows[0]['matched']);
    $this->assertSame('hours=Mon-Fri 08:00-17:00', $rows[0]['returned']);
    $this->assertSame('ok', $rows[0]['status']);
    $summary = $this->stderr->fetch();
    $this->assertStringContainsString('precision 1.000', $summary);
    $this->assertStringContainsString('recall ' . number_format(1 / $expected, 3, '.', ''), $summary);
    $this->assertStringContainsString('rejections: quote_not_found 1.', $summary);
  }

  /**
   * Tests that the kill switch makes the command refuse and call nothing.
   */
  public function testKillSwitchRefuses(): void {
    $this->killSwitch(TRUE);
    $this->script([['reply' => '{"claims": []}']]);
    $command = $this->command();
    try {
      $command->aiEval();
      $this->fail('The kill switch must stop the evaluation.');
    }
    catch (\RuntimeException $exception) {
      $this->assertStringContainsString('kill switch is on', $exception->getMessage());
    }
    $this->assertSame([], $this->calls());
  }

  /**
   * Tests that no provider makes the command refuse, and the model option.
   */
  public function testUnconfiguredRefusesUnlessModelIsGiven(): void {
    $this->configureAi(['model' => '', 'enabled' => FALSE]);
    $this->script(array_fill(0, 30, ['reply' => '{"claims": []}']));
    try {
      $this->command()->aiEval();
      $this->fail('No model must stop the evaluation.');
    }
    catch (\RuntimeException $exception) {
      $this->assertStringContainsString('No provider and model', $exception->getMessage());
    }
    $this->command()->aiEval(['model' => 'given']);
    $this->assertSame('given', $this->calls()[0]['model']);
    $this->assertStringContainsString('Model given', $this->stderr->fetch());
  }

  /**
   * Tests that the reasoning option is checked and replaces the setting.
   */
  public function testReasoningOption(): void {
    try {
      $this->command()->aiEval(['reasoning' => 'maybe']);
      $this->fail('A bad reasoning value must be refused.');
    }
    catch (\InvalidArgumentException $exception) {
      $this->assertStringContainsString('none or provider_default', $exception->getMessage());
    }
    $this->script(array_fill(0, 30, ['reply' => '{"claims": []}']));
    $this->command()->aiEval(['reasoning' => 'provider_default']);
    $this->assertArrayNotHasKey('reasoning_effort', $this->calls()[0]['configuration']);
    $this->assertStringContainsString('reasoning provider_default', $this->stderr->fetch());
  }

  /**
   * Tests that the evaluation ignores the cache and the allowlist.
   */
  public function testIgnoresCacheAndAllowlist(): void {
    $this->configureAi(['allowlist' => []]);
    $this->script(array_fill(0, 60, ['reply' => '{"claims": []}']));
    $this->command()->aiEval();
    $this->command()->aiEval();
    $this->assertCount(60, $this->calls());
  }

}
