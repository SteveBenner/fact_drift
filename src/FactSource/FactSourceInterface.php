<?php

namespace Drupal\fact_drift\FactSource;

use Drupal\Core\Form\FormStateInterface;
use Drupal\Component\Plugin\PluginInspectionInterface;

/**
 * Defines what a fact source plugin must do.
 */
interface FactSourceInterface extends PluginInspectionInterface {

  /**
   * Reads the facts from the source.
   *
   * @param array<string, mixed> $settings
   *   The plugin settings stored on the source entity.
   *
   * @return iterable<\Drupal\fact_drift\Value\Fact>
   *   The facts. Their source ID is left empty; the scanner sets it.
   *
   * @throws \RuntimeException
   *   When the source cannot be read.
   */
  public function facts(array $settings): iterable;

  /**
   * Identifies the current content of the source.
   *
   * The scanner keys cached facts on this value, so it must change whenever
   * the source's content changes.
   *
   * @param array<string, mixed> $settings
   *   The plugin settings stored on the source entity.
   *
   * @return string
   *   A fingerprint of the content; empty when it cannot be determined.
   */
  public function fingerprint(array $settings): string;

  /**
   * Builds the form elements for the plugin settings.
   *
   * @param array<string, mixed> $form
   *   The form being built.
   * @param \Drupal\Core\Form\FormStateInterface $state
   *   The form state.
   * @param array<string, mixed> $settings
   *   The current plugin settings.
   *
   * @return array<string, mixed>
   *   The form elements, keyed by setting name.
   */
  public function settingsForm(array $form, FormStateInterface $state, array $settings): array;

  /**
   * Validates submitted plugin settings.
   *
   * @param array<string, mixed> $values
   *   The submitted plugin settings.
   *
   * @return array<string, string>
   *   Error messages keyed by setting name; empty when the values are valid.
   */
  public function validateSettings(array $values): array;

}
