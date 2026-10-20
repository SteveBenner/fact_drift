<?php

namespace Drupal\Tests\fact_drift_ai\Functional;

use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\node\Entity\NodeType;
use Drupal\Tests\BrowserTestBase;

/**
 * Tests the AI extractor tab: access, saving, validation and the status.
 *
 * @group fact_drift_ai
 * @covers \Drupal\fact_drift_ai\Form\AiSettingsForm
 */
class AiSettingsFormTest extends BrowserTestBase {

  /**
   * The path of the AI tab.
   */
  protected const PATH = '/admin/config/content/fact-drift/ai';

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['block', 'node', 'field', 'text', 'fact_drift', 'fact_drift_ai', 'fact_drift_ai_test'];

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
    $this->drupalPlaceBlock('local_tasks_block');
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
  }

  /**
   * Logs in as someone who may administer the extractor.
   */
  protected function loginAdmin(): void {
    $this->drupalLogin($this->drupalCreateUser(['administer fact drift']));
  }

  /**
   * Tests that the tab needs the permission.
   */
  public function testTabNeedsThePermission(): void {
    $this->drupalGet(self::PATH);
    $this->assertSession()->statusCodeEquals(403);

    $this->drupalLogin($this->drupalCreateUser(['view fact drift findings']));
    $this->drupalGet(self::PATH);
    $this->assertSession()->statusCodeEquals(403);

    $this->drupalLogin($this->drupalCreateUser(['administer fact drift']));
    $this->drupalGet(self::PATH);
    $this->assertSession()->statusCodeEquals(200);
    $this->assertSession()->pageTextContains('Enable the AI extractor');
  }

  /**
   * Tests the two tabs on both settings pages.
   */
  public function testTabsAppearOnBothPages(): void {
    $this->loginAdmin();
    foreach ([self::PATH, '/admin/config/content/fact-drift'] as $path) {
      $this->drupalGet($path);
      $this->assertSession()->elementExists('xpath', '//h2[normalize-space(.) = "Primary tabs"]/following-sibling::ul//a[normalize-space(.) = "Settings"]');
      $this->assertSession()->elementExists('xpath', '//h2[normalize-space(.) = "Primary tabs"]/following-sibling::ul//a[normalize-space(.) = "AI extractor"]');
    }
  }

  /**
   * Tests that the defaults are shown and the extractor starts off.
   */
  public function testDefaultsAreShown(): void {
    $this->loginAdmin();
    $this->drupalGet(self::PATH);
    $this->assertSession()->checkboxNotChecked('enabled');
    $this->assertSession()->checkboxChecked('prefilter');
    $this->assertSession()->fieldValueEquals('mode', 'fallback');
    $this->assertSession()->fieldValueEquals('max_chars', '4000');
    $this->assertSession()->fieldValueEquals('max_calls_per_run', '20');
    $this->assertSession()->fieldValueEquals('min_interval_ms', '1000');
    $this->assertSession()->fieldValueEquals('timeout_seconds', '10');
    $this->assertSession()->fieldValueEquals('max_retries', '2');
    $this->assertSession()->fieldValueEquals('reasoning', 'none');
    $this->assertSession()->fieldExists('allowlist[page.body]');
    $this->assertSession()->pageTextContains('Page: Body');
    $this->assertSession()->pageTextContains('Nothing is sent until you allow a field.');
  }

  /**
   * Tests that the form saves every setting, the unchecked prefilter included.
   */
  public function testFormSavesEverySetting(): void {
    $this->loginAdmin();
    $this->drupalGet(self::PATH);
    $this->assertSession()->optionExists('model', 'fact_drift_ai_test__scripted');
    $this->submitForm([
      'enabled' => TRUE,
      'model' => 'fact_drift_ai_test__scripted',
      'mode' => 'alongside',
      'prefilter' => FALSE,
      'max_chars' => '1200',
      'allowlist[page.body]' => TRUE,
      'max_calls_per_run' => '7',
      'min_interval_ms' => '250',
      'timeout_seconds' => '5',
      'max_retries' => '1',
      'reasoning' => 'provider_default',
    ], 'Save configuration');
    $this->assertSession()->pageTextContains('The configuration options have been saved.');

    $config = $this->config('fact_drift_ai.settings');
    $this->assertTrue($config->get('enabled'));
    $this->assertSame('fact_drift_ai_test', $config->get('provider'));
    $this->assertSame('scripted', $config->get('model'));
    $this->assertSame('alongside', $config->get('mode'));
    $this->assertFalse($config->get('prefilter'));
    $this->assertSame(1200, $config->get('max_chars'));
    $this->assertSame([['bundle' => 'page', 'field' => 'body']], $config->get('allowlist'));
    $this->assertSame(7, $config->get('max_calls_per_run'));
    $this->assertSame(250, $config->get('min_interval_ms'));
    $this->assertSame(5, $config->get('timeout_seconds'));
    $this->assertSame(1, $config->get('max_retries'));
    $this->assertSame('provider_default', $config->get('reasoning'));

    $this->drupalGet(self::PATH);
    $this->assertSession()->checkboxChecked('enabled');
    $this->assertSession()->checkboxNotChecked('prefilter');
    $this->assertSession()->checkboxChecked('allowlist[page.body]');
    $this->assertSession()->fieldValueEquals('model', 'fact_drift_ai_test__scripted');
    $this->assertSession()->fieldValueEquals('mode', 'alongside');
  }

  /**
   * Tests that unchecking an allowed field removes it from the allowlist.
   */
  public function testUncheckingFieldsEmptiesTheAllowlist(): void {
    $this->config('fact_drift_ai.settings')->set('allowlist', [['bundle' => 'page', 'field' => 'body']])->save();
    $this->loginAdmin();
    $this->drupalGet(self::PATH);
    $this->assertSession()->checkboxChecked('allowlist[page.body]');
    $this->submitForm(['allowlist[page.body]' => FALSE], 'Save configuration');
    $this->assertSame([], $this->config('fact_drift_ai.settings')->get('allowlist'));
  }

  /**
   * Tests that enabling without a model is an error on the model field.
   */
  public function testEnablingWithoutModelIsFieldError(): void {
    $this->loginAdmin();
    $this->drupalGet(self::PATH);
    $this->submitForm(['enabled' => TRUE, 'model' => ''], 'Save configuration');
    $this->assertSession()->pageTextContains('Choose a model to enable the AI extractor.');
    $this->assertSession()->elementExists('css', 'select[name="model"].error');
    $this->assertFalse($this->config('fact_drift_ai.settings')->get('enabled'));
  }

  /**
   * Tests that saving without enabling needs no model.
   */
  public function testSavingWithoutEnablingNeedsNoModel(): void {
    $this->loginAdmin();
    $this->drupalGet(self::PATH);
    $this->submitForm(['mode' => 'alongside'], 'Save configuration');
    $this->assertSession()->pageTextContains('The configuration options have been saved.');
    $this->assertSame('alongside', $this->config('fact_drift_ai.settings')->get('mode'));
    $this->assertSame('', $this->config('fact_drift_ai.settings')->get('provider'));
  }

  /**
   * Tests that saving clears the cache of model results.
   */
  public function testSavingClearsTheModelCache(): void {
    $cache = $this->container->get('cache.fact_drift_llm');
    $cache->set('llm:test', ['kept' => FALSE]);
    $this->assertNotFalse($cache->get('llm:test'));
    $this->loginAdmin();
    $this->drupalGet(self::PATH);
    $this->submitForm(['mode' => 'alongside'], 'Save configuration');
    $this->assertFalse($this->container->get('cache.fact_drift_llm')->get('llm:test'));
  }

  /**
   * Tests the status rows when all is quiet.
   */
  public function testStatusRowsWhenQuiet(): void {
    $this->config('fact_drift_ai.settings')->set('provider', 'fact_drift_ai_test')->set('model', 'scripted')->save();
    $this->loginAdmin();
    $this->drupalGet(self::PATH);
    $status = $this->getSession()->getPage()->find('css', '#edit-status');
    $this->assertNotNull($status);
    $text = $status->getText();
    $this->assertStringContainsString('Kill switch', $text);
    $this->assertStringContainsString('Provider usable', $text);
    $this->assertStringContainsString('Yes', $text);
    $this->assertStringContainsString('None', $text);
    $this->assertStringContainsString('Off. Change it on the Settings tab.', $text);
    $this->assertSession()->linkExists('Settings tab');
  }

  /**
   * Tests the allowlist options, the limits grid and the position of reasoning.
   */
  public function testLayoutAndFieldChoices(): void {
    $this->loginAdmin();
    $this->drupalGet(self::PATH);
    $this->assertSession()->fieldExists('allowlist[page.body]');
    $this->assertSession()->fieldNotExists('allowlist[page.revision_log]');
    $this->assertSession()->fieldNotExists('allowlist[page.title]');

    $this->assertSession()->elementExists('css', '.fact-drift-ai-limits .fact-drift-ai-limits__grid input[name="max_calls_per_run"]');
    $this->assertSession()->elementExists('css', '.fact-drift-ai-limits .fact-drift-ai-limits__grid input[name="max_retries"]');
    $this->assertSession()->elementNotExists('css', '.form--inline');
    $this->assertSession()->responseContains('fact-drift-ai-settings.css');

    $this->assertSession()->elementExists('xpath', '//input[@name="mode"]/following::input[@name="reasoning"]');
    $this->assertSession()->elementExists('xpath', '//input[@name="reasoning"]/following::input[@name="prefilter"]');
    $this->assertSession()->elementNotExists('xpath', '//details[summary[contains(., "Limits")]]//input[@name="reasoning"]');
  }

  /**
   * Tests that the model select carries no client-side required state.
   */
  public function testModelSelectHasNoClientSideRequired(): void {
    $this->loginAdmin();
    $this->drupalGet(self::PATH);
    $select = $this->assertSession()->elementExists('css', 'select[name="model"]');
    $this->assertFalse($select->hasAttribute('required'));
    $this->assertStringNotContainsString('required', (string) $select->getAttribute('data-drupal-states'));
    $this->assertStringContainsString('visible', (string) $select->getAttribute('data-drupal-states'));
  }

  /**
   * Tests that an active kill switch is shown as stopping every call.
   */
  public function testKillSwitchIsShown(): void {
    $this->config('fact_drift.settings')->set('kill_switch', TRUE)->save();
    $this->loginAdmin();
    $this->drupalGet(self::PATH);
    $this->assertSession()->pageTextContains('On, stopping every call');
    $this->assertSession()->linkNotExists('Settings tab');
  }

  /**
   * Tests that a kill switch overridden in settings.php is shown as active.
   */
  public function testOverriddenKillSwitchIsShownAsActive(): void {
    $this->writeSettings([
      'config' => [
        'fact_drift.settings' => [
          'kill_switch' => (object) ['value' => TRUE, 'required' => TRUE],
        ],
      ],
    ]);
    $this->loginAdmin();
    $this->drupalGet(self::PATH);
    $this->assertSession()->pageTextContains('On, stopping every call');
  }

  /**
   * Tests that an open side path is named by its setting.
   */
  public function testSidePathIsNamed(): void {
    $this->writeSettings([
      'config' => [
        'ai_observability.settings' => [
          'log_input' => (object) ['value' => TRUE, 'required' => TRUE],
        ],
      ],
    ]);
    $this->loginAdmin();
    $this->drupalGet(self::PATH);
    $this->assertSession()->pageTextContains('ai_observability.settings:log_input');
  }

  /**
   * Tests that no usable provider shows a warning with a link and no select.
   */
  public function testNoUsableProviderShowsWarning(): void {
    $this->container->get('state')->set('fact_drift_ai_test.usable', FALSE);
    $this->loginAdmin();
    $this->drupalGet(self::PATH);
    $this->assertSession()->statusCodeEquals(200);
    $this->assertSession()->pageTextContains('No usable chat provider is configured.');
    $this->assertSession()->elementExists('xpath', '//a[contains(@href, "/admin/config/ai/providers")][normalize-space(.) = "AI providers page"]');
    $this->assertSession()->fieldNotExists('model');
    $this->assertSession()->pageTextContains('Provider usable');
    $this->assertSession()->elementTextContains('css', '#edit-status', 'No');
  }

}
