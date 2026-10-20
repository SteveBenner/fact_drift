<?php

namespace Drupal\fact_drift_ai\Hook;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Hook\Attribute\Hook;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\fact_drift_ai\CallGuard;
use Drupal\fact_drift_ai\Plugin\AiProvider\TimeoutOllamaProvider;

/**
 * Hook implementations for the Fact Drift Sentinel AI extractor.
 *
 * Drupal 11.1 and later discover this class on their own. Drupal 10.6 reaches
 * it through the #[LegacyHook] functions in fact_drift_ai.module and the
 * requirements function in fact_drift_ai.install.
 */
class FactDriftAiHooks {

  use StringTranslationTrait;

  /**
   * The class of the ollama provider plugin that is replaced.
   */
  public const OLLAMA_CLASS = 'Drupal\ai_provider_ollama\Plugin\AiProvider\OllamaProvider';

  /**
   * The severity of a requirement that is met.
   *
   * Plain integers work on every supported Drupal version; the constants are
   * deprecated from Drupal 11.2 and the enum does not exist before it.
   */
  public const SEVERITY_OK = 0;

  /**
   * The severity of a requirement that is met with a warning.
   */
  public const SEVERITY_WARNING = 1;

  /**
   * Constructs the hook implementations.
   *
   * @param \Drupal\Core\Config\ConfigFactoryInterface $configFactory
   *   The config factory.
   * @param \Drupal\fact_drift_ai\CallGuard $guard
   *   The call guard, which knows the side paths.
   */
  public function __construct(
    protected ConfigFactoryInterface $configFactory,
    protected CallGuard $guard,
  ) {
  }

  /**
   * Replaces the ollama provider class with one that honours timeouts.
   *
   * Only an ollama definition whose class is exactly the one the
   * ai_provider_ollama module ships is replaced. The replacement class is
   * named, not loaded, so a site without that module is untouched.
   *
   * @param array<string, array<string, mixed>> $definitions
   *   The provider plugin definitions.
   */
  #[Hook('ai_provider_info_alter')]
  public function aiProviderInfoAlter(array &$definitions): void {
    if (isset($definitions['ollama']) && ($definitions['ollama']['class'] ?? NULL) === self::OLLAMA_CLASS) {
      $definitions['ollama']['class'] = TimeoutOllamaProvider::class;
    }
  }

  /**
   * Reports the side paths on the status report.
   *
   * @param string $phase
   *   The phase: install, update or runtime.
   *
   * @return array<string, array<string, mixed>>
   *   Nothing outside the runtime phase or while the extractor is disabled;
   *   otherwise one requirement, a warning naming each open side path.
   */
  public function requirements(string $phase): array {
    if ($phase !== 'runtime' || !$this->configFactory->get('fact_drift_ai.settings')->get('enabled')) {
      return [];
    }
    $paths = $this->guard->sidePaths();
    $requirement = [
      'title' => $this->t('Fact Drift Sentinel AI extractor'),
      'severity' => self::SEVERITY_OK,
      'value' => $this->t('No other AI submodule stores or forwards what is sent.'),
    ];
    if ($paths) {
      $requirement['severity'] = self::SEVERITY_WARNING;
      $requirement['value'] = $this->t('Held: another AI submodule would store or forward page text.');
      $requirement['description'] = $this->t('The AI extractor makes no call while these settings are on: @settings. Turn them off, or for prompt logging add the tag fact_drift_ai to the excluded tags.', [
        '@settings' => implode(', ', $paths),
      ]);
    }
    return ['fact_drift_ai_side_paths' => $requirement];
  }

}
