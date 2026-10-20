<?php

namespace Drupal\fact_drift_test_contextual\Plugin\ClaimExtractor;

use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\Plugin\PluginBase;
use Drupal\Core\State\StateInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\fact_drift\Attribute\ClaimExtractor;
use Drupal\fact_drift\ClaimExtractor\ContextualClaimExtractorInterface;
use Drupal\fact_drift\Comparator;
use Drupal\fact_drift\Plugin\ClaimExtractor\PhoneExtractor;
use Drupal\fact_drift\Value\Claim;
use Drupal\fact_drift\Value\ExtractionContext;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * A contextual extractor whose behaviour is chosen through State.
 *
 * The State key fact_drift_test_contextual.mode is find (report every phone
 * number that no earlier claim covers), defer (defer the field by 300 seconds
 * for the reason budget), hold (hold the field) or silent (find nothing). The
 * key fact_drift_test_contextual.active, TRUE by default, is isActive(). Each
 * call records the field and the extractor IDs of the prior claims under
 * fact_drift_test_contextual.seen.
 *
 * @phpstan-consistent-constructor
 */
#[ClaimExtractor(
  id: 'fact_drift_test_contextual',
  label: new TranslatableMarkup('Contextual test extractor'),
  claim_type: NULL,
  weight: 100,
  claim_types: ['phone'],
  marker: new TranslatableMarkup('CTX'),
)]
class ContextualTestExtractor extends PluginBase implements ContextualClaimExtractorInterface, ContainerFactoryPluginInterface {

  /**
   * Constructs a ContextualTestExtractor.
   *
   * @param array<string, mixed> $configuration
   *   The plugin configuration.
   * @param string $plugin_id
   *   The plugin ID.
   * @param mixed $plugin_definition
   *   The plugin definition.
   * @param \Drupal\fact_drift\Comparator $comparator
   *   The comparator that normalises values and cuts snippets.
   * @param \Drupal\Core\State\StateInterface $state
   *   The state service.
   */
  public function __construct(array $configuration, $plugin_id, $plugin_definition, protected Comparator $comparator, protected StateInterface $state) {
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
    return new static($configuration, $plugin_id, $plugin_definition, $container->get('fact_drift.comparator'), $container->get('state'));
  }

  /**
   * {@inheritdoc}
   */
  public function extract(string $text): array {
    return [];
  }

  /**
   * {@inheritdoc}
   */
  public function isActive(): bool {
    return (bool) $this->state->get('fact_drift_test_contextual.active', TRUE);
  }

  /**
   * {@inheritdoc}
   */
  public function extractInContext(string $text, ExtractionContext $context): array {
    $seen = $this->state->get('fact_drift_test_contextual.seen', []);
    $prior = array_map(static fn (Claim $claim): string => $claim->extractor, $context->priorClaims);
    $seen[] = [$context->fieldName, $prior];
    $this->state->set('fact_drift_test_contextual.seen', $seen);
    $this->state->set('fact_drift_test_contextual.calls', (int) $this->state->get('fact_drift_test_contextual.calls', 0) + 1);

    $mode = $this->state->get('fact_drift_test_contextual.mode', 'find');
    if ($mode === 'defer') {
      $context->defer(300, 'budget');
      return [];
    }
    if ($mode === 'hold') {
      $context->hold('kill_switch');
      return [];
    }
    if ($mode !== 'find' || !preg_match_all(PhoneExtractor::PATTERN, $text, $matches, PREG_OFFSET_CAPTURE)) {
      return [];
    }
    $covered = array_map(static fn (Claim $claim): int => $claim->offset, $context->priorClaims);
    $claims = [];
    foreach ($matches[0] as [$raw, $byteOffset]) {
      $value = $this->comparator->normalisePhone($raw);
      $offset = mb_strlen(substr($text, 0, $byteOffset));
      if ($value === NULL || in_array($offset, $covered, TRUE)) {
        continue;
      }
      $length = mb_strlen($raw);
      $claims[] = new Claim('phone', $raw, $value, $offset, $length, $this->comparator->snippet($text, $offset, $length));
    }
    return $claims;
  }

  /**
   * {@inheritdoc}
   */
  public function runSummary(): array {
    return ['calls' => (int) $this->state->get('fact_drift_test_contextual.calls', 0)];
  }

}
