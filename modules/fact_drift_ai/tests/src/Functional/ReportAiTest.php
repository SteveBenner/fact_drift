<?php

namespace Drupal\Tests\fact_drift_ai\Functional;

use Drupal\fact_drift\Entity\Source;
use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\node\Entity\Node;
use Drupal\node\Entity\NodeType;
use Drupal\Tests\BrowserTestBase;
use Drupal\user\Entity\Role;
use Drupal\user\RoleInterface;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests the report's AI marker and extractor filter for real AI findings.
 *
 * @group fact_drift_ai
 * @covers \Drupal\fact_drift\Controller\ReportController
 * @covers \Drupal\fact_drift_ai\Plugin\ClaimExtractor\LlmExtractor
 */
#[RunTestsInSeparateProcesses]
class ReportAiTest extends BrowserTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['node', 'fact_drift', 'fact_drift_ai', 'fact_drift_ai_test'];

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
    Role::load(RoleInterface::ANONYMOUS_ID)->grantPermission('access content')->save();
    Source::create([
      'id' => 'clinics',
      'label' => 'Clinics',
      'plugin' => 'csv',
      'plugin_settings' => ['path' => dirname(__DIR__, 5) . '/tests/fixtures/clinics.csv'],
      'claim_types' => [],
      'status' => TRUE,
    ])->save();
    $this->config('fact_drift_ai.settings')
      ->set('enabled', TRUE)
      ->set('provider', 'fact_drift_ai_test')
      ->set('model', 'scripted')
      ->set('allowlist', [['bundle' => 'page', 'field' => 'body']])
      ->set('min_interval_ms', 0)
      ->save();
    $this->container->get('state')->set('fact_drift_ai_test.script', [
      ['reply' => '{"claims": [{"type": "hours", "quote": "Monday through Friday", "value": "Mon-Fri 08:00-17:00"}]}'],
    ]);
    $scanner = $this->container->get('fact_drift.scanner');
    $scanner->scanNode($this->createPage('Hilltop page', 'Hilltop Medical Group is open from seven in the morning until six, Monday through Friday.'));
    $scanner->scanNode($this->createPage('Contact us', 'Riverside Family Clinic: 909-555-0123.'));
    $this->drupalLogin($this->drupalCreateUser(['view fact drift findings']));
  }

  /**
   * Creates a published page.
   *
   * @param string $title
   *   The title.
   * @param string $text
   *   The body text.
   *
   * @return \Drupal\node\NodeInterface
   *   The saved node.
   */
  protected function createPage(string $title, string $text) {
    $node = Node::create([
      'type' => 'page',
      'title' => $title,
      'status' => 1,
      'body' => ['value' => '<p>' . $text . '</p>', 'format' => 'plain_text'],
    ]);
    $node->save();
    return $node;
  }

  /**
   * Tests the marker beside the claim type of the AI finding only.
   */
  public function testAiMarkerShowsForAiFindingsOnly(): void {
    $this->drupalGet('/admin/reports/fact-drift');
    $this->assertSession()->statusCodeEquals(200);
    $marked = $this->xpath('//tr[contains(., "Hilltop page")]//span[contains(@class, "fact-drift-report__marker")]');
    $this->assertCount(1, $marked);
    $this->assertSame('AI (found by the AI extractor)', preg_replace('/\s+/', ' ', trim($marked[0]->getText())));
    $this->assertSession()->elementExists('xpath', '//tr[contains(., "Contact us")]//span[contains(@class, "fact-drift-report__type")][normalize-space(.) = "Phone"]');
    $this->assertSession()->elementNotExists('xpath', '//tr[contains(., "Contact us")]//span[contains(@class, "fact-drift-report__marker")]');
  }

  /**
   * Tests that the filter lists the AI extractor and filters rows.
   */
  public function testExtractorFilterListsTheAiExtractor(): void {
    $this->drupalGet('/admin/reports/fact-drift');
    $this->assertSession()->linkExists('AI extractor');
    $this->assertSession()->pageTextContains('Hilltop page');
    $this->assertSession()->pageTextContains('Contact us');

    $this->drupalGet('/admin/reports/fact-drift', ['query' => ['extractor' => 'llm']]);
    $this->assertSession()->pageTextContains('Hilltop page');
    $this->assertSession()->pageTextNotContains('Contact us');

    $this->drupalGet('/admin/reports/fact-drift', ['query' => ['extractor' => 'phone']]);
    $this->assertSession()->pageTextContains('Contact us');
    $this->assertSession()->pageTextNotContains('Hilltop page');
  }

  /**
   * Tests that the empty text names the AI extractor.
   */
  public function testEmptyTextNamesTheAiExtractor(): void {
    $this->drupalGet('/admin/reports/fact-drift', ['query' => ['state' => 'closed', 'extractor' => 'llm']]);
    $this->assertSession()->pageTextContains('No closed findings found by AI extractor.');
  }

}
