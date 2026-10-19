<?php

namespace Drupal\fact_drift\Hook;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Hook\Attribute\Hook;
use Drupal\Core\Link;
use Drupal\Core\Queue\QueueFactory;
use Drupal\Core\Routing\RouteMatchInterface;
use Drupal\Core\State\StateInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\fact_drift\FindingRecorder;
use Drupal\node\NodeInterface;
use Psr\Log\LoggerInterface;

/**
 * Hook implementations for Fact Drift Sentinel.
 *
 * Drupal 11.1 and later discover this class on their own. Drupal 10.3 reaches
 * it through the #[LegacyHook] functions in fact_drift.module.
 */
class FactDriftHooks {

  use StringTranslationTrait;

  /**
   * The name of the rescan queue.
   */
  public const QUEUE = 'fact_drift_rescan';

  /**
   * The State key of the cron cursor: the last node ID queued in this pass.
   */
  public const STATE_CURSOR = 'fact_drift.cursor';

  /**
   * The State key of the time the last full scan pass finished.
   */
  public const STATE_LAST_FULL_SCAN = 'fact_drift.last_full_scan';

  /**
   * Constructs the hook implementations.
   *
   * @param \Drupal\Core\Config\ConfigFactoryInterface $configFactory
   *   The config factory.
   * @param \Drupal\Core\Queue\QueueFactory $queueFactory
   *   The queue factory.
   * @param \Drupal\Core\State\StateInterface $state
   *   The state service.
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entityTypeManager
   *   The entity type manager.
   * @param \Drupal\fact_drift\FindingRecorder $recorder
   *   The finding recorder.
   * @param \Drupal\Component\Datetime\TimeInterface $time
   *   The time service.
   * @param \Psr\Log\LoggerInterface $logger
   *   The fact_drift logger channel.
   */
  public function __construct(
    protected ConfigFactoryInterface $configFactory,
    protected QueueFactory $queueFactory,
    protected StateInterface $state,
    protected EntityTypeManagerInterface $entityTypeManager,
    protected FindingRecorder $recorder,
    protected TimeInterface $time,
    protected LoggerInterface $logger,
  ) {
  }

  /**
   * Queues a rescan when a published node of a configured type is created.
   *
   * @param \Drupal\node\NodeInterface $node
   *   The node.
   */
  #[Hook('node_insert')]
  public function nodeInsert(NodeInterface $node): void {
    $this->enqueue($node);
  }

  /**
   * Queues a rescan when a published node of a configured type is updated.
   *
   * @param \Drupal\node\NodeInterface $node
   *   The node.
   */
  #[Hook('node_update')]
  public function nodeUpdate(NodeInterface $node): void {
    $this->enqueue($node);
  }

  /**
   * Closes the open findings of a deleted node.
   *
   * @param \Drupal\node\NodeInterface $node
   *   The node.
   */
  #[Hook('node_delete')]
  public function nodeDelete(NodeInterface $node): void {
    $closed = $this->recorder->closeForNode((int) $node->id());
    $this->logger->info('node deleted: node @nid, @count findings closed', [
      '@nid' => $node->id(),
      '@count' => $closed,
    ]);
  }

  /**
   * Queues rescans for the next batch of published nodes when a pass is due.
   */
  #[Hook('cron')]
  public function cron(): void {
    $settings = $this->configFactory->get('fact_drift.settings');
    $now = $this->time->getRequestTime();
    $cursor = (int) $this->state->get(self::STATE_CURSOR, 0);
    $last = (int) $this->state->get(self::STATE_LAST_FULL_SCAN, 0);
    if ($cursor <= 0 && $now - $last < (int) $settings->get('full_scan_interval')) {
      return;
    }
    $types = $settings->get('content_types') ?? [];
    $limit = max(1, (int) $settings->get('nodes_per_cron'));
    $nids = [];
    if ($types) {
      $nids = array_values($this->entityTypeManager->getStorage('node')->getQuery()
        ->accessCheck(FALSE)
        ->condition('status', 1)
        ->condition('type', $types, 'IN')
        ->condition('nid', $cursor, '>')
        ->sort('nid')
        ->range(0, $limit)
        ->execute());
    }
    $queue = $this->queueFactory->get(self::QUEUE);
    foreach ($nids as $nid) {
      $queue->createItem(['nid' => (int) $nid]);
    }
    if (count($nids) < $limit) {
      $this->state->set(self::STATE_CURSOR, 0);
      $this->state->set(self::STATE_LAST_FULL_SCAN, $now);
    }
    else {
      $this->state->set(self::STATE_CURSOR, (int) end($nids));
    }
  }

  /**
   * Registers the report theme hook.
   *
   * @return array<string, array<string, mixed>>
   *   The theme hook definitions.
   */
  #[Hook('theme')]
  public function theme(): array {
    return [
      'fact_drift_report' => [
        'variables' => [
          'summary' => [],
          'filters' => [],
          'table' => [],
          'attributes' => [],
        ],
      ],
    ];
  }

  /**
   * Provides the module's help page.
   *
   * @param string $route_name
   *   The route name.
   * @param \Drupal\Core\Routing\RouteMatchInterface $route_match
   *   The route match.
   *
   * @return array<string, mixed>|null
   *   A render array for the help page, or NULL for any other route.
   */
  #[Hook('help')]
  public function help(string $route_name, RouteMatchInterface $route_match): ?array {
    if ($route_name !== 'help.page.fact_drift') {
      return NULL;
    }
    return [
      'about' => [
        '#type' => 'html_tag',
        '#tag' => 'p',
        '#value' => $this->t('Fact Drift Sentinel reads the true facts from configured sources, such as a CSV file of clinic phone numbers and opening hours, and checks the published pages of the site against them. Each page that shows a stale fact becomes a finding with the evidence.'),
      ],
      'links' => [
        '#theme' => 'item_list',
        '#items' => [
          Link::createFromRoute($this->t('Fact drift report'), 'fact_drift.report')->toRenderable(),
          Link::createFromRoute($this->t('Fact Drift Sentinel settings'), 'fact_drift.settings')->toRenderable(),
          Link::createFromRoute($this->t('Fact drift sources'), 'entity.fact_drift_source.collection')->toRenderable(),
        ],
      ],
    ];
  }

  /**
   * Queues a rescan for a published node of a configured content type.
   *
   * @param \Drupal\node\NodeInterface $node
   *   The node.
   */
  protected function enqueue(NodeInterface $node): void {
    $types = $this->configFactory->get('fact_drift.settings')->get('content_types') ?? [];
    if ($node->isPublished() && in_array($node->bundle(), $types, TRUE)) {
      $this->queueFactory->get(self::QUEUE)->createItem(['nid' => (int) $node->id()]);
    }
  }

}
