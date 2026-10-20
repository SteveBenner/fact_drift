<?php

namespace Drupal\Tests\fact_drift\Functional;

use Drupal\fact_drift\Entity\Finding;
use Drupal\fact_drift\Entity\Source;
use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\node\Entity\Node;
use Drupal\node\Entity\NodeType;
use Drupal\Tests\BrowserTestBase;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests that dismissing a finding is protected by a token.
 *
 * @group fact_drift
 * @covers \Drupal\fact_drift\Controller\FindingActionController
 */
#[RunTestsInSeparateProcesses]
class DismissTest extends BrowserTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['node', 'fact_drift'];

  /**
   * {@inheritdoc}
   */
  protected $defaultTheme = 'stark';

  /**
   * The finding under test.
   */
  protected Finding $finding;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
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
    Source::create([
      'id' => 'clinics',
      'label' => 'Clinics',
      'plugin' => 'csv',
      'plugin_settings' => ['path' => dirname(__DIR__, 2) . '/fixtures/clinics.csv'],
      'claim_types' => [],
      'status' => TRUE,
    ])->save();
    Node::create([
      'type' => 'page',
      'title' => 'Contact us',
      'status' => 1,
      'body' => ['value' => '<p>Riverside Family Clinic: call 909-555-0123 today.</p>', 'format' => 'plain_text'],
    ])->save();
    $this->container->get('fact_drift.scanner')->scanAll();
    $findings = $this->container->get('entity_type.manager')->getStorage('fact_drift_finding')->loadMultiple();
    $this->assertCount(1, $findings);
    $finding = reset($findings);
    $this->assertInstanceOf(Finding::class, $finding);
    $this->finding = $finding;
  }

  /**
   * Reloads the finding from storage.
   *
   * @return \Drupal\fact_drift\Entity\Finding
   *   The fresh finding.
   */
  protected function reloadFinding(): Finding {
    $storage = $this->container->get('entity_type.manager')->getStorage('fact_drift_finding');
    $storage->resetCache();
    $finding = $storage->load($this->finding->id());
    $this->assertInstanceOf(Finding::class, $finding);
    return $finding;
  }

  /**
   * Tests that a request without a token is refused.
   */
  public function testMissingTokenIsRefused(): void {
    $this->drupalLogin($this->drupalCreateUser(['view fact drift findings', 'manage fact drift findings']));
    $this->drupalGet('/admin/reports/fact-drift/' . $this->finding->id() . '/dismiss');
    $this->assertSession()->statusCodeEquals(403);
    $this->assertSame(Finding::STATE_OPEN, $this->reloadFinding()->getState());
  }

  /**
   * Tests that a request with a wrong token is refused.
   */
  public function testWrongTokenIsRefused(): void {
    $this->drupalLogin($this->drupalCreateUser(['view fact drift findings', 'manage fact drift findings']));
    $this->drupalGet('/admin/reports/fact-drift/' . $this->finding->id() . '/dismiss', ['query' => ['token' => 'not-the-token']]);
    $this->assertSession()->statusCodeEquals(403);
    $this->assertSame(Finding::STATE_OPEN, $this->reloadFinding()->getState());
  }

  /**
   * Tests that a user without the permission sees no link and is refused.
   */
  public function testViewerCannotDismiss(): void {
    $this->drupalLogin($this->drupalCreateUser(['view fact drift findings']));
    $this->drupalGet('/admin/reports/fact-drift');
    $this->assertSession()->elementNotExists('css', '.fact-drift-report__ops a');
    $this->drupalGet('/admin/reports/fact-drift/' . $this->finding->id() . '/dismiss');
    $this->assertSession()->statusCodeEquals(403);
    $this->assertSame(Finding::STATE_OPEN, $this->reloadFinding()->getState());
  }

  /**
   * Tests that dismissing under filters returns to the report with them.
   */
  public function testDismissKeepsStateTypeAndExtractorFilters(): void {
    $this->drupalLogin($this->drupalCreateUser(['view fact drift findings', 'manage fact drift findings']));
    $this->drupalGet('/admin/reports/fact-drift', [
      'query' => ['state' => 'open', 'type' => 'phone', 'extractor' => 'phone'],
    ]);
    $link = $this->assertSession()->elementExists('css', '.fact-drift-report__ops a');
    $this->assertStringContainsString('extractor=phone', (string) $link->getAttribute('href'));
    $link->click();
    $this->assertSession()->pageTextContains('Finding dismissed.');
    $url = parse_url($this->getUrl());
    parse_str($url['query'] ?? '', $query);
    $this->assertSame('/admin/reports/fact-drift', $url['path']);
    $this->assertSame(['state' => 'open', 'type' => 'phone', 'extractor' => 'phone'], $query);
  }

  /**
   * Tests that the link from the report dismisses and records the reason.
   */
  public function testReportLinkDismisses(): void {
    $user = $this->drupalCreateUser(['view fact drift findings', 'manage fact drift findings']);
    $this->drupalLogin($user);
    $this->drupalGet('/admin/reports/fact-drift');
    $this->assertSession()->elementExists('css', '.fact-drift-report__ops a')->click();
    $this->assertSession()->statusCodeEquals(200);
    $this->assertSession()->pageTextContains('Finding dismissed.');
    $finding = $this->reloadFinding();
    $this->assertSame(Finding::STATE_DISMISSED, $finding->getState());
    $this->assertSame('Dismissed from the report by ' . $user->getDisplayName(), $finding->get('dismiss_reason')->value);
    $this->assertSession()->elementNotExists('css', '.fact-drift-report__ops a');
  }

}
