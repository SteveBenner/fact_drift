<?php

namespace Drupal\Tests\fact_drift\Functional;

use Drupal\fact_drift\Entity\Source;
use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\node\Entity\Node;
use Drupal\node\Entity\NodeType;
use Drupal\Tests\BrowserTestBase;

/**
 * Tests who may open the report and how its evidence is rendered.
 *
 * @group fact_drift
 * @covers \Drupal\fact_drift\Controller\ReportController
 */
class ReportAccessTest extends BrowserTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['node', 'fact_drift'];

  /**
   * {@inheritdoc}
   */
  protected $defaultTheme = 'stark';

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
  }

  /**
   * Creates a page that repeats a wrong number and scans it.
   *
   * @param string $body
   *   The body markup.
   */
  protected function createScannedPage(string $body): void {
    Node::create([
      'type' => 'page',
      'title' => 'Contact us',
      'status' => 1,
      'body' => ['value' => $body, 'format' => 'plain_text'],
    ])->save();
    $this->container->get('fact_drift.scanner')->scanAll();
  }

  /**
   * Tests that the report needs its permission.
   */
  public function testReportRequiresPermission(): void {
    $this->drupalGet('/admin/reports/fact-drift');
    $this->assertSession()->statusCodeEquals(403);

    $this->drupalLogin($this->drupalCreateUser(['access administration pages']));
    $this->drupalGet('/admin/reports/fact-drift');
    $this->assertSession()->statusCodeEquals(403);

    $this->drupalLogin($this->drupalCreateUser(['view fact drift findings']));
    $this->drupalGet('/admin/reports/fact-drift');
    $this->assertSession()->statusCodeEquals(200);
  }

  /**
   * Tests that open findings show their evidence.
   */
  public function testFindingsAreVisible(): void {
    $this->createScannedPage('<p>Riverside Family Clinic: call 909-555-0123 today.</p>');
    $this->drupalLogin($this->drupalCreateUser(['view fact drift findings']));
    $this->drupalGet('/admin/reports/fact-drift');
    $this->assertSession()->statusCodeEquals(200);
    $this->assertSession()->pageTextContains('Contact us');
    $this->assertSession()->pageTextContains('9095550100');
    $this->assertSession()->pageTextContains('9095550123');
    $this->assertSession()->pageTextContains('Riverside Family Clinic: call 909-555-0123 today.');
    $this->assertSession()->elementNotExists('css', '.fact-drift-report__ops a');
  }

  /**
   * Tests that a snippet containing markup renders as text.
   */
  public function testSnippetIsEscaped(): void {
    $this->createScannedPage('<p>Riverside Family Clinic 909-555-0123 &lt;script&gt;alert(1)&lt;/script&gt;</p>');
    $this->drupalLogin($this->drupalCreateUser(['view fact drift findings']));
    $this->drupalGet('/admin/reports/fact-drift');
    $this->assertSession()->statusCodeEquals(200);
    $this->assertSession()->pageTextContains('<script>alert(1)</script>');
    $this->assertSession()->responseContains('&lt;script&gt;alert(1)&lt;/script&gt;');
    $this->assertSession()->responseNotContains('<script>alert(1)</script>');
  }

}
