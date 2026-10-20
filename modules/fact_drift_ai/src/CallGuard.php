<?php

namespace Drupal\fact_drift_ai;

use Drupal\Component\Plugin\Factory\FactoryInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\ImmutableConfig;
use Drupal\Core\DestructableInterface;
use Drupal\ai\Exception\AiRateLimitException;
use Drupal\ai\OperationType\Chat\ChatInput;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Exception\TransferException;
use Psr\Http\Message\ResponseInterface;
use Psr\Log\LoggerInterface;

/**
 * Puts the kill switch, limits, retries and circuit breaker around every call.
 *
 * One instance serves one PHP process, so its counters describe one run: a
 * Drush command or a cron run. Nothing it logs contains page text, a quote or
 * a reply.
 */
class CallGuard implements DestructableInterface {

  /**
   * The seconds a field is deferred when the circuit breaker is open.
   */
  public const OUTAGE_DELAY = 900;

  /**
   * The seconds a field is deferred when the budget is spent.
   */
  public const BUDGET_DELAY = 3600;

  /**
   * The seconds a field is deferred after a 429 without a Retry-After header.
   */
  public const RATE_LIMIT_DELAY = 60;

  /**
   * The longest backoff before a retry, in seconds.
   */
  public const MAX_BACKOFF = 30;

  /**
   * The counters of the run, in the order they are shown.
   *
   * @var array<string, int>
   */
  protected array $counters = [
    'calls' => 0,
    'cache_hits' => 0,
    'retries' => 0,
    'deferred_budget' => 0,
    'deferred_rate_limited' => 0,
    'deferred_outage' => 0,
    'held' => 0,
  ];

  /**
   * The rejections of the run by reason.
   *
   * @var array<string, int>
   */
  protected array $rejections = [];

  /**
   * The prompt tokens the provider reported.
   */
  protected int $inputTokens = 0;

  /**
   * The completion tokens the provider reported.
   */
  protected int $outputTokens = 0;

  /**
   * Whether the circuit breaker is open.
   */
  protected bool $breakerOpen = FALSE;

  /**
   * The delay of the 429 that stopped further calls, or NULL.
   */
  protected ?int $rateLimitDelay = NULL;

  /**
   * The monotonic time the last call started, in nanoseconds, or NULL.
   */
  protected ?int $lastStart = NULL;

  /**
   * The function that waits for a number of microseconds.
   */
  protected \Closure $sleeper;

  /**
   * The budget that replaces max_calls_per_run, or NULL.
   */
  protected ?int $budget = NULL;

  /**
   * The model ID that replaces the configured one, or NULL.
   */
  protected ?string $model = NULL;

  /**
   * The reasoning setting that replaces the configured one, or NULL.
   */
  protected ?string $reasoning = NULL;

  /**
   * Whether the enabled setting is part of the gate.
   */
  protected bool $enabledRequired = TRUE;

  /**
   * The provider instances created in this process, by configuration.
   *
   * @var array<string, object|null>
   */
  protected array $providers = [];

  /**
   * Constructs a CallGuard.
   *
   * @param \Drupal\Component\Plugin\Factory\FactoryInterface $providerManager
   *   The ai module's provider plugin manager, which creates provider proxies.
   * @param \Drupal\Core\Config\ConfigFactoryInterface $configFactory
   *   The config factory.
   * @param \Psr\Log\LoggerInterface $logger
   *   The fact_drift_ai logger channel.
   */
  public function __construct(
    protected FactoryInterface $providerManager,
    protected ConfigFactoryInterface $configFactory,
    protected LoggerInterface $logger,
  ) {
    $this->sleeper = usleep(...);
  }

  /**
   * Replaces the function that waits.
   *
   * @param callable $sleeper
   *   A function taking a number of microseconds.
   */
  public function setSleeper(callable $sleeper): void {
    $this->sleeper = $sleeper(...);
  }

  /**
   * Replaces max_calls_per_run for this process.
   *
   * @param int $budget
   *   The most calls, counting retries.
   */
  public function setBudget(int $budget): void {
    $this->budget = $budget;
  }

  /**
   * Replaces the configured model for this process.
   *
   * @param string|null $model
   *   The model ID, or NULL to use the configured one.
   */
  public function setModel(?string $model): void {
    $this->model = $model;
  }

  /**
   * Replaces the configured reasoning setting for this process.
   *
   * @param string|null $reasoning
   *   none or provider_default, or NULL to use the configured one.
   */
  public function setReasoning(?string $reasoning): void {
    $this->reasoning = $reasoning;
  }

  /**
   * Sets whether the enabled setting is part of the gate.
   *
   * @param bool $required
   *   FALSE for a run that is not a scan, such as the evaluation.
   */
  public function setEnabledRequired(bool $required): void {
    $this->enabledRequired = $required;
  }

  /**
   * Computes the longest wait before a retry, in microseconds.
   *
   * @param int $retry
   *   The retry number, starting at 0.
   *
   * @return int
   *   1 second for the first retry, doubling, never more than 30 seconds.
   */
  public static function backoffCeiling(int $retry): int {
    return min(self::MAX_BACKOFF, 2 ** max(0, $retry)) * 1000000;
  }

  /**
   * Picks the wait before a retry, with full jitter.
   *
   * @param int $retry
   *   The retry number, starting at 0.
   *
   * @return int
   *   A uniform random number of microseconds between 0 and the ceiling.
   */
  public static function backoffMicroseconds(int $retry): int {
    return random_int(0, self::backoffCeiling($retry));
  }

  /**
   * Tells whether the kill switch is on.
   *
   * @return bool
   *   TRUE when the kill switch of the base module is on.
   */
  public function killSwitchOn(): bool {
    return (bool) $this->configFactory->get('fact_drift.settings')->get('kill_switch');
  }

  /**
   * Lists the side paths that are open.
   *
   * @return string[]
   *   The settings to change, as config:key, for each ai submodule that
   *   would store or forward what is sent.
   */
  public function sidePaths(): array {
    $open = [];
    $logging = $this->configFactory->get('ai_logging.settings');
    if ($logging->get('prompt_logging') && !$this->tagExcluded($logging->get('prompt_logging_excluded_tags'))) {
      $open[] = 'ai_logging.settings:prompt_logging';
    }
    if ($this->configFactory->get('ai_observability.settings')->get('log_input')) {
      $open[] = 'ai_observability.settings:log_input';
    }
    if ($this->configFactory->get('ai.external_moderation')->get('moderations')) {
      $open[] = 'ai.external_moderation:moderations';
    }
    return $open;
  }

  /**
   * Tells whether the provider and model are set and the provider is usable.
   *
   * @return bool
   *   TRUE when a call could be made, as far as configuration shows.
   */
  public function isConfigured(): bool {
    if ($this->modelId() === '') {
      return FALSE;
    }
    $provider = $this->provider();
    if ($provider === NULL) {
      return FALSE;
    }
    try {
      return (bool) $this->invoke($provider, 'isUsable', ['chat']);
    }
    catch (\Exception) {
      return FALSE;
    }
  }

  /**
   * Tells why no call may be made, if so.
   *
   * @return string|null
   *   kill_switch, unconfigured or side_path, or NULL when a call may be made.
   */
  public function gate(): ?string {
    if ($this->killSwitchOn() || ($this->enabledRequired && !$this->settings()->get('enabled'))) {
      return 'kill_switch';
    }
    if (!$this->isConfigured()) {
      return 'unconfigured';
    }
    if ($this->sidePaths()) {
      return 'side_path';
    }
    return NULL;
  }

  /**
   * Makes one guarded call.
   *
   * @param \Drupal\ai\OperationType\Chat\ChatInput $input
   *   The chat input.
   *
   * @return \Drupal\fact_drift_ai\CallResult
   *   The reply, or a signal: held for the kill switch, no usable provider or
   *   an open side path; deferred for a spent budget, a rate limit or an
   *   outage; rejected for any other provider error.
   */
  public function call(ChatInput $input): CallResult {
    $reason = $this->gate();
    if ($reason !== NULL) {
      $this->counters['held']++;
      return new CallResult(signal: CallResult::HELD, reason: $reason);
    }
    $retry = 0;
    while (TRUE) {
      if ($this->rateLimitDelay !== NULL) {
        $this->counters['deferred_rate_limited']++;
        return new CallResult(signal: CallResult::DEFERRED, delay: $this->rateLimitDelay, reason: 'rate_limited');
      }
      if ($this->breakerOpen) {
        $this->counters['deferred_outage']++;
        return new CallResult(signal: CallResult::DEFERRED, delay: self::OUTAGE_DELAY, reason: 'outage');
      }
      if ($this->counters['calls'] >= $this->budget()) {
        $this->counters['deferred_budget']++;
        return new CallResult(signal: CallResult::DEFERRED, delay: self::BUDGET_DELAY, reason: 'budget');
      }
      $provider = $this->provider();
      if ($provider === NULL) {
        $this->counters['held']++;
        return new CallResult(signal: CallResult::HELD, reason: 'unconfigured');
      }
      $this->waitForInterval();
      $this->counters['calls']++;
      try {
        $output = $this->invoke($provider, 'chat', [$input, $this->modelId(), [Prompt::TAG]]);
        $usage = $output->getTokenUsage();
        $this->inputTokens += (int) $usage->input;
        $this->outputTokens += (int) $usage->output;
        return new CallResult(
          text: $output->getNormalized()->getText(),
          inputTokens: $usage->input,
          outputTokens: $usage->output,
        );
      }
      catch (\Throwable $exception) {
        $failure = $this->classify($exception);
      }
      if ($failure['class'] === 'rate_limited') {
        $this->rateLimitDelay = $failure['delay'];
        continue;
      }
      if ($failure['class'] === 'retryable' && $retry < $this->maxRetries()) {
        $this->counters['retries']++;
        ($this->sleeper)(self::backoffMicroseconds($retry));
        $retry++;
        continue;
      }
      if ($failure['class'] === 'retryable' || $failure['class'] === 'outage') {
        $this->breakerOpen = TRUE;
        continue;
      }
      $this->rejections['provider_error'] = ($this->rejections['provider_error'] ?? 0) + 1;
      return new CallResult(signal: CallResult::REJECTED, reason: 'provider_error');
    }
  }

  /**
   * Counts a result served from the cache.
   */
  public function countCacheHit(): void {
    $this->counters['cache_hits']++;
  }

  /**
   * Counts a field held before any call was tried.
   */
  public function countHold(): void {
    $this->counters['held']++;
  }

  /**
   * Counts rejections of a reply or of its claims.
   *
   * @param array<string, int> $rejections
   *   The number of rejections by reason.
   */
  public function countRejections(array $rejections): void {
    foreach ($rejections as $reason => $count) {
      $this->rejections[$reason] = ($this->rejections[$reason] ?? 0) + $count;
    }
  }

  /**
   * Reports the counters of this process.
   *
   * @return array<string, int>
   *   The counters by name: calls, cache_hits, retries, the deferrals by
   *   reason, held, one rejected_<reason> per rejection reason seen, and the
   *   tokens the provider reported.
   */
  public function runSummary(): array {
    $summary = $this->counters;
    ksort($this->rejections);
    foreach ($this->rejections as $reason => $count) {
      $summary['rejected_' . $reason] = $count;
    }
    $summary['input_tokens'] = $this->inputTokens;
    $summary['output_tokens'] = $this->outputTokens;
    return $summary;
  }

  /**
   * {@inheritdoc}
   */
  public function destruct(): void {
    $deferrals = $this->counters['deferred_budget'] + $this->counters['deferred_rate_limited'] + $this->counters['deferred_outage'];
    if ($this->counters['calls'] + $this->counters['cache_hits'] + $deferrals + $this->counters['held'] === 0) {
      return;
    }
    $pairs = [];
    foreach ($this->runSummary() as $name => $value) {
      if ($value !== 0 || array_key_exists($name, $this->counters)) {
        $pairs[] = $name . ' ' . $value;
      }
    }
    $this->logger->info('Run summary: @summary', ['@summary' => implode(', ', $pairs)]);
  }

  /**
   * Gets the settings of the submodule.
   *
   * @return \Drupal\Core\Config\ImmutableConfig
   *   The fact_drift_ai.settings config.
   */
  protected function settings(): ImmutableConfig {
    return $this->configFactory->get('fact_drift_ai.settings');
  }

  /**
   * Gets the model ID in use.
   *
   * @return string
   *   The override, else the configured model, else an empty string.
   */
  protected function modelId(): string {
    return $this->model ?? (string) $this->settings()->get('model');
  }

  /**
   * Gets the most calls allowed in this process.
   *
   * @return int
   *   The override, else max_calls_per_run.
   */
  protected function budget(): int {
    return $this->budget ?? (int) $this->settings()->get('max_calls_per_run');
  }

  /**
   * Gets the most retries of one call.
   *
   * @return int
   *   The max_retries setting.
   */
  protected function maxRetries(): int {
    return max(0, (int) $this->settings()->get('max_retries'));
  }

  /**
   * Tells whether the fact_drift_ai tag is excluded from prompt logging.
   *
   * @param mixed $excluded
   *   The excluded tags: a comma-separated string or a list.
   *
   * @return bool
   *   TRUE when the tag is excluded.
   */
  protected function tagExcluded(mixed $excluded): bool {
    $tags = is_array($excluded) ? $excluded : explode(',', (string) $excluded);
    foreach ($tags as $tag) {
      if (strtolower(trim((string) $tag)) === Prompt::TAG) {
        return TRUE;
      }
    }
    return FALSE;
  }

  /**
   * Gets the provider instance for this process, creating it once.
   *
   * The instance gets the call timeout as its request and connect timeout,
   * and reasoning_effort none when the reasoning setting says none.
   *
   * @return object|null
   *   The provider proxy, or NULL when there is no such provider.
   */
  protected function provider(): ?object {
    $id = (string) $this->settings()->get('provider');
    if ($id === '') {
      return NULL;
    }
    $timeout = max(1, (int) $this->settings()->get('timeout_seconds'));
    $reasoning = $this->reasoning ?? (string) $this->settings()->get('reasoning');
    $key = $id . '|' . $timeout . '|' . $reasoning;
    if (!array_key_exists($key, $this->providers)) {
      try {
        $provider = $this->providerManager->createInstance($id, [
          'http_client_options' => ['timeout' => $timeout, 'connect_timeout' => $timeout],
        ]);
        if ($reasoning === 'none') {
          $this->invoke($provider, 'setConfiguration', [$this->invoke($provider, 'getConfiguration') + ['reasoning_effort' => 'none']]);
        }
        $this->providers[$key] = $provider;
      }
      catch (\Exception) {
        $this->providers[$key] = NULL;
      }
    }
    return $this->providers[$key];
  }

  /**
   * Calls a method of the provider plugin through its proxy.
   *
   * The proxy forwards unknown methods to the plugin and wraps the operation
   * methods, such as chat(), in the ai module's events.
   *
   * @param object $provider
   *   The provider proxy.
   * @param string $method
   *   The method name.
   * @param array<int, mixed> $arguments
   *   The arguments.
   *
   * @return mixed
   *   What the method returns.
   */
  protected function invoke(object $provider, string $method, array $arguments = []): mixed {
    return call_user_func_array([$provider, $method], $arguments);
  }

  /**
   * Waits until min_interval_ms has passed since the last call started.
   */
  protected function waitForInterval(): void {
    $interval = max(0, (int) $this->settings()->get('min_interval_ms')) * 1000000;
    $now = (int) hrtime(TRUE);
    if ($this->lastStart !== NULL && $interval > 0) {
      $remaining = $interval - ($now - $this->lastStart);
      if ($remaining > 0) {
        ($this->sleeper)((int) ceil($remaining / 1000));
        $now = (int) hrtime(TRUE);
      }
    }
    $this->lastStart = $now;
  }

  /**
   * Classifies a failed call.
   *
   * @param \Throwable $exception
   *   The exception the provider threw.
   *
   * @return array{class: string, delay: int}
   *   The class: rate_limited (a 429, with the delay in seconds), retryable
   *   (a timeout or a 5xx), outage (any other connection failure) or other.
   */
  protected function classify(\Throwable $exception): array {
    if ($exception instanceof AiRateLimitException) {
      return ['class' => 'rate_limited', 'delay' => self::RATE_LIMIT_DELAY];
    }
    for ($link = $exception; $link !== NULL; $link = $link->getPrevious()) {
      $response = $this->responseOf($link);
      if ($response === NULL) {
        continue;
      }
      $status = $response->getStatusCode();
      if ($status === 429) {
        return ['class' => 'rate_limited', 'delay' => $this->retryAfter($response)];
      }
      if ($status >= 500 && $status <= 599) {
        return ['class' => 'retryable', 'delay' => 0];
      }
      return ['class' => 'other', 'delay' => 0];
    }
    for ($link = $exception; $link !== NULL; $link = $link->getPrevious()) {
      if ($link instanceof TransferException) {
        return ['class' => $this->isTimeout($link) ? 'retryable' : 'outage', 'delay' => 0];
      }
    }
    return ['class' => 'other', 'delay' => 0];
  }

  /**
   * Gets the PSR response an exception carries, if any.
   *
   * @param \Throwable $exception
   *   The exception.
   *
   * @return \Psr\Http\Message\ResponseInterface|null
   *   The response from a public response property or a getResponse() method.
   */
  protected function responseOf(\Throwable $exception): ?ResponseInterface {
    $response = get_object_vars($exception)['response'] ?? NULL;
    if ($response === NULL && method_exists($exception, 'getResponse')) {
      $response = $exception->getResponse();
    }
    return $response instanceof ResponseInterface ? $response : NULL;
  }

  /**
   * Reads the delay a 429 asks for.
   *
   * @param \Psr\Http\Message\ResponseInterface $response
   *   The response.
   *
   * @return int
   *   The seconds of the Retry-After header, at least 1, or 60 when absent or
   *   not a number of seconds.
   */
  protected function retryAfter(ResponseInterface $response): int {
    $header = trim($response->getHeaderLine('Retry-After'));
    return ctype_digit($header) ? max(1, (int) $header) : self::RATE_LIMIT_DELAY;
  }

  /**
   * Tells whether a transport exception reports a timeout.
   *
   * @param \GuzzleHttp\Exception\TransferException $exception
   *   The Guzzle exception.
   *
   * @return bool
   *   TRUE for cURL error 28 or a message naming a timeout.
   */
  protected function isTimeout(TransferException $exception): bool {
    if ($exception instanceof ConnectException && ($exception->getHandlerContext()['errno'] ?? NULL) === 28) {
      return TRUE;
    }
    return (bool) preg_match('/cURL error 28|timed out/i', $exception->getMessage());
  }

}
