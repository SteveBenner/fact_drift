<?php

namespace Drupal\Tests\fact_drift\Kernel;

use Drupal\fact_drift\Entity\Finding;
use Drupal\fact_drift\FindingRecorder;
use Drupal\fact_drift\Value\Claim;
use Drupal\fact_drift\Value\Fact;
use Drupal\fact_drift\Value\Mismatch;
use Drupal\KernelTests\KernelTestBase;
use Drupal\node\Entity\Node;
use Drupal\node\Entity\NodeType;
use Drupal\node\NodeInterface;

/**
 * Tests that the recorder opens, closes, de-duplicates and dismisses findings.
 *
 * @group fact_drift
 * @covers \Drupal\fact_drift\FindingRecorder
 */
class FindingRecorderTest extends KernelTestBase {

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
  ];

  /**
   * The recorder under test.
   */
  protected FindingRecorder $recorder;

  /**
   * A published page.
   */
  protected NodeInterface $node;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('user');
    $this->installEntitySchema('node');
    $this->installEntitySchema('fact_drift_finding');
    $this->installConfig(['node', 'fact_drift']);
    NodeType::create(['type' => 'page', 'name' => 'Page'])->save();
    $this->recorder = $this->container->get('fact_drift.finding_recorder');
    $this->node = $this->createPage();
  }

  /**
   * Creates a published page.
   *
   * @return \Drupal\node\NodeInterface
   *   The saved node.
   */
  protected function createPage(): NodeInterface {
    $node = Node::create(['type' => 'page', 'title' => 'Contact', 'status' => 1]);
    $node->save();
    return $node;
  }

  /**
   * Builds a phone mismatch.
   *
   * @param string $found
   *   The wrong number found in the text.
   * @param string $source
   *   The ID of the source that holds the fact.
   * @param string $type
   *   The claim type.
   *
   * @return \Drupal\fact_drift\Value\Mismatch
   *   The mismatch.
   */
  protected function mismatch(string $found = '9095550123', string $source = 'clinics', string $type = 'phone'): Mismatch {
    $fact = new Fact($source, 'riverside', 'Riverside Family Clinic', [], $type, '9095550100');
    $claim = new Claim($type, $found, $found, 20, strlen($found), 'Riverside Family Clinic ' . $found);
    return new Mismatch('body', $fact, $claim);
  }

  /**
   * Loads every finding.
   *
   * @return \Drupal\fact_drift\Entity\Finding[]
   *   The findings, keyed by ID.
   */
  protected function findings(): array {
    $storage = $this->container->get('entity_type.manager')->getStorage('fact_drift_finding');
    $storage->resetCache();
    $findings = [];
    foreach ($storage->loadMultiple() as $id => $finding) {
      $this->assertInstanceOf(Finding::class, $finding);
      $findings[$id] = $finding;
    }
    return $findings;
  }

  /**
   * Tests that a mismatch opens a finding that records its evidence.
   */
  public function testMismatchOpensFinding(): void {
    $result = $this->recorder->sync($this->node, [$this->mismatch()], ['clinics']);
    $this->assertSame(['opened' => 1, 'closed' => 0], $result);
    $findings = array_values($this->findings());
    $this->assertCount(1, $findings);
    $finding = $findings[0];
    $this->assertSame(Finding::STATE_OPEN, $finding->getState());
    $this->assertSame((int) $this->node->id(), $finding->getNodeId());
    $this->assertSame('body', $finding->getFieldName());
    $this->assertSame('phone', $finding->getClaimType());
    $this->assertSame('riverside', $finding->getFactKey());
    $this->assertSame('clinics', $finding->getSourceId());
    $this->assertSame('9095550100', $finding->getExpected());
    $this->assertSame('9095550123', $finding->getFound());
    $this->assertSame('Riverside Family Clinic 9095550123', $finding->getSnippet());
  }

  /**
   * Tests that a rescan with the same mismatch opens no duplicate.
   */
  public function testRescanDoesNotDuplicate(): void {
    $this->recorder->sync($this->node, [$this->mismatch()], ['clinics']);
    $result = $this->recorder->sync($this->node, [$this->mismatch()], ['clinics']);
    $this->assertSame(['opened' => 0, 'closed' => 0], $result);
    $this->assertCount(1, $this->findings());
  }

  /**
   * Tests that the claim type is part of a finding's identity.
   */
  public function testClaimTypeIsPartOfTheIdentity(): void {
    $this->recorder->sync($this->node, [$this->mismatch('9095550123', 'clinics', 'phone')], ['clinics']);
    $result = $this->recorder->sync($this->node, [
      $this->mismatch('9095550123', 'clinics', 'phone'),
      $this->mismatch('9095550123', 'clinics', 'hours'),
    ], ['clinics']);
    $this->assertSame(1, $result['opened']);
    $this->assertCount(2, $this->findings());
  }

  /**
   * Tests that a different wrong value opens a second finding.
   */
  public function testDifferentFoundValueOpensAnother(): void {
    $this->recorder->sync($this->node, [$this->mismatch('9095550123')], ['clinics']);
    $result = $this->recorder->sync($this->node, [$this->mismatch('9095550123'), $this->mismatch('9095550124')], ['clinics']);
    $this->assertSame(['opened' => 1, 'closed' => 0], $result);
    $this->assertCount(2, $this->findings());
  }

  /**
   * Tests that a finding closes once its mismatch is gone.
   */
  public function testFindingClosesWhenGone(): void {
    $this->recorder->sync($this->node, [$this->mismatch()], ['clinics']);
    $result = $this->recorder->sync($this->node, [], ['clinics']);
    $this->assertSame(['opened' => 0, 'closed' => 1], $result);
    $finding = array_values($this->findings())[0];
    $this->assertSame(Finding::STATE_CLOSED, $finding->getState());
    $this->assertGreaterThan(0, (int) $finding->get('closed')->value);
  }

  /**
   * Tests that a closed finding is not closed again.
   */
  public function testClosedFindingIsNotClosedTwice(): void {
    $this->recorder->sync($this->node, [$this->mismatch()], ['clinics']);
    $this->recorder->sync($this->node, [], ['clinics']);
    $result = $this->recorder->sync($this->node, [], ['clinics']);
    $this->assertSame(['opened' => 0, 'closed' => 0], $result);
  }

  /**
   * Tests that the same mismatch after closing opens a new finding.
   */
  public function testReturningMismatchOpensNewFinding(): void {
    $this->recorder->sync($this->node, [$this->mismatch()], ['clinics']);
    $this->recorder->sync($this->node, [], ['clinics']);
    $result = $this->recorder->sync($this->node, [$this->mismatch()], ['clinics']);
    $this->assertSame(1, $result['opened']);
    $this->assertCount(2, $this->findings());
  }

  /**
   * Tests that a dismissed finding is not reopened for the same value.
   */
  public function testDismissedFindingIsNotReopened(): void {
    $this->recorder->sync($this->node, [$this->mismatch()], ['clinics']);
    $finding = array_values($this->findings())[0];
    $this->recorder->dismiss($finding, 'Dismissed from the report by Test');
    $result = $this->recorder->sync($this->node, [$this->mismatch()], ['clinics']);
    $this->assertSame(['opened' => 0, 'closed' => 0], $result);
    $findings = array_values($this->findings());
    $this->assertCount(1, $findings);
    $this->assertSame(Finding::STATE_DISMISSED, $findings[0]->getState());
    $this->assertSame('Dismissed from the report by Test', $findings[0]->get('dismiss_reason')->value);
  }

  /**
   * Tests that a dismissed finding stays dismissed when the mismatch is gone.
   */
  public function testDismissedFindingIsNotClosed(): void {
    $this->recorder->sync($this->node, [$this->mismatch()], ['clinics']);
    $this->recorder->dismiss(array_values($this->findings())[0], 'Dismissed from the report by Test');
    $result = $this->recorder->sync($this->node, [], ['clinics']);
    $this->assertSame(0, $result['closed']);
    $this->assertSame(Finding::STATE_DISMISSED, array_values($this->findings())[0]->getState());
  }

  /**
   * Tests that closeForNode closes the open findings of one node only.
   */
  public function testCloseForNode(): void {
    $other = $this->createPage();
    $this->recorder->sync($this->node, [$this->mismatch('9095550123'), $this->mismatch('9095550124')], ['clinics']);
    $this->recorder->sync($other, [$this->mismatch('9095550125')], ['clinics']);
    $this->assertSame(2, $this->recorder->closeForNode((int) $this->node->id()));
    $states = [];
    foreach ($this->findings() as $finding) {
      $states[$finding->getNodeId()][] = $finding->getState();
    }
    $this->assertSame([Finding::STATE_CLOSED, Finding::STATE_CLOSED], $states[(int) $this->node->id()]);
    $this->assertSame([Finding::STATE_OPEN], $states[(int) $other->id()]);
  }

  /**
   * Tests that closeForNode leaves dismissed findings alone.
   */
  public function testCloseForNodeSkipsDismissed(): void {
    $this->recorder->sync($this->node, [$this->mismatch()], ['clinics']);
    $this->recorder->dismiss(array_values($this->findings())[0], 'Dismissed from the report by Test');
    $this->assertSame(0, $this->recorder->closeForNode((int) $this->node->id()));
    $this->assertSame(Finding::STATE_DISMISSED, array_values($this->findings())[0]->getState());
  }

  /**
   * Tests that findings of a source that was not read stay open.
   */
  public function testFailedSourceLeavesFindingsOpen(): void {
    $this->recorder->sync($this->node, [$this->mismatch()], ['clinics']);
    $result = $this->recorder->sync($this->node, [], []);
    $this->assertSame(['opened' => 0, 'closed' => 0], $result);
    $this->assertSame(Finding::STATE_OPEN, array_values($this->findings())[0]->getState());
    $result = $this->recorder->sync($this->node, [], ['other']);
    $this->assertSame(0, $result['closed']);
    $this->assertSame(Finding::STATE_OPEN, array_values($this->findings())[0]->getState());
  }

}
