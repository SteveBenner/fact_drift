<?php

namespace Drupal\Tests\fact_drift\Functional;

use Drupal\node\Entity\NodeType;
use Drupal\Tests\BrowserTestBase;

/**
 * Tests that the settings form saves every element to configuration.
 *
 * @group fact_drift
 * @covers \Drupal\fact_drift\Form\SettingsForm
 */
class SettingsFormTest extends BrowserTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['node', 'fact_drift'];

  /**
   * {@inheritdoc}
   */
  protected $defaultTheme = 'stark';

  /**
   * Tests access and the saved values of every element.
   */
  public function testSettingsSave(): void {
    NodeType::create(['type' => 'page', 'name' => 'Page'])->save();
    NodeType::create(['type' => 'article', 'name' => 'Article'])->save();

    $this->drupalLogin($this->drupalCreateUser(['access administration pages']));
    $this->drupalGet('/admin/config/content/fact-drift');
    $this->assertSession()->statusCodeEquals(403);

    $this->drupalLogin($this->drupalCreateUser(['administer fact drift']));
    $this->drupalGet('/admin/config/content/fact-drift');
    $this->assertSession()->statusCodeEquals(200);
    $this->assertSession()->checkboxChecked('content_types[page]');
    $this->assertSession()->checkboxChecked('extractors[phone]');
    $this->assertSession()->checkboxNotChecked('kill_switch');

    $this->submitForm([
      'content_types[page]' => FALSE,
      'content_types[article]' => TRUE,
      'field_types[text_with_summary]' => FALSE,
      'field_types[text_long]' => TRUE,
      'field_types[string_long]' => FALSE,
      'extractors[phone]' => TRUE,
      'extractors[hours]' => FALSE,
      'full_scan_interval' => '7200',
      'nodes_per_cron' => '50',
      'kill_switch' => TRUE,
    ], 'Save configuration');
    $this->assertSession()->pageTextContains('The configuration options have been saved.');

    $config = $this->config('fact_drift.settings');
    $this->assertSame(['article'], $config->get('content_types'));
    $this->assertSame(['text_long'], $config->get('field_types'));
    $this->assertSame(['phone'], $config->get('extractors'));
    $this->assertSame(7200, $config->get('full_scan_interval'));
    $this->assertSame(50, $config->get('nodes_per_cron'));
    $this->assertTrue($config->get('kill_switch'));

    $this->drupalGet('/admin/config/content/fact-drift');
    $this->assertSession()->checkboxChecked('content_types[article]');
    $this->assertSession()->checkboxNotChecked('content_types[page]');
    $this->assertSession()->checkboxChecked('kill_switch');
    $this->assertSession()->fieldValueEquals('full_scan_interval', '7200');
    $this->assertSession()->fieldValueEquals('nodes_per_cron', '50');
  }

  /**
   * Tests that a full scan interval below an hour is refused.
   */
  public function testIntervalBelowMinimumIsRefused(): void {
    $this->drupalLogin($this->drupalCreateUser(['administer fact drift']));
    $this->drupalGet('/admin/config/content/fact-drift');
    $this->submitForm(['full_scan_interval' => '60'], 'Save configuration');
    $this->assertSame(86400, $this->config('fact_drift.settings')->get('full_scan_interval'));
  }

}
