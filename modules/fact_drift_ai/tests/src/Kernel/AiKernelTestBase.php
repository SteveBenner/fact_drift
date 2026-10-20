<?php

namespace Drupal\Tests\fact_drift_ai\Kernel;

use Drupal\fact_drift\Entity\Finding;
use Drupal\fact_drift\Entity\Source;
use Drupal\fact_drift_ai\CallGuard;
use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\KernelTests\KernelTestBase;
use Drupal\node\Entity\Node;
use Drupal\node\Entity\NodeType;
use Drupal\node\NodeInterface;
use Drupal\user\Entity\Role;
use Drupal\user\RoleInterface;

/**
 * Sets up a site with the AI extractor, the fake provider and one clinic.
 *
 * The fake provider plays back the script in the State key
 * fact_drift_ai_test.script and records every call under
 * fact_drift_ai_test.calls, so no test can reach a real model.
 */
abstract class AiKernelTestBase extends KernelTestBase {

  /**
   * A body the plain extractors cannot read hours from.
   */
  protected const HOURS_PROSE = 'Riverside Family Clinic is open from eight in the morning until five in the afternoon, Monday through Friday.';

  /**
   * The reply that reads HOURS_PROSE as the wrong hours.
   */
  protected const HOURS_REPLY = '{"claims": [{"type": "hours", "quote": "Monday through Friday", "value": "Mon-Fri 08:00-17:00"}]}';

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'user',
    'node',
    'field',
    'text',
    'filter',
    'options',
    'file',
    'key',
    'ai',
    'fact_drift',
    'fact_drift_ai',
    'fact_drift_ai_test',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('user');
    $this->installEntitySchema('node');
    $this->installEntitySchema('fact_drift_finding');
    $this->installSchema('node', ['node_access']);
    $this->installConfig(['node', 'filter', 'ai', 'fact_drift', 'fact_drift_ai']);
    NodeType::create(['type' => 'page', 'name' => 'Page'])->save();
    if (!FieldStorageConfig::loadByName('node', 'body')) {
      FieldStorageConfig::create([
        'field_name' => 'body',
        'entity_type' => 'node',
        'type' => 'text_with_summary',
      ])->save();
    }
    if (!FieldConfig::loadByName('node', 'page', 'body')) {
      FieldConfig::create([
        'field_name' => 'body',
        'entity_type' => 'node',
        'bundle' => 'page',
        'label' => 'Body',
      ])->save();
    }
    FieldStorageConfig::create([
      'field_name' => 'field_notes',
      'entity_type' => 'node',
      'type' => 'text_long',
    ])->save();
    FieldConfig::create([
      'field_name' => 'field_notes',
      'entity_type' => 'node',
      'bundle' => 'page',
      'label' => 'Notes',
    ])->save();
    $this->anonymousMayView(TRUE);
    Source::create([
      'id' => 'clinics',
      'label' => 'Clinics',
      'plugin' => 'csv',
      'plugin_settings' => ['path' => dirname(__DIR__, 5) . '/tests/fixtures/clinics.csv'],
      'claim_types' => [],
      'status' => TRUE,
    ])->save();
    $this->configureAi();
    $this->guard()->setSleeper(static function (int $microseconds): void {
    });
  }

  /**
   * {@inheritdoc}
   */
  protected function tearDown(): void {
    unset($GLOBALS['config']['ai_logging.settings'], $GLOBALS['config']['ai_observability.settings'], $GLOBALS['config']['ai.external_moderation']);
    parent::tearDown();
  }

  /**
   * Grants or revokes the anonymous user's right to view published content.
   *
   * @param bool $may
   *   Whether anonymous visitors may view content.
   */
  protected function anonymousMayView(bool $may): void {
    $role = Role::load(RoleInterface::ANONYMOUS_ID)
      ?? Role::create(['id' => RoleInterface::ANONYMOUS_ID, 'label' => 'Anonymous']);
    if ($may) {
      $role->grantPermission('access content');
    }
    else {
      $role->revokePermission('access content');
    }
    $role->save();
  }

  /**
   * Gets the call guard shared by the process.
   *
   * @return \Drupal\fact_drift_ai\CallGuard
   *   The guard.
   */
  protected function guard(): CallGuard {
    return $this->container->get('fact_drift_ai.call_guard');
  }

  /**
   * Turns the AI extractor on, against the fake provider, allowing the body.
   *
   * @param array<string, mixed> $overrides
   *   Settings to set over the defaults of this helper.
   */
  protected function configureAi(array $overrides = []): void {
    $config = $this->config('fact_drift_ai.settings');
    $values = $overrides + [
      'enabled' => TRUE,
      'provider' => 'fact_drift_ai_test',
      'model' => 'scripted',
      'allowlist' => [['bundle' => 'page', 'field' => 'body']],
      'min_interval_ms' => 0,
      'timeout_seconds' => 3,
      'max_retries' => 2,
      'max_calls_per_run' => 20,
    ];
    foreach ($values as $key => $value) {
      $config->set($key, $value);
    }
    $config->save();
  }

  /**
   * Sets the plain extractors the base settings enable.
   *
   * @param string[] $ids
   *   The extractor plugin IDs.
   */
  protected function enablePlain(array $ids): void {
    $this->config('fact_drift.settings')->set('extractors', $ids)->save();
  }

  /**
   * Sets the kill switch.
   *
   * @param bool $on
   *   Whether it is on.
   */
  protected function killSwitch(bool $on): void {
    $this->config('fact_drift.settings')->set('kill_switch', $on)->save();
  }

  /**
   * Overrides a configuration object that belongs to another ai submodule.
   *
   * @param string $name
   *   The configuration name.
   * @param array<string, mixed> $data
   *   The values, or an empty array to remove the override.
   */
  protected function overrideConfig(string $name, array $data): void {
    if ($data) {
      $GLOBALS['config'][$name] = $data;
    }
    else {
      unset($GLOBALS['config'][$name]);
    }
    $this->container->get('config.factory')->reset($name);
  }

  /**
   * Sets the script the fake provider plays back.
   *
   * @param array<int, array<string, mixed>> $steps
   *   The steps.
   */
  protected function script(array $steps): void {
    $this->container->get('state')->set('fact_drift_ai_test.script', $steps);
  }

  /**
   * Gets the calls the fake provider recorded.
   *
   * @return array<int, array<string, mixed>>
   *   The calls in order.
   */
  protected function calls(): array {
    $this->container->get('state')->resetCache();
    return $this->container->get('state')->get('fact_drift_ai_test.calls', []);
  }

  /**
   * Gets the steps of the script that were not used.
   *
   * @return array<int, array<string, mixed>>
   *   The steps left.
   */
  protected function scriptLeft(): array {
    $this->container->get('state')->resetCache();
    return $this->container->get('state')->get('fact_drift_ai_test.script', []);
  }

  /**
   * Creates a published page.
   *
   * @param string $body
   *   The body text.
   * @param bool $published
   *   Whether the page is published.
   * @param string $notes
   *   The text of the notes field, which is never on the allowlist.
   *
   * @return \Drupal\node\NodeInterface
   *   The saved node.
   */
  protected function createPage(string $body = self::HOURS_PROSE, bool $published = TRUE, string $notes = ''): NodeInterface {
    $node = Node::create([
      'type' => 'page',
      'title' => 'Private Title ZQX',
      'status' => $published ? 1 : 0,
      'body' => ['value' => '<p>' . $body . '</p>', 'format' => 'plain_text'],
      'field_notes' => ['value' => $notes, 'format' => 'plain_text'],
      'revision_log' => 'Revision note ZQX',
    ]);
    $node->save();
    return $node;
  }

  /**
   * Loads the findings in a state as extractor and found-value strings.
   *
   * @param string $state
   *   The finding state.
   *
   * @return string[]
   *   Sorted descriptions.
   */
  protected function findings(string $state = Finding::STATE_OPEN): array {
    $storage = $this->container->get('entity_type.manager')->getStorage('fact_drift_finding');
    $storage->resetCache();
    $rows = [];
    foreach ($storage->loadByProperties(['state' => $state]) as $finding) {
      $this->assertInstanceOf(Finding::class, $finding);
      $rows[] = $finding->getExtractor() . ':' . $finding->getFound();
    }
    sort($rows);
    return $rows;
  }

}
