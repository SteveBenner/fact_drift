<?php

namespace Drupal\fact_drift\Plugin\ClaimExtractor;

use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\Plugin\PluginBase;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\fact_drift\Attribute\ClaimExtractor;
use Drupal\fact_drift\ClaimExtractor\ClaimExtractorInterface;
use Drupal\fact_drift\Comparator;
use Drupal\fact_drift\Value\Claim;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Finds opening hours.
 *
 * @phpstan-consistent-constructor
 */
#[ClaimExtractor(
  id: 'hours',
  label: new TranslatableMarkup('Hours'),
  claim_type: 'hours',
  weight: 10,
)]
class HoursExtractor extends PluginBase implements ClaimExtractorInterface, ContainerFactoryPluginInterface {

  /**
   * Constructs an HoursExtractor.
   *
   * @param array<string, mixed> $configuration
   *   The plugin configuration.
   * @param string $plugin_id
   *   The plugin ID.
   * @param mixed $plugin_definition
   *   The plugin definition.
   * @param \Drupal\fact_drift\Comparator $comparator
   *   The comparator that normalises values and cuts snippets.
   */
  public function __construct(array $configuration, $plugin_id, $plugin_definition, protected Comparator $comparator) {
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
    return new static($configuration, $plugin_id, $plugin_definition, $container->get('fact_drift.comparator'));
  }

  /**
   * {@inheritdoc}
   */
  public function extract(string $text): array {
    $segment = '/' . Comparator::HOURS_SEGMENT_PATTERN . '/iu';
    $gap = '/^' . Comparator::HOURS_SEPARATOR_PATTERN . '$/iu';
    preg_match_all($segment, $text, $matches, PREG_OFFSET_CAPTURE | PREG_SET_ORDER);
    $runs = [];
    $current = NULL;
    foreach ($matches as $match) {
      [$raw, $start] = $match[0];
      $end = $start + strlen($raw);
      if ($current !== NULL && preg_match($gap, substr($text, $current['end'], $start - $current['end']))) {
        $current['end'] = $end;
        continue;
      }
      if ($current !== NULL) {
        $runs[] = $current;
      }
      $current = ['start' => $start, 'end' => $end];
    }
    if ($current !== NULL) {
      $runs[] = $current;
    }
    $claims = [];
    foreach ($runs as $run) {
      $raw = substr($text, $run['start'], $run['end'] - $run['start']);
      $value = $this->comparator->normaliseHours($raw);
      if ($value === NULL) {
        continue;
      }
      $offset = mb_strlen(substr($text, 0, $run['start']));
      $length = mb_strlen($raw);
      $claims[] = new Claim('hours', $raw, $value, $offset, $length, $this->comparator->snippet($text, $offset, $length));
    }
    return $claims;
  }

}
