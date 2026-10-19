<?php

namespace Drupal\fact_drift\Form;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\TypedConfigManagerInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\ConfigTarget;
use Drupal\Core\Form\FormStateInterface;
use Drupal\fact_drift\ClaimExtractor\ClaimExtractorManager;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Configures what Fact Drift Sentinel scans and how often.
 *
 * @phpstan-consistent-constructor
 */
class SettingsForm extends ConfigFormBase {

  /**
   * Constructs a SettingsForm.
   *
   * @param \Drupal\Core\Config\ConfigFactoryInterface $config_factory
   *   The config factory.
   * @param \Drupal\Core\Config\TypedConfigManagerInterface $typed_config_manager
   *   The typed config manager.
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entityTypeManager
   *   The entity type manager.
   * @param \Drupal\fact_drift\ClaimExtractor\ClaimExtractorManager $extractorManager
   *   The claim extractor plugin manager.
   */
  public function __construct(ConfigFactoryInterface $config_factory, TypedConfigManagerInterface $typed_config_manager, protected EntityTypeManagerInterface $entityTypeManager, protected ClaimExtractorManager $extractorManager) {
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
      $container->get('fact_drift.extractor_manager'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'fact_drift_settings';
  }

  /**
   * {@inheritdoc}
   *
   * @return array<int, string>
   *   The editable config names.
   */
  protected function getEditableConfigNames(): array {
    return ['fact_drift.settings'];
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

    $content_types = [];
    foreach ($this->entityTypeManager->getStorage('node_type')->loadMultiple() as $id => $type) {
      $content_types[$id] = $type->label();
    }
    $extractors = [];
    foreach ($this->extractorManager->getDefinitions() as $id => $definition) {
      $extractors[$id] = $definition['label'];
    }

    $form['content_types'] = [
      '#type' => 'checkboxes',
      '#title' => $this->t('Content types to scan'),
      '#options' => $content_types,
      '#config_target' => new ConfigTarget('fact_drift.settings', 'content_types', NULL, static fn (array $value): array => array_values(array_filter($value))),
    ];
    $form['field_types'] = [
      '#type' => 'checkboxes',
      '#title' => $this->t('Field types to scan'),
      '#options' => [
        'text_with_summary' => $this->t('Text (formatted, long, with summary)'),
        'text_long' => $this->t('Text (formatted, long)'),
        'string_long' => $this->t('Text (plain, long)'),
      ],
      '#config_target' => new ConfigTarget('fact_drift.settings', 'field_types', NULL, static fn (array $value): array => array_values(array_filter($value))),
    ];
    $form['extractors'] = [
      '#type' => 'checkboxes',
      '#title' => $this->t('Claim extractors'),
      '#options' => $extractors,
      '#config_target' => new ConfigTarget('fact_drift.settings', 'extractors', NULL, static fn (array $value): array => array_values(array_filter($value))),
    ];
    $form['full_scan_interval'] = [
      '#type' => 'number',
      '#title' => $this->t('Seconds between full scans'),
      '#min' => 3600,
      '#config_target' => new ConfigTarget('fact_drift.settings', 'full_scan_interval', NULL, static fn ($value): int => (int) $value),
    ];
    $form['nodes_per_cron'] = [
      '#type' => 'number',
      '#title' => $this->t('Nodes queued per cron run'),
      '#min' => 1,
      '#config_target' => new ConfigTarget('fact_drift.settings', 'nodes_per_cron', NULL, static fn ($value): int => (int) $value),
    ];
    $form['kill_switch'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Kill switch'),
      '#description' => $this->t('Forces deterministic extraction only. Can be overridden per environment in settings.php.'),
      '#config_target' => new ConfigTarget('fact_drift.settings', 'kill_switch', NULL, static fn ($value): bool => (bool) $value),
    ];

    return $form;
  }

}
