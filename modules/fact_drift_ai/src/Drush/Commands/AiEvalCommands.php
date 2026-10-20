<?php

namespace Drupal\fact_drift_ai\Drush\Commands;

use Consolidation\OutputFormatters\StructuredData\RowsOfFields;
use Drupal\Component\Serialization\Yaml;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Extension\ExtensionPathResolver;
use Drupal\fact_drift_ai\CallGuard;
use Drupal\fact_drift_ai\CallResult;
use Drupal\fact_drift_ai\Prompt;
use Drupal\fact_drift_ai\ResponseValidator;
use Drush\Attributes as CLI;
use Drush\Commands\DrushCommands;
use Symfony\Component\Console\Output\ConsoleOutputInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Drush command that measures how well a model extracts the facts.
 */
final class AiEvalCommands extends DrushCommands {

  /**
   * The claim types every case asks for.
   */
  public const TYPES = ['phone', 'hours'];

  /**
   * The most calls an evaluation may make.
   */
  public const MAX_CALLS = 200;

  /**
   * Constructs the commands.
   *
   * @param \Drupal\fact_drift_ai\CallGuard $guard
   *   The call guard.
   * @param \Drupal\fact_drift_ai\Prompt $prompt
   *   The prompt.
   * @param \Drupal\fact_drift_ai\ResponseValidator $validator
   *   The response validator.
   * @param \Drupal\Core\Config\ConfigFactoryInterface $configFactory
   *   The config factory.
   * @param \Drupal\Core\Extension\ExtensionPathResolver $pathResolver
   *   The extension path resolver, which locates the fixture.
   * @param string $appRoot
   *   The Drupal root directory.
   */
  public function __construct(
    protected CallGuard $guard,
    protected Prompt $prompt,
    protected ResponseValidator $validator,
    protected ConfigFactoryInterface $configFactory,
    protected ExtensionPathResolver $pathResolver,
    protected string $appRoot,
  ) {
    parent::__construct();
  }

  /**
   * Creates the commands from the container.
   *
   * @param \Symfony\Component\DependencyInjection\ContainerInterface $container
   *   The service container.
   *
   * @return static
   *   The commands.
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('fact_drift_ai.call_guard'),
      $container->get('fact_drift_ai.prompt'),
      $container->get('fact_drift_ai.response_validator'),
      $container->get('config.factory'),
      $container->get('extension.path.resolver'),
      (string) $container->getParameter('app.root'),
    );
  }

  /**
   * Runs the labelled sentences through the model and scores the claims.
   *
   * Every case goes through the same prompt, validation and call guard as a
   * scan, bypassing the cache and the allowlist. Precision and recall are
   * counted on (type, value) pairs.
   *
   * @param array<string, mixed> $options
   *   The command options.
   *
   * @return \Consolidation\OutputFormatters\StructuredData\RowsOfFields
   *   One row per case.
   */
  #[CLI\Command(name: 'fact-drift:ai-eval')]
  #[CLI\Option(name: 'model', description: 'Use this model ID instead of the configured one, for this run only.')]
  #[CLI\Option(name: 'reasoning', description: 'none or provider_default; replaces the configured setting for this run only.')]
  #[CLI\Option(name: 'max-calls', description: 'The most calls to make; defaults to the number of cases times one plus max_retries, at most 200.')]
  #[CLI\FieldLabels(labels: [
    'id' => 'Case',
    'expected' => 'Expected',
    'returned' => 'Returned',
    'matched' => 'Matched',
    'latency_ms' => 'Latency (ms)',
    'status' => 'Status',
  ])]
  #[CLI\DefaultTableFields(fields: ['id', 'expected', 'returned', 'matched', 'latency_ms', 'status'])]
  #[CLI\Usage(name: 'drush fact-drift:ai-eval --model=qwen3_4b', description: 'Evaluate one model.')]
  public function aiEval(
    array $options = [
      'model' => NULL,
      'reasoning' => NULL,
      'max-calls' => NULL,
      'format' => 'table',
    ],
  ): RowsOfFields {
    $reasoning = ($options['reasoning'] ?? NULL) !== NULL ? (string) $options['reasoning'] : NULL;
    if ($reasoning !== NULL && !in_array($reasoning, ['none', 'provider_default'], TRUE)) {
      throw new \InvalidArgumentException('The reasoning option must be none or provider_default.');
    }
    $model = ($options['model'] ?? NULL) !== NULL ? (string) $options['model'] : NULL;
    $this->guard->setEnabledRequired(FALSE);
    $this->guard->setModel($model);
    $this->guard->setReasoning($reasoning);
    $this->refuseWhenBlocked();
    $cases = $this->cases();
    $retries = max(0, (int) $this->configFactory->get('fact_drift_ai.settings')->get('max_retries'));
    $budget = ($options['max-calls'] ?? NULL) !== NULL ? (int) $options['max-calls'] : count($cases) * (1 + $retries);
    $this->guard->setBudget(max(1, min($budget, self::MAX_CALLS)));

    $rows = [];
    $totals = ['expected' => 0, 'returned' => 0, 'matched' => 0, 'latency' => 0.0, 'calls' => 0];
    $rejections = [];
    foreach ($cases as $case) {
      $expected = $this->pairs($case['expected']);
      $started = hrtime(TRUE);
      $result = $this->guard->call($this->prompt->input($case['text'], self::TYPES));
      $latency = (hrtime(TRUE) - $started) / 1000000;
      if ($result->signal === CallResult::DEFERRED || $result->signal === CallResult::HELD) {
        $this->logger()->warning(sprintf('Stopped before case %s: %s %s.', $case['id'], $result->signal, $result->reason));
        break;
      }
      $returned = [];
      $status = $result->signal ?? 'ok';
      if ($result->text !== NULL) {
        $validated = $this->validator->validate($result->text, $case['text'], self::TYPES);
        $this->guard->countRejections($validated['rejections']);
        foreach ($validated['rejections'] as $reason => $count) {
          $rejections[$reason] = ($rejections[$reason] ?? 0) + $count;
        }
        $returned = $this->pairs($validated['claims']);
      }
      elseif ($result->reason !== NULL) {
        $status = $result->signal . ':' . $result->reason;
        $rejections[$result->reason] = ($rejections[$result->reason] ?? 0) + 1;
      }
      $matched = count(array_intersect($returned, $expected));
      $totals['expected'] += count($expected);
      $totals['returned'] += count($returned);
      $totals['matched'] += $matched;
      $totals['latency'] += $latency;
      $totals['calls']++;
      $rows[] = [
        'id' => $case['id'],
        'expected' => implode('; ', $expected),
        'returned' => implode('; ', $returned),
        'matched' => $matched,
        'latency_ms' => (int) round($latency),
        'status' => $status,
      ];
    }
    $output = $this->output();
    $stderr = $output instanceof ConsoleOutputInterface ? $output->getErrorOutput() : $output;
    $stderr->writeln($this->summary($totals, $rejections, $model, $reasoning));
    return new RowsOfFields($rows);
  }

  /**
   * Refuses to run when the kill switch is on or no model can be called.
   *
   * @throws \RuntimeException
   *   When the kill switch is on, no provider or model is set, the provider
   *   is not usable, or a side path is open.
   */
  protected function refuseWhenBlocked(): void {
    $reason = $this->guard->gate();
    if ($reason === 'kill_switch') {
      throw new \RuntimeException('The kill switch is on; the evaluation makes no call.');
    }
    if ($reason === 'unconfigured') {
      throw new \RuntimeException('No provider and model are configured, or the provider is not usable. Choose one on the AI extractor tab or pass --model.');
    }
    if ($reason !== NULL) {
      throw new \RuntimeException('The evaluation makes no call while another AI submodule would store or forward the prompts: ' . implode(', ', $this->guard->sidePaths()) . '.');
    }
  }

  /**
   * Reads the labelled cases from the fixture.
   *
   * @return list<array{id: string, text: string, expected: list<array{type: string, value: string}>}>
   *   The cases.
   *
   * @throws \RuntimeException
   *   When the fixture cannot be read.
   */
  protected function cases(): array {
    $file = $this->appRoot . '/' . $this->pathResolver->getPath('module', 'fact_drift_ai') . '/tests/fixtures/eval.yml';
    if (!is_readable($file)) {
      throw new \RuntimeException('The evaluation fixture is missing: tests/fixtures/eval.yml.');
    }
    $cases = Yaml::decode((string) file_get_contents($file));
    if (!is_array($cases) || !$cases) {
      throw new \RuntimeException('The evaluation fixture has no cases.');
    }
    return array_values($cases);
  }

  /**
   * Reduces claims to sorted, unique type=value pairs.
   *
   * @param array<int, array<string, mixed>> $claims
   *   Items with a type and a value.
   *
   * @return list<string>
   *   The pairs, as type=value.
   */
  protected function pairs(array $claims): array {
    $pairs = [];
    foreach ($claims as $claim) {
      $pairs[] = $claim['type'] . '=' . $claim['value'];
    }
    $pairs = array_values(array_unique($pairs));
    sort($pairs);
    return $pairs;
  }

  /**
   * Builds the closing summary line.
   *
   * @param array{expected: int, returned: int, matched: int, latency: float, calls: int} $totals
   *   The totals over the cases run.
   * @param array<string, int> $rejections
   *   The rejections by reason.
   * @param string|null $model
   *   The model override, if any.
   * @param string|null $reasoning
   *   The reasoning override, if any.
   *
   * @return string
   *   The summary.
   */
  protected function summary(array $totals, array $rejections, ?string $model, ?string $reasoning): string {
    $settings = $this->configFactory->get('fact_drift_ai.settings');
    $precision = $totals['returned'] > 0 ? $totals['matched'] / $totals['returned'] : 0.0;
    $recall = $totals['expected'] > 0 ? $totals['matched'] / $totals['expected'] : 0.0;
    $latency = $totals['calls'] > 0 ? $totals['latency'] / $totals['calls'] : 0.0;
    ksort($rejections);
    $rejected = [];
    foreach ($rejections as $reason => $count) {
      $rejected[] = $reason . ' ' . $count;
    }
    return sprintf(
      'Model %s, reasoning %s, %d cases: precision %s, recall %s, mean latency %d ms, rejections: %s.',
      $model ?? (string) $settings->get('model'),
      $reasoning ?? (string) $settings->get('reasoning'),
      $totals['calls'],
      number_format($precision, 3, '.', ''),
      number_format($recall, 3, '.', ''),
      (int) round($latency),
      $rejected ? implode(', ', $rejected) : 'none',
    );
  }

}
