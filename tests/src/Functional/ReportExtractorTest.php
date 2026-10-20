<?php

namespace Drupal\Tests\fact_drift\Functional;

use Drupal\fact_drift\Entity\Source;
use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\node\Entity\Node;
use Drupal\node\Entity\NodeType;
use Drupal\Tests\BrowserTestBase;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests the report's extractor marker and extractor filter.
 *
 * @group fact_drift
 * @covers \Drupal\fact_drift\Controller\ReportController
 */
#[RunTestsInSeparateProcesses]
class ReportExtractorTest extends BrowserTestBase {

  /**
   * The ID of the contextual test extractor.
   */
  protected const CONTEXTUAL = 'fact_drift_test_contextual';

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['node', 'fact_drift', 'fact_drift_test_contextual'];

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

    $scanner = $this->container->get('fact_drift.scanner');
    $this->config('fact_drift.settings')->set('extractors', ['hours'])->save();
    $found = $this->createPage('Hilltop page', 'Hilltop Family Clinic: 909-555-0177.');
    $scanner->scanNode($found);
    $this->config('fact_drift.settings')->set('extractors', ['phone', 'hours'])->save();
    $this->container->get('state')->set('fact_drift_test_contextual.mode', 'silent');
    $plain = $this->createPage('Contact us', 'Riverside Family Clinic: 909-555-0123.');
    $scanner->scanNode($plain);
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
   * Tests the marker beside the claim type of a contextual extractor's finding.
   */
  public function testMarkerShowsForContextualFindingsOnly(): void {
    $this->drupalGet('/admin/reports/fact-drift');
    $this->assertSession()->statusCodeEquals(200);
    $marked = $this->xpath('//tr[contains(., "Hilltop page")]//span[contains(@class, "fact-drift-report__marker")]');
    $this->assertCount(1, $marked);
    $this->assertSame('CTX (found by the Contextual test extractor)', preg_replace('/\s+/', ' ', trim($marked[0]->getText())));
    $this->assertSession()->elementExists('xpath', '//tr[contains(., "Hilltop page")]//span[contains(@class, "fact-drift-report__type")]/span[contains(@class, "fact-drift-report__marker")]/span[contains(@class, "visually-hidden")]');
    $this->assertSession()->elementExists('xpath', '//tr[contains(., "Contact us")]//span[contains(@class, "fact-drift-report__type")][normalize-space(.) = "Phone"]');
    $this->assertSession()->elementNotExists('xpath', '//tr[contains(., "Contact us")]//span[contains(@class, "fact-drift-report__marker")]');
  }

  /**
   * Tests that the extractor filter lists the extractors and filters rows.
   */
  public function testExtractorFilterFiltersRows(): void {
    $this->drupalGet('/admin/reports/fact-drift');
    $this->assertSession()->elementTextContains('css', 'nav[aria-label="Filter findings"] h3:nth-of-type(1)', 'State');
    $headings = array_map(static fn ($element): string => $element->getText(), $this->xpath('//nav[@aria-label="Filter findings"]//h3'));
    $this->assertSame(['State', 'Claim type', 'Extractor'], $headings);
    $this->assertSession()->linkExists('Contextual test extractor');
    $this->assertSession()->pageTextContains('Hilltop page');
    $this->assertSession()->pageTextContains('Contact us');

    $this->drupalGet('/admin/reports/fact-drift', ['query' => ['extractor' => self::CONTEXTUAL]]);
    $this->assertSession()->pageTextContains('Hilltop page');
    $this->assertSession()->pageTextNotContains('Contact us');
    $this->assertSession()->elementExists('css', 'nav[aria-label="Filter findings"] a.is-active[aria-current="page"] strong');

    $this->drupalGet('/admin/reports/fact-drift', ['query' => ['extractor' => 'phone']]);
    $this->assertSession()->pageTextContains('Contact us');
    $this->assertSession()->pageTextNotContains('Hilltop page');

    $this->drupalGet('/admin/reports/fact-drift', ['query' => ['extractor' => 'no_such_extractor']]);
    $this->assertSession()->pageTextContains('Contact us');
    $this->assertSession()->pageTextContains('Hilltop page');
  }

  /**
   * Tests that every filter link keeps the other two filters.
   */
  public function testFilterLinksKeepStateTypeAndExtractor(): void {
    $this->drupalGet('/admin/reports/fact-drift', [
      'query' => ['state' => 'open', 'type' => 'phone', 'extractor' => self::CONTEXTUAL],
    ]);
    $state = $this->groupLinks(1);
    $type = $this->groupLinks(2);
    $extractor = $this->groupLinks(3);
    foreach (['Open', 'Closed', 'Dismissed'] as $label) {
      $this->assertStringContainsString('type=phone', $state[$label]);
      $this->assertStringContainsString('extractor=' . self::CONTEXTUAL, $state[$label]);
    }
    $this->assertStringContainsString('state=open', $type['Hours']);
    $this->assertStringContainsString('extractor=' . self::CONTEXTUAL, $type['Hours']);
    $this->assertStringContainsString('extractor=' . self::CONTEXTUAL, $type['All']);
    $this->assertSame(['All', 'Phone', 'Hours'], array_keys($type));
    $this->assertSame(['All', 'Phone patterns', 'Hours patterns', 'Contextual test extractor'], array_keys($extractor));
    $this->assertStringNotContainsString('extractor=', $extractor['All']);
    $this->assertStringContainsString('type=phone', $extractor['All']);
    $this->assertStringContainsString('type=phone', $extractor['Contextual test extractor']);
    $this->assertStringContainsString('state=open', $extractor['Contextual test extractor']);
  }

  /**
   * Collects the links of one filter group.
   *
   * @param int $group
   *   The position of the group in the filter navigation, from 1.
   *
   * @return array<string, string>
   *   The link URLs keyed by link text.
   */
  protected function groupLinks(int $group): array {
    $links = [];
    foreach ($this->xpath('//nav[@aria-label="Filter findings"]/div[' . $group . ']//a') as $link) {
      $links[trim($link->getText())] = (string) $link->getAttribute('href');
    }
    return $links;
  }

  /**
   * Tests that the empty text names the extractor filter.
   */
  public function testEmptyTextNamesTheExtractor(): void {
    $this->drupalGet('/admin/reports/fact-drift', ['query' => ['type' => 'hours', 'extractor' => self::CONTEXTUAL]]);
    $this->assertSession()->pageTextContains('No open findings of claim type Hours found by Contextual test extractor.');
    $this->assertSession()->linkExists('Show all open findings');

    $this->drupalGet('/admin/reports/fact-drift', ['query' => ['state' => 'closed', 'extractor' => self::CONTEXTUAL]]);
    $this->assertSession()->pageTextContains('No closed findings found by Contextual test extractor.');
  }

}
