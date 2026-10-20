<?php

namespace Drupal\Tests\fact_drift\Kernel;

use Drupal\fact_drift\Entity\Finding;
use Drupal\fact_drift\Entity\Source;
use Drupal\fact_drift\Scanner;
use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\KernelTests\KernelTestBase;
use Drupal\node\Entity\Node;
use Drupal\node\Entity\NodeType;
use Drupal\node\NodeInterface;

/**
 * Tests scanning real nodes against the fixture through a source entity.
 *
 * @group fact_drift
 * @covers \Drupal\fact_drift\Scanner
 * @covers \Drupal\fact_drift\Plugin\FactSource\CsvSource
 */
class ScannerTest extends KernelTestBase {

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
   * The scanner under test.
   */
  protected Scanner $scanner;

  /**
   * The absolute path of the working copy of the fixture.
   */
  protected string $csvPath;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('user');
    $this->installEntitySchema('node');
    $this->installEntitySchema('fact_drift_finding');
    $this->installConfig(['node', 'filter', 'fact_drift']);
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
    $directory = $this->siteDirectory . '/fact-drift';
    mkdir($directory, 0775, TRUE);
    $this->csvPath = $directory . '/clinics.csv';
    copy(dirname(__DIR__, 2) . '/fixtures/clinics.csv', $this->csvPath);
    $this->createSource($this->csvPath);
    $this->scanner = $this->container->get('fact_drift.scanner');
  }

  /**
   * Creates the clinics source.
   *
   * @param string $path
   *   The absolute path of the CSV file.
   * @param string[] $claimTypes
   *   The claim types the source supplies, empty for all.
   */
  protected function createSource(string $path, array $claimTypes = []): void {
    Source::create([
      'id' => 'clinics',
      'label' => 'Clinics',
      'plugin' => 'csv',
      'plugin_settings' => ['path' => $path],
      'claim_types' => $claimTypes,
      'status' => TRUE,
    ])->save();
  }

  /**
   * Creates a page.
   *
   * @param string $body
   *   The body markup.
   * @param bool $published
   *   Whether the page is published.
   *
   * @return \Drupal\node\NodeInterface
   *   The saved node.
   */
  protected function createPage(string $body, bool $published = TRUE): NodeInterface {
    $node = Node::create([
      'type' => 'page',
      'title' => 'Contact',
      'status' => $published ? 1 : 0,
      'body' => ['value' => $body, 'format' => 'plain_text'],
    ]);
    $node->save();
    return $node;
  }

  /**
   * Rewrites the working copy of the fixture in place.
   *
   * @param string $search
   *   The text to replace.
   * @param string $replace
   *   The replacement.
   */
  protected function editCsv(string $search, string $replace): void {
    $csv = file_get_contents($this->csvPath);
    $this->assertStringContainsString($search, $csv);
    file_put_contents($this->csvPath, str_replace($search, $replace, $csv));
    touch($this->csvPath, time() + 60 + random_int(1, 100000));
    clearstatcache(TRUE, $this->csvPath);
  }

  /**
   * Lists the open findings as found-value, fact-key and claim-type strings.
   *
   * @return string[]
   *   Sorted descriptions of the open findings.
   */
  protected function openFindings(): array {
    $storage = $this->container->get('entity_type.manager')->getStorage('fact_drift_finding');
    $storage->resetCache();
    $rows = [];
    foreach ($storage->loadByProperties(['state' => Finding::STATE_OPEN]) as $finding) {
      $this->assertInstanceOf(Finding::class, $finding);
      $rows[] = $finding->getFactKey() . ':' . $finding->getClaimType() . ':' . $finding->getFound();
    }
    sort($rows);
    return $rows;
  }

  /**
   * Returns the correct text for the Riverside page.
   *
   * @return string
   *   The body markup.
   */
  protected function riversideBody(): string {
    return '<p>Riverside Family Clinic is here for you. Call (909) 555-0100.</p><p>Riverside Family Clinic hours: Mon-Fri 8am-5pm; Sat 9am-12pm.</p>';
  }

  /**
   * Tests that the fixture yields a phone and an hours fact per clinic.
   */
  public function testFixtureLoadsFacts(): void {
    $loaded = $this->scanner->loadFacts();
    $this->assertSame(['clinics'], $loaded['loaded']);
    $this->assertSame([], $loaded['failed']);
    $this->assertCount(6, $loaded['facts']);
    $phones = [];
    foreach ($loaded['facts'] as $fact) {
      $this->assertSame('clinics', $fact->sourceId);
      if ($fact->claimType === 'phone') {
        $phones[$fact->key] = $fact->value;
      }
    }
    $this->assertSame(['riverside' => '9095550100', 'hilltop' => '9095550142', 'lakeview' => '9095550163'], $phones);
  }

  /**
   * Tests that a page repeating the facts correctly opens nothing.
   */
  public function testCorrectPageOpensNothing(): void {
    $node = $this->createPage($this->riversideBody());
    $result = $this->scanner->scanNode($node);
    $this->assertSame(0, $result['opened']);
    $this->assertSame(2, $result['claims']);
    $this->assertSame([], $result['failed']);
    $this->assertSame([], $this->openFindings());
  }

  /**
   * Tests that a wrong number and a retired number open findings.
   */
  public function testWrongAndRetiredNumbersOpenFindings(): void {
    $this->createPage('<p>Riverside Family Clinic: 909-555-0123. Old line 909-555-0199.</p>');
    $result = $this->scanner->scanAll();
    $this->assertSame(2, $result['opened']);
    $this->assertSame([
      'riverside:phone:9095550123',
      'riverside:phone:9095550199',
    ], $this->openFindings());
  }

  /**
   * Tests that base fields such as the revision log are never scanned.
   */
  public function testBaseFieldsAreNotScanned(): void {
    $node = Node::create([
      'type' => 'page',
      'title' => 'Riverside Family Clinic 909-555-0123',
      'status' => 1,
      'body' => ['value' => '<p>Nothing to see.</p>', 'format' => 'plain_text'],
      'revision_log' => 'Riverside Family Clinic: 909-555-0123.',
    ]);
    $node->save();
    $result = $this->scanner->scanNode($node);
    $this->assertSame(0, $result['claims']);
    $this->assertSame(0, $result['opened']);
    $this->assertSame([], $this->openFindings());
  }

  /**
   * Tests that an unpublished node is not scanned.
   */
  public function testUnpublishedNodeIsSkipped(): void {
    $node = $this->createPage('<p>Riverside Family Clinic: 909-555-0123.</p>', FALSE);
    $result = $this->scanner->scanNode($node);
    $this->assertSame(0, $result['claims']);
    $this->assertSame(0, $result['opened']);
    $result = $this->scanner->scanAll();
    $this->assertSame(0, $result['opened']);
    $this->assertSame([], $this->openFindings());
  }

  /**
   * Tests that a node of an unconfigured type is not scanned.
   */
  public function testUnconfiguredTypeIsSkipped(): void {
    NodeType::create(['type' => 'blog', 'name' => 'Blog'])->save();
    FieldConfig::create([
      'field_name' => 'body',
      'entity_type' => 'node',
      'bundle' => 'blog',
      'label' => 'Body',
    ])->save();
    $node = Node::create([
      'type' => 'blog',
      'title' => 'Post',
      'status' => 1,
      'body' => ['value' => '<p>Riverside Family Clinic: 909-555-0123.</p>', 'format' => 'plain_text'],
    ]);
    $node->save();
    $this->assertSame(0, $this->scanner->scanNode($node)['opened']);
  }

  /**
   * Tests that a changed CSV opens and closes the right findings.
   */
  public function testChangedCsvOpensAndClosesFindings(): void {
    $this->createPage($this->riversideBody());
    $this->assertSame(0, $this->scanner->scanAll()['opened']);

    $this->editCsv('(909) 555-0100', '(909) 555-0111');
    $result = $this->scanner->scanAll();
    $this->assertSame(1, $result['opened']);
    $this->assertSame(0, $result['closed']);
    $this->assertSame(['riverside:phone:9095550100'], $this->openFindings());

    $this->editCsv('Mon-Fri 8am-5pm; Sat 9am-12pm', 'Mon-Fri 9am-5pm; Sat 9am-12pm');
    $result = $this->scanner->scanAll();
    $this->assertSame(1, $result['opened']);
    $this->assertSame([
      'riverside:hours:Mon-Fri 08:00-17:00; Sat 09:00-12:00',
      'riverside:phone:9095550100',
    ], $this->openFindings());

    $this->editCsv('(909) 555-0111', '(909) 555-0100');
    $result = $this->scanner->scanAll();
    $this->assertSame(0, $result['opened']);
    $this->assertSame(1, $result['closed']);
    $this->assertSame(['riverside:hours:Mon-Fri 08:00-17:00; Sat 09:00-12:00'], $this->openFindings());

    $this->editCsv('Mon-Fri 9am-5pm; Sat 9am-12pm', 'Mon-Fri 8am-5pm; Sat 9am-12pm');
    $result = $this->scanner->scanAll();
    $this->assertSame(1, $result['closed']);
    $this->assertSame([], $this->openFindings());
  }

  /**
   * Tests that an in-place file edit is noticed without saving any config.
   */
  public function testInPlaceEditIsPickedUpWithoutConfigSave(): void {
    $this->createPage($this->riversideBody());
    $source = Source::load('clinics');
    $this->assertSame(0, $this->scanner->scanAll()['opened']);
    $fingerprint = $this->container->get('fact_drift.source_manager')
      ->createInstance('csv')
      ->fingerprint($source->getPluginSettings());
    $this->editCsv('(909) 555-0100', '(909) 555-0112');
    $changed = $this->container->get('fact_drift.source_manager')
      ->createInstance('csv')
      ->fingerprint($source->getPluginSettings());
    $this->assertNotSame($fingerprint, $changed);
    $this->assertSame(1, $this->scanner->scanAll()['opened']);
  }

  /**
   * Tests that numbers in adjacent table cells are both read.
   */
  public function testAdjacentTagsDoNotMerge(): void {
    $node = $this->createPage('<table><tr><td>Riverside Family Clinic</td><td>909-555-0199</td><td>909-555-0123</td></tr></table>');
    $result = $this->scanner->scanNode($node);
    $this->assertSame(2, $result['claims']);
    $this->assertSame(1, $result['opened']);
    $this->assertSame(['riverside:phone:9095550199'], $this->openFindings());
  }

  /**
   * Tests that a source limited to one claim type supplies only those facts.
   */
  public function testClaimTypesFilterFacts(): void {
    Source::load('clinics')->delete();
    $this->createSource($this->csvPath, ['phone']);
    $loaded = $this->scanner->loadFacts();
    $this->assertCount(3, $loaded['facts']);
    foreach ($loaded['facts'] as $fact) {
      $this->assertSame('phone', $fact->claimType);
    }
    $this->createPage('<p>Riverside Family Clinic hours: Mon-Fri 6am-7pm.</p>');
    $this->assertSame(0, $this->scanner->scanAll()['opened']);
  }

  /**
   * Tests that an unreadable source is reported and leaves findings open.
   */
  public function testFailedSourceLeavesFindingsOpen(): void {
    $this->createPage('<p>Riverside Family Clinic: 909-555-0123.</p>');
    $this->assertSame(1, $this->scanner->scanAll()['opened']);
    unlink($this->csvPath);
    clearstatcache(TRUE, $this->csvPath);
    $result = $this->scanner->scanAll();
    $this->assertSame(['clinics'], $result['failed']);
    $this->assertSame(0, $result['closed']);
    $this->assertSame(['riverside:phone:9095550123'], $this->openFindings());
  }

  /**
   * Tests that the limit bounds the number of nodes scanned.
   */
  public function testLimitBoundsTheScan(): void {
    $this->createPage('<p>Riverside Family Clinic: 909-555-0123.</p>');
    $this->createPage('<p>Riverside Family Clinic: 909-555-0124.</p>');
    $this->assertSame(1, $this->scanner->scanAll(NULL, 1)['opened']);
    $this->assertSame(1, $this->scanner->scanAll()['opened']);
  }

}
