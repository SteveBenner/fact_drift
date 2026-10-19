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
 * Finds North American phone numbers.
 *
 * @phpstan-consistent-constructor
 */
#[ClaimExtractor(
  id: 'phone',
  label: new TranslatableMarkup('Phone'),
  claim_type: 'phone',
)]
class PhoneExtractor extends PluginBase implements ClaimExtractorInterface, ContainerFactoryPluginInterface {

  /**
   * The regular expression that finds a phone number.
   */
  public const PATTERN = '/(?<!\d)(?:\+?1[\s.\-]?)?(?:\(\d{3}\)|\d{3})[\s.\-]?\d{3}[\s.\-]?\d{4}(?:\s*(?:x|ext\.?|extension)\s*\d{1,6})?(?!\d)/iu';

  /**
   * Constructs a PhoneExtractor.
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
    if (!preg_match_all(self::PATTERN, $text, $matches, PREG_OFFSET_CAPTURE)) {
      return [];
    }
    $claims = [];
    foreach ($matches[0] as [$raw, $byteOffset]) {
      $value = $this->comparator->normalisePhone($raw);
      if ($value === NULL) {
        continue;
      }
      $offset = mb_strlen(substr($text, 0, $byteOffset));
      $length = mb_strlen($raw);
      $claims[] = new Claim('phone', $raw, $value, $offset, $length, $this->comparator->snippet($text, $offset, $length));
    }
    return $claims;
  }

}
