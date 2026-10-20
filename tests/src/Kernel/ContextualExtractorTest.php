<?php

namespace Drupal\Tests\fact_drift\Kernel;

use Drupal\Core\Queue\DelayedRequeueException;
use Drupal\fact_drift\Entity\Finding;
use Drupal\fact_drift\Entity\Source;
use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\KernelTests\KernelTestBase;
use Drupal\node\Entity\Node;
use Drupal\node\Entity\NodeType;
use Drupal\node\NodeInterface;

/**
 * Tests plain and contextual extractors, provenance and deferred work.
 *
 * @group fact_drift
 * @covers \Drupal\fact_drift\Scanner
 * @covers \Drupal\fact_drift\FindingRecorder
 * @covers \Drupal\fact_drift\Entity\Finding
 * @covers \Drupal\fact_drift\Plugin\QueueWorker\RescanWorker
 */
class ContextualExtractorTest extends KernelTestBase {

  /**
   * The ID of the contextual test extractor.
   */
  protected const CONTEXTUAL = 'fact_drift_test_contextual';

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
    'fact_drift',
    'fact_drift_test_contextual',
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
    $this->installConfig(['node', 'filter', 'fact_drift']);
    NodeType::create(['type' => 'page', 'name' => 'Page'])->save();
    FieldStorageConfig::create([
      'field_name' => 'body',
      'entity_type' => 'node',
      'type' => 'text_with_summary',
    ])->save();
    FieldConfig::create([
      'field_name' => 'body',
      'entity_type' => 'node',
      'bundle' => 'page',
      'label' => 'Body',
    ])->save();
    Source::create([
      'id' => 'clinics',
      'label' => 'Clinics',
      'plugin' => 'csv',
      'plugin_settings' => ['path' => dirname(__DIR__, 2) . '/fixtures/clinics.csv'],
      'claim_types' => [],
      'status' => TRUE,
    ])->save();
  }

  /**
   * Sets how the contextual test extractor behaves.
   *
   * @param string $mode
   *   One of find, defer, hold or silent.
   */
  protected function mode(string $mode): void {
    $this->container->get('state')->set('fact_drift_test_contextual.mode', $mode);
  }

  /**
   * Sets the plain extractors the settings enable.
   *
   * @param string[] $ids
   *   The extractor plugin IDs.
   */
  protected function enablePlain(array $ids): void {
    $this->config('fact_drift.settings')->set('extractors', $ids)->save();
  }

  /**
   * Creates a page whose body states a phone number for Riverside.
   *
   * @param string $number
   *   The number as written.
   *
   * @return \Drupal\node\NodeInterface
   *   The saved node.
   */
  protected function createPage(string $number = '909-555-0123'): NodeInterface {
    $node = Node::create([
      'type' => 'page',
      'title' => 'Contact',
      'status' => 1,
      'body' => ['value' => '<p>Riverside Family Clinic: ' . $number . '.</p>', 'format' => 'plain_text'],
    ]);
    $node->save();
    return $node;
  }

  /**
   * Points the page at a number and saves it.
   *
   * @param \Drupal\node\NodeInterface $node
   *   The page.
   * @param string $number
   *   The number as written.
   */
  protected function rewritePage(NodeInterface $node, string $number): void {
    $node->set('body', ['value' => '<p>Riverside Family Clinic: ' . $number . '.</p>', 'format' => 'plain_text']);
    $node->save();
  }

  /**
   * Loads the findings in a state, as extractor and found-value strings.
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

  /**
   * Tests that findings store the plain extractor that opened them.
   */
  public function testScannerStampsPlainClaimsAndFindingsStoreTheExtractor(): void {
    $this->mode('silent');
    $this->createPage();
    $result = $this->container->get('fact_drift.scanner')->scanAll();
    $this->assertSame(1, $result['opened']);
    $this->assertSame(['phone:9095550123'], $this->findings());
  }

  /**
   * Tests that a contextual extractor runs after the plain ones.
   */
  public function testContextualExtractorRunsAfterPlainAndReceivesPriorClaims(): void {
    $this->createPage();
    $result = $this->container->get('fact_drift.scanner')->scanAll();
    $this->assertSame(
      [['body', ['phone']]],
      $this->container->get('state')->get('fact_drift_test_contextual.seen'),
    );
    $this->assertSame(1, $result['claims']);
    $this->assertSame(['phone:9095550123'], $this->findings());
  }

  /**
   * Tests that a claim only the contextual extractor finds keeps its ID.
   */
  public function testContextualFindingCarriesItsOwnExtractor(): void {
    $this->enablePlain(['hours']);
    $this->createPage();
    $result = $this->container->get('fact_drift.scanner')->scanAll();
    $this->assertSame(1, $result['opened']);
    $this->assertSame([self::CONTEXTUAL . ':9095550123'], $this->findings());
    $this->assertSame(
      [['body', []]],
      $this->container->get('state')->get('fact_drift_test_contextual.seen'),
    );
  }

  /**
   * Tests that a deferred field keeps its findings open and reports the delay.
   */
  public function testDeferredFieldKeepsFindingsOpen(): void {
    $this->enablePlain(['hours']);
    $node = $this->createPage();
    $scanner = $this->container->get('fact_drift.scanner');
    $scanner->scanNode($node);
    $this->assertSame([self::CONTEXTUAL . ':9095550123'], $this->findings());

    $this->rewritePage($node, '(909) 555-0100');
    $this->mode('defer');
    $result = $scanner->scanNode($node);
    $this->assertSame(0, $result['closed']);
    $this->assertSame(300, $result['deferred_delay']);
    $this->assertSame('budget', $result['deferred_reason']);
    $this->assertSame([self::CONTEXTUAL . ':9095550123'], $this->findings());

    $this->mode('silent');
    $result = $scanner->scanNode($node);
    $this->assertSame(1, $result['closed']);
    $this->assertSame(0, $result['deferred_delay']);
    $this->assertNull($result['deferred_reason']);
    $this->assertSame([], $this->findings());
  }

  /**
   * Tests that a held field keeps its findings open without a delay.
   */
  public function testHeldFieldKeepsFindingsOpenWithoutDelay(): void {
    $this->enablePlain(['hours']);
    $node = $this->createPage();
    $scanner = $this->container->get('fact_drift.scanner');
    $scanner->scanNode($node);

    $this->rewritePage($node, '(909) 555-0100');
    $this->mode('hold');
    $result = $scanner->scanNode($node);
    $this->assertSame(0, $result['closed']);
    $this->assertSame(0, $result['deferred_delay']);
    $this->assertNull($result['deferred_reason']);
    $this->assertSame([self::CONTEXTUAL . ':9095550123'], $this->findings());
  }

  /**
   * Tests that an inactive contextual extractor is skipped.
   */
  public function testInactiveExtractorLetsFindingsClose(): void {
    $this->enablePlain(['hours']);
    $node = $this->createPage();
    $scanner = $this->container->get('fact_drift.scanner');
    $scanner->scanNode($node);
    $this->container->get('state')->set('fact_drift_test_contextual.seen', []);

    $this->container->get('state')->set('fact_drift_test_contextual.active', FALSE);
    $this->mode('defer');
    $result = $scanner->scanNode($node);
    $this->assertSame(1, $result['closed']);
    $this->assertSame(0, $result['deferred_delay']);
    $this->assertSame([], $result['summaries']);
    $this->assertSame([], $this->container->get('state')->get('fact_drift_test_contextual.seen'));
    $this->assertSame([], $this->findings());
  }

  /**
   * Tests that dropping an extractor from the setting closes its findings.
   */
  public function testRemovingPhoneClosesPhoneFindings(): void {
    $this->mode('silent');
    $node = $this->createPage();
    $scanner = $this->container->get('fact_drift.scanner');
    $this->assertSame(1, $scanner->scanNode($node)['opened']);
    $this->assertSame(['phone:9095550123'], $this->findings());

    $this->enablePlain(['hours']);
    $result = $scanner->scanNode($node);
    $this->assertSame(1, $result['closed']);
    $this->assertSame([], $this->findings());
  }

  /**
   * Tests that a full scan counts deferred nodes by reason and adds summaries.
   */
  public function testScanAllCountsDeferredNodesAndAddsSummaries(): void {
    $this->enablePlain(['hours']);
    $this->createPage();
    $this->mode('defer');
    $result = $this->container->get('fact_drift.scanner')->scanAll();
    $this->assertSame(['budget' => 1], $result['deferred']);
    $this->assertSame([self::CONTEXTUAL => ['calls' => 1]], $result['summaries']);
  }

  /**
   * Tests that the worker requeues a deferred node after recording findings.
   */
  public function testWorkerThrowsDelayedRequeueAfterRecordingFindings(): void {
    $this->mode('defer');
    $node = $this->createPage();
    $worker = $this->container->get('plugin.manager.queue_worker')->createInstance('fact_drift_rescan');
    try {
      $worker->processItem(['nid' => $node->id()]);
      $this->fail('A deferred field must requeue the item.');
    }
    catch (DelayedRequeueException $exception) {
      $this->assertSame(300, $exception->getDelay());
    }
    $this->assertSame(['phone:9095550123'], $this->findings());
  }

  /**
   * Tests that the worker finishes quietly when nothing was deferred.
   */
  public function testWorkerFinishesWhenNothingIsDeferred(): void {
    $this->mode('silent');
    $node = $this->createPage();
    $worker = $this->container->get('plugin.manager.queue_worker')->createInstance('fact_drift_rescan');
    $worker->processItem(['nid' => $node->id()]);
    $this->assertSame(['phone:9095550123'], $this->findings());
  }

}
