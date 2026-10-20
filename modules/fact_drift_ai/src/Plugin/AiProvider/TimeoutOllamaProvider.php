<?php

namespace Drupal\fact_drift_ai\Plugin\AiProvider;

use Drupal\ai_provider_ollama\Plugin\AiProvider\OllamaProvider;
use GuzzleHttp\Client as GuzzleClient;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * The Ollama provider, honouring the http_client_options it is created with.
 *
 * The ai_provider_ollama module builds its client with a fixed 600 second
 * timeout and ignores the options. This class keeps 600 seconds unless options
 * are given.
 * It is swapped in for the ollama plugin by FactDriftAiHooks and has no
 * AiProvider attribute of its own.
 */
class TimeoutOllamaProvider extends OllamaProvider {

  /**
   * The Guzzle options the plugin was created with.
   *
   * @var array<string, mixed>
   */
  protected array $httpClientOptions = [];

  /**
   * Creates the plugin and remembers the HTTP client options.
   *
   * @param \Symfony\Component\DependencyInjection\ContainerInterface $container
   *   The service container.
   * @param array<string, mixed> $configuration
   *   The plugin configuration, which may carry http_client_options.
   * @param string $plugin_id
   *   The plugin ID.
   * @param mixed $plugin_definition
   *   The plugin definition.
   *
   * @return static
   *   The plugin.
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    $instance = parent::create($container, $configuration, $plugin_id, $plugin_definition);
    $instance->httpClientOptions = $configuration['http_client_options'] ?? [];
    return $instance;
  }

  /**
   * {@inheritdoc}
   */
  protected function loadClient(): void {
    if (empty($this->client)) {
      $host = $this->getBaseHost();
      $this->setEndpoint($host . '/v1');
      $this->setHttpClient(new GuzzleClient($this->httpClientOptions + ['timeout' => 600]));
      $this->client = $this->createClient();
    }
  }

}
