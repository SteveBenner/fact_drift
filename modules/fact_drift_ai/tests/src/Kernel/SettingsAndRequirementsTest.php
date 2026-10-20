<?php

namespace Drupal\Tests\fact_drift_ai\Kernel;

use Drupal\fact_drift_ai\Hook\FactDriftAiHooks;
use Drupal\fact_drift_ai\Plugin\AiProvider\TimeoutOllamaProvider;
use Drupal\Tests\SchemaCheckTestTrait;

/**
 * Tests the default settings' schema, the provider alter and the requirement.
 *
 * @group fact_drift_ai
 * @covers \Drupal\fact_drift_ai\Hook\FactDriftAiHooks
 */
class SettingsAndRequirementsTest extends AiKernelTestBase {

  use SchemaCheckTestTrait;

  /**
   * Tests that the default settings match the schema and carry every key.
   */
  public function testDefaultSettingsValidate(): void {
    $this->config('fact_drift_ai.settings')->delete();
    $this->installConfig(['fact_drift_ai']);
    $data = $this->config('fact_drift_ai.settings')->get();
    unset($data['_core']);
    $this->assertSame([
      'allowlist',
      'enabled',
      'max_calls_per_run',
      'max_chars',
      'max_retries',
      'min_interval_ms',
      'mode',
      'model',
      'prefilter',
      'provider',
      'reasoning',
      'timeout_seconds',
    ], array_keys($this->sorted($data)));
    $this->assertFalse($data['enabled']);
    $this->assertSame('fallback', $data['mode']);
    $this->assertSame('none', $data['reasoning']);
    $this->assertSame([], $data['allowlist']);
    $this->assertTrue($this->checkConfigSchema($this->container->get('config.typed'), 'fact_drift_ai.settings', $data));
  }

  /**
   * Sorts a map by key.
   *
   * @param array<string, mixed> $data
   *   The map.
   *
   * @return array<string, mixed>
   *   The map, sorted.
   */
  protected function sorted(array $data): array {
    ksort($data);
    return $data;
  }

  /**
   * Tests that a value of the wrong type fails the schema.
   */
  public function testWrongTypesFailTheSchema(): void {
    $data = $this->config('fact_drift_ai.settings')->get();
    foreach (['max_chars' => 'big', 'enabled' => 'sometimes', 'allowlist' => 'body', 'timeout_seconds' => []] as $key => $value) {
      $broken = [$key => $value] + $data;
      $this->assertNotTrue($this->checkConfigSchema($this->container->get('config.typed'), 'fact_drift_ai.settings', $broken), $key);
    }
  }

  /**
   * Tests that a saved allowlist matches the schema.
   */
  public function testAllowlistValidates(): void {
    $this->configureAi([
      'allowlist' => [
      ['bundle' => 'page', 'field' => 'body'],
      ['bundle' => 'article', 'field' => 'body'],
      ],
    ]);
    $this->assertTrue($this->checkConfigSchema($this->container->get('config.typed'), 'fact_drift_ai.settings', $this->config('fact_drift_ai.settings')->get()));
  }

  /**
   * Tests that the hook reaches the plugin manager's alter.
   */
  public function testProviderAlterIsInvokedThroughTheModuleHandler(): void {
    $definitions = [
      'ollama' => ['id' => 'ollama', 'class' => FactDriftAiHooks::OLLAMA_CLASS],
      'other' => ['id' => 'other', 'class' => 'Drupal\\other\\Provider'],
    ];
    $this->container->get('module_handler')->alter('ai_provider_info', $definitions);
    $this->assertSame(TimeoutOllamaProvider::class, $definitions['ollama']['class']);
    $this->assertSame('Drupal\\other\\Provider', $definitions['other']['class']);
  }

  /**
   * Tests that the status report says nothing while the extractor is off.
   */
  public function testRequirementIsAbsentWhileDisabled(): void {
    $this->configureAi(['enabled' => FALSE]);
    $this->overrideConfig('ai_observability.settings', ['log_input' => TRUE]);
    $this->assertSame([], $this->requirements());
  }

  /**
   * Tests that the status report is OK when no side path is open.
   */
  public function testRequirementIsOkWhenQuiet(): void {
    $requirements = $this->requirements();
    $this->assertSame(0, $requirements['fact_drift_ai_side_paths']['severity']);
  }

  /**
   * Tests that each open side path is a warning naming the setting.
   */
  public function testRequirementWarnsAndNamesTheSetting(): void {
    $this->overrideConfig('ai_logging.settings', ['prompt_logging' => TRUE]);
    $this->overrideConfig('ai_observability.settings', ['log_input' => TRUE]);
    $requirement = $this->requirements()['fact_drift_ai_side_paths'];
    $this->assertSame(1, $requirement['severity']);
    $this->assertStringContainsString('ai_logging.settings:prompt_logging', (string) $requirement['description']);
    $this->assertStringContainsString('ai_observability.settings:log_input', (string) $requirement['description']);

    $this->overrideConfig('ai_logging.settings', [
      'prompt_logging' => TRUE,
      'prompt_logging_excluded_tags' => ['fact_drift_ai'],
    ]);
    $this->overrideConfig('ai_observability.settings', []);
    $this->assertSame(0, $this->requirements()['fact_drift_ai_side_paths']['severity']);
  }

  /**
   * Gets the runtime requirements through the module's install file.
   *
   * @return array<string, array<string, mixed>>
   *   The requirements.
   */
  protected function requirements(): array {
    $this->container->get('module_handler')->loadInclude('fact_drift_ai', 'install');
    $this->assertSame([], fact_drift_ai_requirements('install'));
    return fact_drift_ai_requirements('runtime');
  }

}
