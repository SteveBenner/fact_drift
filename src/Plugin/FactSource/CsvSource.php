<?php

namespace Drupal\fact_drift\Plugin\FactSource;

use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\Plugin\PluginBase;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\fact_drift\Attribute\FactSource;
use Drupal\fact_drift\Comparator;
use Drupal\fact_drift\FactSource\FactSourceInterface;
use Drupal\fact_drift\Value\Fact;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Reads facts from a CSV file.
 *
 * The first row names the columns key, name, aliases, phone, former_phones and
 * hours, in any order. Aliases and former phones are separated by a pipe.
 *
 * @phpstan-consistent-constructor
 */
#[FactSource(
  id: 'csv',
  label: new TranslatableMarkup('CSV file'),
  description: new TranslatableMarkup('Reads facts from a CSV file.'),
)]
class CsvSource extends PluginBase implements FactSourceInterface, ContainerFactoryPluginInterface {

  /**
   * The columns every CSV file must have.
   */
  public const REQUIRED_COLUMNS = ['key', 'name', 'aliases', 'phone', 'former_phones', 'hours'];

  /**
   * Constructs a CsvSource.
   *
   * @param array<string, mixed> $configuration
   *   The plugin configuration.
   * @param string $plugin_id
   *   The plugin ID.
   * @param mixed $plugin_definition
   *   The plugin definition.
   * @param string $appRoot
   *   The Drupal root directory.
   * @param \Drupal\fact_drift\Comparator $comparator
   *   The comparator that normalises values.
   */
  public function __construct(array $configuration, $plugin_id, $plugin_definition, protected string $appRoot, protected Comparator $comparator) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
  }

  /**
   * Creates the plugin from the container.
   *
   * @param \Symfony\Component\DependencyInjection\ContainerInterface $container
   *   The service container.
   * @param array<string, mixed> $configuration
   *   The plugin configuration.
   * @param string $plugin_id
   *   The plugin ID.
   * @param mixed $plugin_definition
   *   The plugin definition.
   *
   * @return static
   *   The plugin.
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition): static {
    return new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      (string) $container->getParameter('app.root'),
      $container->get('fact_drift.comparator'),
    );
  }

  /**
   * {@inheritdoc}
   *
   * @param array<string, mixed> $settings
   *   The plugin settings stored on the source entity.
   *
   * @return array<int, \Drupal\fact_drift\Value\Fact>
   *   The facts.
   */
  public function facts(array $settings): iterable {
    $path = $this->resolvePath((string) ($settings['path'] ?? ''));
    if (!is_file($path) || !is_readable($path)) {
      throw new \RuntimeException(sprintf('The CSV file %s does not exist or cannot be read.', $path));
    }
    $handle = fopen($path, 'r');
    if ($handle === FALSE) {
      throw new \RuntimeException(sprintf('The CSV file %s cannot be opened.', $path));
    }
    try {
      $header = fgetcsv($handle, NULL, ',', '"', '');
      if (!is_array($header)) {
        throw new \RuntimeException(sprintf('The CSV file %s is empty.', $path));
      }
      $header[0] = preg_replace('/^\xEF\xBB\xBF/', '', (string) $header[0]);
      $columns = array_flip(array_map(static fn ($name): string => strtolower(trim((string) $name)), $header));
      $missing = array_diff(self::REQUIRED_COLUMNS, array_keys($columns));
      if ($missing) {
        throw new \RuntimeException(sprintf('The CSV file %s lacks the columns: %s.', $path, implode(', ', $missing)));
      }
      $facts = [];
      while (($row = fgetcsv($handle, NULL, ',', '"', '')) !== FALSE) {
        $cells = [];
        foreach (self::REQUIRED_COLUMNS as $column) {
          $cells[$column] = trim((string) ($row[$columns[$column]] ?? ''));
        }
        if ($cells['key'] === '') {
          continue;
        }
        array_push($facts, ...$this->rowFacts($cells));
      }
    }
    finally {
      fclose($handle);
    }
    return $facts;
  }

  /**
   * {@inheritdoc}
   *
   * @param array<string, mixed> $settings
   *   The plugin settings stored on the source entity.
   */
  public function fingerprint(array $settings): string {
    $path = $this->resolvePath((string) ($settings['path'] ?? ''));
    if (!is_file($path)) {
      return '';
    }
    return filemtime($path) . ':' . filesize($path);
  }

  /**
   * {@inheritdoc}
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
  public function settingsForm(array $form, FormStateInterface $state, array $settings): array {
    return [
      'path' => [
        '#type' => 'textfield',
        '#title' => $this->t('CSV path'),
        '#description' => $this->t('A stream-wrapper URI, an absolute path, or a path relative to the Drupal root.'),
        '#default_value' => $settings['path'] ?? '',
        '#required' => TRUE,
        '#maxlength' => 255,
      ],
    ];
  }

  /**
   * {@inheritdoc}
   *
   * @param array<string, mixed> $values
   *   The submitted plugin settings.
   *
   * @return array<string, string>
   *   Error messages keyed by setting name.
   */
  public function validateSettings(array $values): array {
    $errors = [];
    if (trim((string) ($values['path'] ?? '')) === '') {
      $errors['path'] = $this->t('The CSV path is required.')->render();
    }
    return $errors;
  }

  /**
   * Turns one CSV row into facts.
   *
   * @param string[] $cells
   *   The trimmed cells of the row, keyed by column name.
   *
   * @return \Drupal\fact_drift\Value\Fact[]
   *   A phone fact when the phone normalises and an hours fact when the hours
   *   normalise.
   */
  protected function rowFacts(array $cells): array {
    $aliases = $this->splitList($cells['aliases']);
    $facts = [];
    $phone = $this->comparator->normalisePhone($cells['phone']);
    if ($phone !== NULL) {
      $former = [];
      foreach ($this->splitList($cells['former_phones']) as $number) {
        $normalised = $this->comparator->normalisePhone($number);
        if ($normalised !== NULL) {
          $former[] = $normalised;
        }
      }
      $facts[] = new Fact('', $cells['key'], $cells['name'], $aliases, 'phone', $phone, $former);
    }
    $hours = $this->comparator->normaliseHours($cells['hours']);
    if ($hours !== NULL) {
      $facts[] = new Fact('', $cells['key'], $cells['name'], $aliases, 'hours', $hours);
    }
    return $facts;
  }

  /**
   * Splits a pipe-separated cell into its non-empty parts.
   *
   * @param string $cell
   *   The cell.
   *
   * @return string[]
   *   The trimmed parts.
   */
  protected function splitList(string $cell): array {
    return array_values(array_filter(array_map('trim', explode('|', $cell)), static fn (string $part): bool => $part !== ''));
  }

  /**
   * Resolves a configured path to a readable location.
   *
   * @param string $path
   *   A stream-wrapper URI, an absolute path, or a path relative to the root.
   *
   * @return string
   *   The location.
   */
  protected function resolvePath(string $path): string {
    $path = trim($path);
    if ($path === '' || str_contains($path, '://') || str_starts_with($path, '/')) {
      return $path;
    }
    return $this->appRoot . '/' . $path;
  }

}
