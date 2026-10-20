<?php

namespace Drupal\Tests\fact_drift\Functional;

use Drupal\Tests\BrowserTestBase;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests that the settings form lists only plain extractors.
 *
 * @group fact_drift
 * @covers \Drupal\fact_drift\Form\SettingsForm
 */
#[RunTestsInSeparateProcesses]
class SettingsFormExtractorsTest extends BrowserTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['node', 'fact_drift', 'fact_drift_test_contextual'];

  /**
   * {@inheritdoc}
   */
  protected $defaultTheme = 'stark';

  /**
   * Tests that a contextual extractor is not offered as a checkbox.
   */
  public function testOnlyPlainExtractorsAreListed(): void {
    $this->drupalLogin($this->drupalCreateUser(['administer fact drift']));
    $this->drupalGet('/admin/config/content/fact-drift');
    $this->assertSession()->statusCodeEquals(200);
    $this->assertSession()->fieldExists('extractors[phone]');
    $this->assertSession()->fieldExists('extractors[hours]');
    $this->assertSession()->fieldNotExists('extractors[fact_drift_test_contextual]');
    $this->assertSession()->pageTextNotContains('Contextual test extractor');
    $this->assertSession()->pageTextContains('Phone patterns');
    $this->assertSession()->pageTextContains('Hours patterns');

    $this->submitForm(['extractors[phone]' => FALSE], 'Save configuration');
    $this->assertSame(['hours'], $this->config('fact_drift.settings')->get('extractors'));
  }

}
