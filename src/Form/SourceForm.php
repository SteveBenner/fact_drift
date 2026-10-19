<?php

namespace Drupal\fact_drift\Form;

use Drupal\Core\Entity\EntityForm;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\fact_drift\ClaimExtractor\ClaimExtractorManager;
use Drupal\fact_drift\Entity\Source;
use Drupal\fact_drift\FactSource\FactSourceManager;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Adds and edits a fact source.
 *
 * @phpstan-consistent-constructor
 */
class SourceForm extends EntityForm {

  /**
   * Constructs a SourceForm.
   *
   * @param \Drupal\fact_drift\FactSource\FactSourceManager $sourceManager
   *   The fact source plugin manager.
   * @param \Drupal\fact_drift\ClaimExtractor\ClaimExtractorManager $extractorManager
   *   The claim extractor plugin manager.
   */
  public function __construct(protected FactSourceManager $sourceManager, protected ClaimExtractorManager $extractorManager) {
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static($container->get('fact_drift.source_manager'), $container->get('fact_drift.extractor_manager'));
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
  public function form(array $form, FormStateInterface $form_state): array {
    $form = parent::form($form, $form_state);
    $source = $this->entity;
    assert($source instanceof Source);

    $form['label'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Label'),
      '#maxlength' => 255,
      '#default_value' => $source->label(),
      '#required' => TRUE,
    ];
    $form['id'] = [
      '#type' => 'machine_name',
      '#default_value' => $source->id(),
      '#machine_name' => ['exists' => [$this, 'exists']],
      '#disabled' => !$source->isNew(),
    ];

    $plugins = [];
    foreach ($this->sourceManager->getDefinitions() as $id => $definition) {
      $plugins[$id] = $definition['label'];
    }
    $plugin_id = $form_state->getValue('plugin') ?: $source->getPluginId() ?: (string) array_key_first($plugins);
    $form['plugin'] = [
      '#type' => 'select',
      '#title' => $this->t('Source plugin'),
      '#options' => $plugins,
      '#default_value' => $plugin_id,
      '#required' => TRUE,
    ];

    $form['plugin_settings'] = [
      '#type' => 'details',
      '#title' => $this->t('Plugin settings'),
      '#open' => TRUE,
      '#tree' => TRUE,
    ];
    if ($plugin_id !== '' && $this->sourceManager->hasDefinition($plugin_id)) {
      $plugin = $this->sourceManager->createInstance($plugin_id);
      $form['plugin_settings'] += $plugin->settingsForm($form, $form_state, $source->getPluginSettings());
    }

    $claim_types = [];
    foreach ($this->extractorManager->getDefinitions() as $definition) {
      $claim_types[$definition['claim_type']] = $definition['claim_type'];
    }
    $form['claim_types'] = [
      '#type' => 'checkboxes',
      '#title' => $this->t('Claim types'),
      '#description' => $this->t('The claim types this source provides facts for.'),
      '#options' => $claim_types,
      '#default_value' => $source->getClaimTypes(),
      '#required' => TRUE,
    ];

    $form['status'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Enabled'),
      '#default_value' => $source->status(),
    ];

    return $form;
  }

  /**
   * {@inheritdoc}
   *
   * @param array<int|string, mixed> $form
   *   The form.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The form state.
   */
  public function validateForm(array &$form, FormStateInterface $form_state): void {
    parent::validateForm($form, $form_state);
    $plugin_id = (string) $form_state->getValue('plugin');
    if ($plugin_id === '' || !$this->sourceManager->hasDefinition($plugin_id)) {
      return;
    }
    $plugin = $this->sourceManager->createInstance($plugin_id);
    $errors = $plugin->validateSettings((array) $form_state->getValue('plugin_settings', []));
    foreach ($errors as $element => $message) {
      $form_state->setErrorByName('plugin_settings][' . $element, $message);
    }
  }

  /**
   * {@inheritdoc}
   *
   * @param \Drupal\Core\Entity\EntityInterface $entity
   *   The entity being built.
   * @param array<string, mixed> $form
   *   The form.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The form state.
   */
  protected function copyFormValuesToEntity(EntityInterface $entity, array $form, FormStateInterface $form_state): void {
    $form_state->setValue('claim_types', array_values(array_filter((array) $form_state->getValue('claim_types'))));
    $form_state->setValue('status', (bool) $form_state->getValue('status'));
    parent::copyFormValuesToEntity($entity, $form, $form_state);
  }

  /**
   * {@inheritdoc}
   *
   * @param array<string, mixed> $form
   *   The form.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The form state.
   */
  public function save(array $form, FormStateInterface $form_state): int {
    $source = $this->entity;
    $result = $source->save();
    $this->messenger()->addStatus($this->t('Saved the %label source.', ['%label' => $source->label()]));
    $form_state->setRedirectUrl($source->toUrl('collection'));
    return $result;
  }

  /**
   * Tells whether a source with the given machine name exists.
   *
   * @param string $id
   *   The machine name.
   *
   * @return bool
   *   TRUE when the machine name is taken.
   */
  public function exists(string $id): bool {
    return (bool) $this->entityTypeManager->getStorage('fact_drift_source')
      ->getQuery()
      ->accessCheck(TRUE)
      ->condition('id', $id)
      ->execute();
  }

}
