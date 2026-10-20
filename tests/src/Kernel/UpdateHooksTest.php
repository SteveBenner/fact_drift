<?php

namespace Drupal\Tests\fact_drift\Kernel;

use Drupal\fact_drift\Entity\Finding;
use Drupal\KernelTests\KernelTestBase;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests the 0.2.0 update hooks.
 *
 * @group fact_drift
 */
#[RunTestsInSeparateProcesses]
class UpdateHooksTest extends KernelTestBase {

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
    $this->installEntitySchema('user');
    $this->installEntitySchema('node');
    $this->installEntitySchema('fact_drift_finding');
    $this->installConfig(['node', 'fact_drift']);
    require_once __DIR__ . '/../../../fact_drift.install';
  }

  /**
   * Runs an update hook function from the module's install file.
   *
   * @param string $function
   *   The name of the update function.
   */
  protected function runUpdate(string $function): void {
    if (!is_callable($function)) {
      $this->fail('The update function ' . $function . ' does not exist.');
    }
    $function();
  }

  /**
   * Tests that update 10001 installs the field and fills it from the type.
   */
  public function testUpdate10001InstallsExtractorFieldAndFillsIt(): void {
    $storage = $this->container->get('entity_type.manager')->getStorage('fact_drift_finding');
    foreach (['phone' => '9095550123', 'hours' => 'Mon-Fri 09:00-17:00'] as $type => $found) {
      $storage->create([
        'node' => 1,
        'field_name' => 'body',
        'claim_type' => $type,
        'extractor' => 'to-be-lost',
        'fact_key' => 'riverside',
        'source' => 'clinics',
        'found' => $found,
        'state' => Finding::STATE_OPEN,
      ])->save();
    }
    $manager = $this->container->get('entity.definition_update_manager');
    $definition = $manager->getFieldStorageDefinition('extractor', 'fact_drift_finding');
    $this->assertNotNull($definition);
    $manager->uninstallFieldStorageDefinition($definition);
    $this->assertNull($manager->getFieldStorageDefinition('extractor', 'fact_drift_finding'));
    $this->assertFalse($this->container->get('database')->schema()->fieldExists('fact_drift_finding', 'extractor'));

    $this->runUpdate('fact_drift_update_10001');

    $this->assertNotNull($manager->getFieldStorageDefinition('extractor', 'fact_drift_finding'));
    $storage->resetCache();
    $extractors = [];
    foreach ($storage->loadMultiple() as $finding) {
      $this->assertInstanceOf(Finding::class, $finding);
      $extractors[$finding->getClaimType()] = $finding->getExtractor();
    }
    ksort($extractors);
    $this->assertSame(['hours' => 'hours', 'phone' => 'phone'], $extractors);
  }

  /**
   * Tests that update 10002 removes the llm key and leaves the rest.
   */
  public function testUpdate10002RemovesTheLlmKey(): void {
    $data = $this->config('fact_drift.settings')->getRawData();
    $data['llm'] = ['enabled' => FALSE, 'provider' => '', 'model' => ''];
    $this->container->get('config.storage')->write('fact_drift.settings', $data);
    $this->container->get('config.factory')->reset('fact_drift.settings');
    $this->assertArrayHasKey('llm', $this->config('fact_drift.settings')->getRawData());

    $this->runUpdate('fact_drift_update_10002');

    $this->container->get('config.factory')->reset('fact_drift.settings');
    $after = $this->config('fact_drift.settings')->getRawData();
    $this->assertArrayNotHasKey('llm', $after);
    unset($data['llm']);
    $this->assertSame($data, $after);
  }

}
