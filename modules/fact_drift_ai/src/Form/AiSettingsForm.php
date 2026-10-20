<?php

namespace Drupal\fact_drift_ai\Form;

use Drupal\Core\Cache\CacheBackendInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\TypedConfigManagerInterface;
use Drupal\Core\Entity\EntityFieldManagerInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\ConfigTarget;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Link;
use Drupal\Core\Url;
use Drupal\ai\AiProviderPluginManager;
use Drupal\fact_drift_ai\CallGuard;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Configures the AI extractor: the model, what is sent, and the limits.
 *
 * @phpstan-consistent-constructor
 */
class AiSettingsForm extends ConfigFormBase {

  /**
   * Constructs an AiSettingsForm.
   *
   * @param \Drupal\Core\Config\ConfigFactoryInterface $config_factory
   *   The config factory.
   * @param \Drupal\Core\Config\TypedConfigManagerInterface $typed_config_manager
   *   The typed config manager.
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entityTypeManager
   *   The entity type manager.
   * @param \Drupal\Core\Entity\EntityFieldManagerInterface $entityFieldManager
   *   The entity field manager.
   * @param \Drupal\ai\AiProviderPluginManager $providerManager
   *   The ai module's provider plugin manager.
   * @param \Drupal\Core\Cache\CacheBackendInterface $cache
   *   The cache bin of validated model results.
   * @param \Drupal\fact_drift_ai\CallGuard $guard
   *   The call guard, which knows the kill switch and the side paths.
   */
  public function __construct(
    ConfigFactoryInterface $config_factory,
    TypedConfigManagerInterface $typed_config_manager,
    protected EntityTypeManagerInterface $entityTypeManager,
    protected EntityFieldManagerInterface $entityFieldManager,
    protected AiProviderPluginManager $providerManager,
    protected CacheBackendInterface $cache,
    protected CallGuard $guard,
  ) {
    parent::__construct($config_factory, $typed_config_manager);
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('config.factory'),
      $container->get('config.typed'),
      $container->get('entity_type.manager'),
      $container->get('entity_field.manager'),
      $container->get('ai.provider'),
      $container->get('cache.fact_drift_llm'),
      $container->get('fact_drift_ai.call_guard'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'fact_drift_ai_settings';
  }

  /**
   * {@inheritdoc}
   *
   * @return array<int, string>
   *   The editable config names.
   */
  protected function getEditableConfigNames(): array {
    return ['fact_drift_ai.settings'];
  }

  /**
   * {@inheritdoc}
   *
   * @param array<string, mixed> $form
   *   The form.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The form state.
   *
   * @return array<string, mixed>
   *   The form.
   */
  public function buildForm(array $form, FormStateInterface $form_state): array {
    $form = parent::buildForm($form, $form_state);
    $form['#attached']['library'][] = 'fact_drift_ai/settings';
    $toInt = static fn ($value): int => (int) $value;

    $form['status'] = [
      '#type' => 'details',
      '#title' => $this->t('Status'),
      '#open' => TRUE,
    ] + $this->statusRows();

    $form['enabled'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Enable the AI extractor'),
      '#description' => $this->t('Sends allowed fields of public pages to the chosen model. The kill switch on the Settings tab stops it at once.'),
      '#config_target' => new ConfigTarget('fact_drift_ai.settings', 'enabled', NULL, static fn ($value): bool => (bool) $value),
    ];

    $options = $this->modelOptions();
    if ($options) {
      $form['model'] = [
        '#type' => 'select',
        '#title' => $this->t('Model'),
        '#options' => $options,
        '#empty_option' => $this->t('- Choose a model -'),
        '#empty_value' => '',
        '#states' => [
          'visible' => [':input[name="enabled"]' => ['checked' => TRUE]],
        ],
        '#config_target' => new ConfigTarget(
          'fact_drift_ai.settings',
          ['provider', 'model'],
          static fn (?string $provider, ?string $model): string => $provider && $model ? $provider . '__' . $model : '',
          static function ($value): array {
            [$provider, $model] = array_pad(explode('__', (string) $value, 2), 2, '');
            return ['provider' => $provider, 'model' => $model];
          },
        ),
      ];
    }
    else {
      $this->messenger()->addWarning($this->t('No usable chat provider is configured. Set one up on the <a href=":url">AI providers page</a>.', [
        ':url' => Url::fromRoute('ai.admin_providers')->toString(),
      ]));
    }

    $form['mode'] = [
      '#type' => 'radios',
      '#title' => $this->t('When to ask the model'),
      '#options' => [
        'fallback' => $this->t('Only for claim types the patterns found none of in a field (fewer calls)'),
        'alongside' => $this->t('For every allowed field, alongside the patterns (more calls)'),
      ],
      '#config_target' => 'fact_drift_ai.settings:mode',
    ];

    $form['reasoning'] = [
      '#type' => 'radios',
      '#title' => $this->t('Reasoning'),
      '#options' => [
        'none' => $this->t('Off (fastest; recommended for extraction)'),
        'provider_default' => $this->t('Provider default'),
      ],
      '#config_target' => 'fact_drift_ai.settings:reasoning',
    ];

    $form['sent'] = [
      '#type' => 'details',
      '#title' => $this->t('What is sent'),
      '#open' => TRUE,
    ];
    $form['sent']['prefilter'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Send only passages with a phone or hours cue'),
      '#config_target' => new ConfigTarget('fact_drift_ai.settings', 'prefilter', NULL, static fn ($value): bool => (bool) $value),
    ];
    $form['sent']['max_chars'] = [
      '#type' => 'number',
      '#title' => $this->t('Largest passage window'),
      '#min' => 500,
      '#max' => 16000,
      '#step' => 100,
      '#field_suffix' => $this->t('characters'),
      '#wrapper_attributes' => ['class' => ['fact-drift-ai-suffixed']],
      '#config_target' => new ConfigTarget('fact_drift_ai.settings', 'max_chars', NULL, $toInt),
    ];
    $form['sent']['allowlist'] = [
      '#type' => 'checkboxes',
      '#title' => $this->t('Allowed fields'),
      '#description' => $this->t('Nothing is sent until you allow a field. Only published pages that anonymous visitors can view are sent.'),
      '#options' => $this->fieldOptions(),
      '#config_target' => new ConfigTarget(
        'fact_drift_ai.settings',
        'allowlist',
        static fn (?array $allowlist): array => array_map(static fn (array $entry): string => $entry['bundle'] . '.' . $entry['field'], $allowlist ?? []),
        static fn (array $value): array => array_map(static function (string $key): array {
          [$bundle, $field] = explode('.', $key, 2);
          return ['bundle' => $bundle, 'field' => $field];
        }, array_values(array_filter($value))),
      ),
    ];

    $form['limits'] = [
      '#type' => 'details',
      '#title' => $this->t('Limits'),
      '#open' => TRUE,
    ];
    $form['limits']['numbers'] = [
      '#type' => 'container',
      '#attributes' => ['class' => ['fact-drift-ai-limits']],
      'grid' => [
        '#type' => 'container',
        '#attributes' => ['class' => ['fact-drift-ai-limits__grid']],
      ],
    ];
    $form['limits']['numbers']['grid']['max_calls_per_run'] = [
      '#type' => 'number',
      '#title' => $this->t('Calls per run'),
      '#description' => $this->t('The most calls in one Drush command or cron run, retries included.'),
      '#min' => 1,
      '#max' => 1000,
      '#config_target' => new ConfigTarget('fact_drift_ai.settings', 'max_calls_per_run', NULL, $toInt),
    ];
    $form['limits']['numbers']['grid']['min_interval_ms'] = [
      '#type' => 'number',
      '#title' => $this->t('Pause between calls'),
      '#description' => $this->t('The least time between the start of one call and the next.'),
      '#min' => 0,
      '#max' => 60000,
      '#field_suffix' => $this->t('ms'),
      '#config_target' => new ConfigTarget('fact_drift_ai.settings', 'min_interval_ms', NULL, $toInt),
    ];
    $form['limits']['numbers']['grid']['timeout_seconds'] = [
      '#type' => 'number',
      '#title' => $this->t('Timeout'),
      '#description' => $this->t('How long one call may take before it is given up.'),
      '#min' => 1,
      '#max' => 120,
      '#field_suffix' => $this->t('s'),
      '#config_target' => new ConfigTarget('fact_drift_ai.settings', 'timeout_seconds', NULL, $toInt),
    ];
    $form['limits']['numbers']['grid']['max_retries'] = [
      '#type' => 'number',
      '#title' => $this->t('Retries'),
      '#description' => $this->t('How often a call is repeated after a timeout or a server error.'),
      '#min' => 0,
      '#max' => 5,
      '#config_target' => new ConfigTarget('fact_drift_ai.settings', 'max_retries', NULL, $toInt),
    ];
    return $form;
  }

  /**
   * {@inheritdoc}
   *
   * @param array<mixed> $form
   *   The form.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The form state.
   */
  public function validateForm(array &$form, FormStateInterface $form_state): void {
    parent::validateForm($form, $form_state);
    if (isset($form['model']) && $form_state->getValue('enabled') && !$form_state->getValue('model')) {
      $form_state->setErrorByName('model', $this->t('Choose a model to enable the AI extractor.'));
    }
  }

  /**
   * {@inheritdoc}
   *
   * @param array<mixed> $form
   *   The form.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The form state.
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    parent::submitForm($form, $form_state);
    $this->cache->deleteAll();
  }

  /**
   * Builds the read-only rows of the status section.
   *
   * @return array<string, array<string, mixed>>
   *   One item element each for the kill switch, the provider and the side
   *   paths.
   */
  protected function statusRows(): array {
    $killSwitch = $this->guard->killSwitchOn();
    $rows['kill_switch'] = [
      '#type' => 'item',
      '#title' => $this->t('Kill switch'),
    ];
    if ($killSwitch) {
      $rows['kill_switch']['value'] = ['#plain_text' => (string) $this->t('On, stopping every call')];
    }
    else {
      $link = Link::createFromRoute($this->t('Settings tab'), 'fact_drift.settings')->toString();
      $rows['kill_switch']['value'] = ['#markup' => $this->t('Off. Change it on the @link.', ['@link' => $link])];
    }
    $rows['provider'] = [
      '#type' => 'item',
      '#title' => $this->t('Provider usable'),
      'value' => ['#plain_text' => $this->guard->isConfigured() ? $this->t('Yes') : $this->t('No')],
    ];
    $paths = $this->guard->sidePaths();
    $rows['side_paths'] = [
      '#type' => 'item',
      '#title' => $this->t('Side paths'),
      'value' => ['#plain_text' => $paths ? implode(', ', $paths) : $this->t('None')],
    ];
    return $rows;
  }

  /**
   * Lists the usable chat providers and their models.
   *
   * A provider that fails to list its models is left out, so one broken
   * provider cannot break the form.
   *
   * @return array<string, array<string, string>>
   *   Provider and model keys, provider__model, grouped under the provider's
   *   label.
   */
  protected function modelOptions(): array {
    $options = [];
    try {
      $providers = $this->providerManager->getProvidersForOperationType('chat', TRUE);
    }
    catch (\Exception) {
      return [];
    }
    foreach ($providers as $id => $definition) {
      try {
        $provider = $this->providerManager->createInstance($id);
        $models = call_user_func([$provider, 'getConfiguredModels'], 'chat');
      }
      catch (\Exception) {
        continue;
      }
      foreach ($models as $modelId => $label) {
        $options[(string) $definition['label']][$id . '__' . $modelId] = (string) $label;
      }
    }
    return $options;
  }

  /**
   * Lists the configurable fields of the configured content types.
   *
   * @return array<string, string>
   *   Labels keyed by bundle.field, for each configurable field whose type
   *   the base module scans. Base fields such as title and revision_log are
   *   never listed.
   */
  protected function fieldOptions(): array {
    $base = $this->configFactory()->get('fact_drift.settings');
    $fieldTypes = $base->get('field_types') ?? [];
    $bundles = $this->entityTypeManager->getStorage('node_type')->loadMultiple($base->get('content_types') ?? []);
    $options = [];
    foreach ($bundles as $bundle => $type) {
      foreach ($this->entityFieldManager->getFieldDefinitions('node', (string) $bundle) as $name => $definition) {
        if (!$definition->getFieldStorageDefinition()->isBaseField() && in_array($definition->getType(), $fieldTypes, TRUE)) {
          $options[$bundle . '.' . $name] = $type->label() . ': ' . $definition->getLabel();
        }
      }
    }
    return $options;
  }

}
