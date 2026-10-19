<?php

namespace Drupal\Tests\fact_drift\Kernel;

use Drupal\fact_drift\Entity\Source;
use Drupal\KernelTests\KernelTestBase;
use Drupal\Tests\SchemaCheckTestTrait;

/**
 * Tests that the module's configuration validates against its schema.
 *
 * @group fact_drift
 * @covers \Drupal\fact_drift\Entity\Source
 */
class ConfigSchemaTest extends KernelTestBase {

  use SchemaCheckTestTrait;

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
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installConfig(['fact_drift']);
  }

  /**
   * Tests that the default settings match the schema.
   */
  public function testDefaultSettingsValidate(): void {
    $config = $this->config('fact_drift.settings');
    $this->assertNotEmpty($config->get('content_types'));
    $this->assertTrue($this->checkConfigSchema(
      $this->container->get('config.typed'),
      'fact_drift.settings',
      $config->get(),
    ));
  }

  /**
   * Tests that a saved source matches the schema.
   */
  public function testSavedSourceValidates(): void {
    $source = Source::create([
      'id' => 'clinics',
      'label' => 'Clinics',
      'plugin' => 'csv',
      'plugin_settings' => ['path' => '/tmp/clinics.csv'],
      'claim_types' => ['phone', 'hours'],
      'status' => TRUE,
    ]);
    $source->save();
    $config = $this->config('fact_drift.source.clinics');
    $this->assertSame('csv', $config->get('plugin'));
    $this->assertTrue($this->checkConfigSchema(
      $this->container->get('config.typed'),
      'fact_drift.source.clinics',
      $config->get(),
    ));
  }

  /**
   * Tests that the schema rejects a value of the wrong type.
   */
  public function testWrongTypeFailsTheSchema(): void {
    $data = $this->config('fact_drift.settings')->get();
    $data['full_scan_interval'] = 'soon';
    $result = $this->checkConfigSchema($this->container->get('config.typed'), 'fact_drift.settings', $data);
    $this->assertNotTrue($result);
  }

}
