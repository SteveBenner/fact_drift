<?php

namespace Drupal\fact_drift_ai_test\Plugin\AiProvider;

use Drupal\Core\State\StateInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\ai\Attribute\AiProvider;
use Drupal\ai\Base\AiProviderClientBase;
use Drupal\ai\Dto\TokenUsageDto;
use Drupal\ai\OperationType\Chat\ChatInput;
use Drupal\ai\OperationType\Chat\ChatInterface;
use Drupal\ai\OperationType\Chat\ChatMessage;
use Drupal\ai\OperationType\Chat\ChatOutput;
use Drupal\fact_drift_ai_test\Exception\ScriptedResponseException;
use Drupal\fact_drift_ai_test\Exception\ScriptedTransportException;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * A chat provider that plays back a script kept in State.
 *
 * The State key fact_drift_ai_test.script is a list of steps. A step is
 * {reply: string, usage: {input, output}} or {throw: rate_limit, retry_after:
 * int}, {throw: server, status: int}, {throw: timeout}, {throw: refused} or
 * {throw: bad_request}. Each call takes the first step. A call with no step
 * left throws a LogicException. Each call is recorded under the State key
 * fact_drift_ai_test.calls. The State key fact_drift_ai_test.usable, TRUE by
 * default, is isUsable().
 */
#[AiProvider(
  id: 'fact_drift_ai_test',
  label: new TranslatableMarkup('Fact drift test provider'),
)]
class ScriptedProvider extends AiProviderClientBase implements ChatInterface {

  /**
   * The state service.
   */
  protected StateInterface $state;

  /**
   * The Guzzle options the plugin was created with.
   *
   * @var array<string, mixed>
   */
  protected array $httpClientOptions = [];

  /**
   * {@inheritdoc}
   *
   * @param \Symfony\Component\DependencyInjection\ContainerInterface $container
   *   The service container.
   * @param array<string, mixed> $configuration
   *   The plugin configuration.
   * @param string $plugin_id
   *   The plugin ID.
   * @param mixed $plugin_definition
   *   The plugin definition.
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    $instance = parent::create($container, $configuration, $plugin_id, $plugin_definition);
    $instance->state = $container->get('state');
    $instance->httpClientOptions = $configuration['http_client_options'] ?? [];
    return $instance;
  }

  /**
   * {@inheritdoc}
   *
   * @param string $model_id
   *   The model ID.
   * @param array<string, mixed> $generalConfig
   *   The general configuration.
   *
   * @return array<string, mixed>
   *   The settings.
   */
  public function getModelSettings(string $model_id, array $generalConfig = []): array {
    return $generalConfig;
  }

  /**
   * {@inheritdoc}
   *
   * @param string|null $operation_type
   *   The operation type.
   * @param array<int, string> $capabilities
   *   The capabilities.
   *
   * @return array<string, string>
   *   The models.
   */
  public function getConfiguredModels(?string $operation_type = NULL, array $capabilities = []): array {
    return ['scripted' => 'Scripted'];
  }

  /**
   * {@inheritdoc}
   *
   * @param string|null $operation_type
   *   The operation type.
   * @param array<int, string> $capabilities
   *   The capabilities.
   */
  public function isUsable(?string $operation_type = NULL, array $capabilities = []): bool {
    return (bool) $this->state->get('fact_drift_ai_test.usable', TRUE);
  }

  /**
   * {@inheritdoc}
   */
  public function getSupportedOperationTypes(): array {
    return ['chat'];
  }

  /**
   * {@inheritdoc}
   */
  public function setAuthentication(mixed $authentication): void {
  }

  /**
   * {@inheritdoc}
   *
   * @param array<mixed>|string|\Drupal\ai\OperationType\Chat\ChatInput $input
   *   The input.
   * @param string $model_id
   *   The model ID.
   * @param array<int, string> $tags
   *   The tags.
   */
  public function chat(array|string|ChatInput $input, string $model_id, array $tags = []): ChatOutput {
    $calls = $this->state->get('fact_drift_ai_test.calls', []);
    $calls[] = [
      'system' => $input instanceof ChatInput ? $input->getSystemPrompt() : '',
      'user' => $input instanceof ChatInput ? $input->getMessages()[0]->getText() : (string) $input,
      'schema' => $input instanceof ChatInput ? $input->getChatStructuredJsonSchema() : [],
      'full_trust' => $input instanceof ChatInput ? $input->getHostnameFilter()?->fullTrust : NULL,
      'tags' => $this->getTags(),
      'model' => $model_id,
      'configuration' => $this->getConfiguration(),
      'http_client_options' => $this->httpClientOptions,
    ];
    $this->state->set('fact_drift_ai_test.calls', $calls);

    $script = $this->state->get('fact_drift_ai_test.script', []);
    if (!$script) {
      throw new \LogicException('The fake provider was called with no scripted step left.');
    }
    $step = array_shift($script);
    $this->state->set('fact_drift_ai_test.script', $script);

    if (isset($step['throw'])) {
      throw $this->failure($step);
    }
    $usage = new TokenUsageDto(input: $step['usage']['input'] ?? NULL, output: $step['usage']['output'] ?? NULL);
    return new ChatOutput(new ChatMessage('assistant', $step['reply']), [], [], $usage);
  }

  /**
   * Builds the exception a step asks for.
   *
   * @param array<string, mixed> $step
   *   The step.
   *
   * @return \Throwable
   *   An exception shaped like the one the real transport throws.
   */
  protected function failure(array $step): \Throwable {
    switch ($step['throw']) {
      case 'rate_limit':
        $headers = isset($step['retry_after']) ? ['Retry-After' => (string) $step['retry_after']] : [];
        return new ScriptedResponseException('Rate limit', new Response(429, $headers));

      case 'server':
        return new ScriptedResponseException('Server error', new Response($step['status'] ?? 503));

      case 'bad_request':
        return new ScriptedResponseException('Bad request', new Response(400));

      case 'timeout':
        return new ScriptedTransportException('Transport failed', 0, new ConnectException('cURL error 28: Operation timed out', new Request('POST', 'http://localhost/v1/chat/completions')));

      case 'refused':
        return new ScriptedTransportException('Transport failed', 0, new ConnectException('cURL error 7: Failed to connect', new Request('POST', 'http://localhost/v1/chat/completions')));
    }
    return new \LogicException('Unknown scripted failure.');
  }

}
