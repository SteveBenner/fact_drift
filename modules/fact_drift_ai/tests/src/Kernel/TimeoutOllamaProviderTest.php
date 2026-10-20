<?php

namespace Drupal\Tests\fact_drift_ai\Kernel;

use Drupal\fact_drift_ai\Plugin\AiProvider\TimeoutOllamaProvider;
use Drupal\KernelTests\KernelTestBase;

/**
 * Tests that the ollama provider is swapped for one that honours timeouts.
 *
 * Nothing here opens a connection: the Guzzle client is built and inspected.
 *
 * @group fact_drift_ai
 * @covers \Drupal\fact_drift_ai\Plugin\AiProvider\TimeoutOllamaProvider
 * @covers \Drupal\fact_drift_ai\Hook\FactDriftAiHooks::aiProviderInfoAlter
 */
class TimeoutOllamaProviderTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'user',
    'node',
    'field',
    'text',
    'filter',
    'options',
    'file',
    'key',
    'ai',
    'ai_provider_ollama',
    'fact_drift',
    'fact_drift_ai',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    if (!class_exists('Drupal\ai_provider_ollama\Plugin\AiProvider\OllamaProvider')) {
      $this->markTestSkipped('The ai_provider_ollama module is not available.');
    }
    parent::setUp();
    $this->installEntitySchema('user');
    $this->installConfig(['ai', 'ai_provider_ollama']);
    $this->config('ai_provider_ollama.settings')->set('host_name', 'http://127.0.0.1')->set('port', 9)->save();
  }

  /**
   * Builds the provider's client and reads its timeout.
   *
   * @param array<string, mixed> $configuration
   *   The configuration to create the provider with.
   *
   * @return mixed
   *   The Guzzle timeout option.
   */
  protected function timeoutOf(array $configuration): mixed {
    $proxy = $this->container->get('ai.provider')->createInstance('ollama', $configuration);
    $plugin = $proxy->getPlugin();
    $this->assertInstanceOf(TimeoutOllamaProvider::class, $plugin);
    $load = new \ReflectionMethod($plugin, 'loadClient');
    $load->invoke($plugin);
    $get = new \ReflectionMethod($plugin, 'getHttpClient');
    $client = $get->invoke($plugin);
    return $client->getConfig('timeout');
  }

  /**
   * Tests that the ollama plugin class is the timeout subclass.
   */
  public function testOllamaDefinitionUsesTheTimeoutClass(): void {
    $definition = $this->container->get('ai.provider')->getDefinition('ollama');
    $this->assertSame(TimeoutOllamaProvider::class, $definition['class']);
    $this->assertSame('ollama', $definition['id']);
  }

  /**
   * Tests that the client honours a three second timeout.
   */
  public function testClientHonoursTheGivenTimeout(): void {
    $this->assertSame(3, $this->timeoutOf(['http_client_options' => ['timeout' => 3, 'connect_timeout' => 3]]));
  }

  /**
   * Tests that the client keeps the provider's 600 seconds without options.
   */
  public function testClientKeepsSixHundredSecondsWithoutOptions(): void {
    $this->assertSame(600, $this->timeoutOf([]));
  }

  /**
   * Tests that the connect timeout reaches the client too.
   */
  public function testConnectTimeoutReachesTheClient(): void {
    $options = ['timeout' => 4, 'connect_timeout' => 2];
    $proxy = $this->container->get('ai.provider')->createInstance('ollama', ['http_client_options' => $options]);
    $plugin = $proxy->getPlugin();
    $load = new \ReflectionMethod($plugin, 'loadClient');
    $load->invoke($plugin);
    $get = new \ReflectionMethod($plugin, 'getHttpClient');
    $this->assertSame(2, $get->invoke($plugin)->getConfig('connect_timeout'));
  }

}
