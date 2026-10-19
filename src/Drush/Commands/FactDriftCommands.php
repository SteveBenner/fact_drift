<?php

namespace Drupal\fact_drift\Drush\Commands;

use Consolidation\OutputFormatters\StructuredData\RowsOfFields;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\fact_drift\Entity\Finding;
use Drupal\fact_drift\Scanner;
use Drupal\node\NodeInterface;
use Drush\Attributes as CLI;
use Drush\Commands\DrushCommands;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Drush commands for Fact Drift Sentinel.
 */
final class FactDriftCommands extends DrushCommands {

  /**
   * Constructs the commands.
   *
   * @param \Drupal\fact_drift\Scanner $scanner
   *   The scanner.
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entityTypeManager
   *   The entity type manager.
   */
  public function __construct(
    protected Scanner $scanner,
    protected EntityTypeManagerInterface $entityTypeManager,
  ) {
    parent::__construct();
  }

  /**
   * Creates the commands from the container.
   *
   * @param \Symfony\Component\DependencyInjection\ContainerInterface $container
   *   The service container.
   *
   * @return static
   *   The commands.
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('fact_drift.scanner'),
      $container->get('entity_type.manager'),
    );
  }

  /**
   * Scans published nodes for facts that have drifted from their source.
   *
   * @param array<string, mixed> $options
   *   The command options.
   */
  #[CLI\Command(name: 'fact-drift:scan')]
  #[CLI\Option(name: 'source', description: 'Only compare against the source with this ID.')]
  #[CLI\Option(name: 'node', description: 'Only scan the node with this ID.')]
  #[CLI\Option(name: 'limit', description: 'Scan at most this many nodes.')]
  #[CLI\Usage(name: 'drush fact-drift:scan', description: 'Scan every published node of the configured content types.')]
  public function scan(array $options = ['source' => NULL, 'node' => NULL, 'limit' => NULL]): void {
    $sourceIds = ($options['source'] ?? NULL) !== NULL ? [(string) $options['source']] : NULL;
    if (($options['node'] ?? NULL) !== NULL) {
      $node = $this->entityTypeManager->getStorage('node')->load((int) $options['node']);
      if (!$node instanceof NodeInterface) {
        throw new \InvalidArgumentException(sprintf('Node %s does not exist.', $options['node']));
      }
      $counts = $this->scanner->scanNode($node, $sourceIds);
    }
    else {
      $limit = ($options['limit'] ?? NULL) !== NULL ? max(1, (int) $options['limit']) : NULL;
      $counts = $this->scanner->scanAll($sourceIds, $limit);
    }
    foreach ($counts['failed'] as $id) {
      $this->logger()->warning(sprintf('Source %s could not be read; its open findings were left as they are.', $id));
    }
    $this->output()->writeln(sprintf('Claims: %d, opened: %d, closed: %d', $counts['claims'], $counts['opened'], $counts['closed']));
  }

  /**
   * Lists findings.
   *
   * @param array<string, mixed> $options
   *   The command options.
   *
   * @return \Consolidation\OutputFormatters\StructuredData\RowsOfFields
   *   The findings.
   */
  #[CLI\Command(name: 'fact-drift:report')]
  #[CLI\Option(name: 'state', description: 'The state to list: open, closed or dismissed.')]
  #[CLI\FieldLabels(labels: [
    'id' => 'ID',
    'page' => 'Page',
    'field' => 'Field',
    'type' => 'Type',
    'expected' => 'Expected',
    'found' => 'Found',
    'state' => 'State',
    'first_seen' => 'First seen',
  ])]
  #[CLI\DefaultTableFields(fields: ['id', 'page', 'field', 'type', 'expected', 'found', 'state', 'first_seen'])]
  #[CLI\Usage(name: 'drush fact-drift:report --format=json', description: 'List open findings as JSON.')]
  public function report(array $options = ['state' => 'open', 'format' => 'table']): RowsOfFields {
    $state = (string) ($options['state'] ?? Finding::STATE_OPEN);
    if (!in_array($state, [Finding::STATE_OPEN, Finding::STATE_CLOSED, Finding::STATE_DISMISSED], TRUE)) {
      throw new \InvalidArgumentException(sprintf('Unknown state %s; use open, closed or dismissed.', $state));
    }
    $storage = $this->entityTypeManager->getStorage('fact_drift_finding');
    $ids = $storage->getQuery()
      ->accessCheck(FALSE)
      ->condition('state', $state)
      ->sort('id')
      ->execute();
    $rows = [];
    foreach ($storage->loadMultiple($ids) as $finding) {
      assert($finding instanceof Finding);
      $node = $finding->getNode();
      $rows[] = [
        'id' => $finding->id(),
        'page' => $node ? $node->label() : '(deleted)',
        'field' => $finding->getFieldName(),
        'type' => $finding->getClaimType(),
        'expected' => $finding->getExpected(),
        'found' => $finding->getFound(),
        'state' => $finding->getState(),
        'first_seen' => $this->isoTime($finding->getCreatedTime()),
      ];
    }
    return new RowsOfFields($rows);
  }

  /**
   * Lists the configured sources.
   *
   * @return \Consolidation\OutputFormatters\StructuredData\RowsOfFields
   *   The sources.
   */
  #[CLI\Command(name: 'fact-drift:sources')]
  #[CLI\FieldLabels(labels: [
    'id' => 'ID',
    'label' => 'Label',
    'plugin' => 'Plugin',
    'facts' => 'Facts',
    'last_load' => 'Last load',
  ])]
  #[CLI\DefaultTableFields(fields: ['id', 'label', 'plugin', 'facts', 'last_load'])]
  #[CLI\Usage(name: 'drush fact-drift:sources', description: 'List the sources with their fact counts.')]
  public function sources(): RowsOfFields {
    $rows = [];
    foreach ($this->scanner->sourceStatus() as $row) {
      $row['last_load'] = $row['last_load'] === NULL ? '' : $this->isoTime($row['last_load']);
      $rows[] = $row;
    }
    return new RowsOfFields($rows);
  }

  /**
   * Formats a timestamp as an ISO 8601 date in UTC.
   *
   * @param int $timestamp
   *   The timestamp.
   *
   * @return string
   *   The formatted time.
   */
  protected function isoTime(int $timestamp): string {
    return (new \DateTimeImmutable('@' . $timestamp))->format('Y-m-d\TH:i:s\Z');
  }

}
