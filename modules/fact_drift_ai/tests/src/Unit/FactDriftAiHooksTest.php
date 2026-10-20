<?php

namespace Drupal\Tests\fact_drift_ai\Unit;

use Drupal\fact_drift_ai\CallGuard;
use Drupal\fact_drift_ai\Hook\FactDriftAiHooks;
use Drupal\fact_drift_ai\Plugin\AiProvider\TimeoutOllamaProvider;
use Drupal\Tests\UnitTestCase;

/**
 * Tests the provider alter and the status report requirement.
 *
 * @group fact_drift_ai
 * @covers \Drupal\fact_drift_ai\Hook\FactDriftAiHooks
 */
class FactDriftAiHooksTest extends UnitTestCase {

  /**
   * Builds the hooks over a stubbed guard.
   *
   * @param bool $enabled
   *   Whether the AI extractor is enabled.
   * @param string[] $sidePaths
   *   The open side paths.
   *
   * @return \Drupal\fact_drift_ai\Hook\FactDriftAiHooks
   *   The hooks.
   */
  protected function hooks(bool $enabled = TRUE, array $sidePaths = []): FactDriftAiHooks {
    $guard = $this->createMock(CallGuard::class);
    $guard->method('sidePaths')->willReturn($sidePaths);
    $hooks = new FactDriftAiHooks($this->getConfigFactoryStub(['fact_drift_ai.settings' => ['enabled' => $enabled]]), $guard);
    $hooks->setStringTranslation($this->getStringTranslationStub());
    return $hooks;
  }

  /**
   * Tests that the stock ollama class is replaced.
   */
  public function testStockOllamaClassIsReplaced(): void {
    $definitions = [
      'ollama' => ['class' => FactDriftAiHooks::OLLAMA_CLASS, 'id' => 'ollama'],
      'openai' => ['class' => 'Other'],
    ];
    $this->hooks()->aiProviderInfoAlter($definitions);
    $this->assertSame(TimeoutOllamaProvider::class, $definitions['ollama']['class']);
    $this->assertSame('ollama', $definitions['ollama']['id']);
    $this->assertSame('Other', $definitions['openai']['class']);
  }

  /**
   * Tests that a changed ollama class and other providers are left alone.
   */
  public function testOtherClassesAreLeftAlone(): void {
    $definitions = ['ollama' => ['class' => 'Drupal\\site\\MyOllama']];
    $this->hooks()->aiProviderInfoAlter($definitions);
    $this->assertSame('Drupal\\site\\MyOllama', $definitions['ollama']['class']);

    $definitions = ['openai' => ['class' => FactDriftAiHooks::OLLAMA_CLASS]];
    $this->hooks()->aiProviderInfoAlter($definitions);
    $this->assertSame(FactDriftAiHooks::OLLAMA_CLASS, $definitions['openai']['class']);

    $definitions = [];
    $this->hooks()->aiProviderInfoAlter($definitions);
    $this->assertSame([], $definitions);
  }

  /**
   * Tests that nothing is reported outside runtime or while disabled.
   */
  public function testNothingIsReportedOutsideRuntimeOrWhileDisabled(): void {
    $this->assertSame([], $this->hooks()->requirements('install'));
    $this->assertSame([], $this->hooks()->requirements('update'));
    $this->assertSame([], $this->hooks(FALSE, ['ai_logging.settings:prompt_logging'])->requirements('runtime'));
  }

  /**
   * Tests that a quiet configuration reports OK.
   */
  public function testQuietConfigurationReportsOk(): void {
    $requirements = $this->hooks()->requirements('runtime');
    $this->assertSame([FactDriftAiHooks::SEVERITY_OK], [$requirements['fact_drift_ai_side_paths']['severity']]);
    $this->assertArrayNotHasKey('description', $requirements['fact_drift_ai_side_paths']);
  }

  /**
   * Tests that an open side path warns and names the setting to change.
   */
  public function testOpenSidePathWarnsAndNamesTheSetting(): void {
    $requirements = $this->hooks(TRUE, ['ai_logging.settings:prompt_logging', 'ai_observability.settings:log_input'])->requirements('runtime');
    $requirement = $requirements['fact_drift_ai_side_paths'];
    $this->assertSame(FactDriftAiHooks::SEVERITY_WARNING, $requirement['severity']);
    $this->assertStringContainsString('ai_logging.settings:prompt_logging, ai_observability.settings:log_input', (string) $requirement['description']);
    $this->assertStringContainsString('fact_drift_ai', (string) $requirement['description']);
  }

}
