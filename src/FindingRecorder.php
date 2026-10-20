<?php

namespace Drupal\fact_drift;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\fact_drift\Entity\Finding;
use Drupal\node\NodeInterface;

/**
 * Opens, closes and de-duplicates findings.
 */
class FindingRecorder {

  /**
   * Constructs a FindingRecorder.
   *
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entityTypeManager
   *   The entity type manager.
   * @param \Drupal\Component\Datetime\TimeInterface $time
   *   The time service.
   */
  public function __construct(protected EntityTypeManagerInterface $entityTypeManager, protected TimeInterface $time) {
  }

  /**
   * Brings the stored findings of a node in line with a scan.
   *
   * Opens a finding for each mismatch unless the same node, field, source,
   * fact and found value already has an open or dismissed finding. Then closes
   * every open finding of the node whose source was scanned and which matches
   * no mismatch of this scan, except those of a field and extractor in $keep.
   *
   * @param \Drupal\node\NodeInterface $node
   *   The scanned node.
   * @param \Drupal\fact_drift\Value\Mismatch[] $mismatches
   *   The mismatches the scan found.
   * @param string[] $sourceIdsScanned
   *   The IDs of the sources whose facts were loaded for the scan.
   * @param array<int, array{0: string, 1: string}> $keep
   *   (optional) Pairs of a field name and an extractor ID whose open findings
   *   stay open because that extractor's work on the field was deferred or
   *   held.
   *
   * @return array{opened: int, closed: int}
   *   The counts.
   */
  public function sync(NodeInterface $node, array $mismatches, array $sourceIdsScanned, array $keep = []): array {
    $storage = $this->storage();
    $existing = $this->loadFindings((int) $node->id(), [Finding::STATE_OPEN, Finding::STATE_DISMISSED]);
    $known = [];
    foreach ($existing as $finding) {
      $known[$this->key($finding->getFieldName(), $finding->getSourceId(), $finding->getClaimType(), $finding->getFactKey(), $finding->getFound())] = TRUE;
    }

    $seen = [];
    $opened = 0;
    foreach ($mismatches as $mismatch) {
      $key = $this->key($mismatch->fieldName, $mismatch->fact->sourceId, $mismatch->fact->claimType, $mismatch->fact->key, $mismatch->claim->value);
      $seen[$key] = TRUE;
      if (isset($known[$key])) {
        continue;
      }
      $known[$key] = TRUE;
      $storage->create([
        'node' => $node->id(),
        'field_name' => $mismatch->fieldName,
        'claim_type' => $mismatch->fact->claimType,
        'extractor' => $mismatch->claim->extractor,
        'fact_key' => $mismatch->fact->key,
        'source' => $mismatch->fact->sourceId,
        'expected' => $mismatch->fact->value,
        'found' => $mismatch->claim->value,
        'snippet' => $mismatch->claim->snippet,
        'state' => Finding::STATE_OPEN,
      ])->save();
      $opened++;
    }

    $kept = [];
    foreach ($keep as [$fieldName, $extractorId]) {
      $kept[$fieldName . "\0" . $extractorId] = TRUE;
    }

    $closed = 0;
    foreach ($existing as $finding) {
      if ($finding->getState() !== Finding::STATE_OPEN || !in_array($finding->getSourceId(), $sourceIdsScanned, TRUE)) {
        continue;
      }
      if (isset($kept[$finding->getFieldName() . "\0" . $finding->getExtractor()])) {
        continue;
      }
      $key = $this->key($finding->getFieldName(), $finding->getSourceId(), $finding->getClaimType(), $finding->getFactKey(), $finding->getFound());
      if (!isset($seen[$key])) {
        $this->close($finding);
        $closed++;
      }
    }
    return ['opened' => $opened, 'closed' => $closed];
  }

  /**
   * Closes every open finding of a node.
   *
   * @param int $nid
   *   The node ID.
   *
   * @return int
   *   The number of findings closed.
   */
  public function closeForNode(int $nid): int {
    $findings = $this->loadFindings($nid, [Finding::STATE_OPEN]);
    foreach ($findings as $finding) {
      $this->close($finding);
    }
    return count($findings);
  }

  /**
   * Dismisses a finding.
   *
   * @param \Drupal\fact_drift\Entity\Finding $finding
   *   The finding.
   * @param string $reason
   *   Why the finding is dismissed.
   */
  public function dismiss(Finding $finding, string $reason): void {
    $finding->setState(Finding::STATE_DISMISSED);
    $finding->set('dismiss_reason', $reason);
    $finding->save();
  }

  /**
   * Closes a finding.
   *
   * @param \Drupal\fact_drift\Entity\Finding $finding
   *   The finding.
   */
  protected function close(Finding $finding): void {
    $finding->setState(Finding::STATE_CLOSED);
    $finding->set('closed', $this->time->getRequestTime());
    $finding->save();
  }

  /**
   * Loads the findings of a node in the given states.
   *
   * @param int $nid
   *   The node ID.
   * @param string[] $states
   *   The states to load.
   *
   * @return array<int, \Drupal\fact_drift\Entity\Finding>
   *   The findings.
   */
  protected function loadFindings(int $nid, array $states): array {
    $storage = $this->storage();
    $ids = $storage->getQuery()
      ->accessCheck(FALSE)
      ->condition('node', $nid)
      ->condition('state', $states, 'IN')
      ->execute();
    if (!$ids) {
      return [];
    }
    /** @var \Drupal\fact_drift\Entity\Finding[] $findings */
    $findings = $storage->loadMultiple($ids);
    return array_values($findings);
  }

  /**
   * Builds the identity of a mismatch.
   *
   * @param string $field
   *   The field name.
   * @param string $source
   *   The source ID.
   * @param string $type
   *   The claim type.
   * @param string $factKey
   *   The fact key.
   * @param string $found
   *   The found value.
   *
   * @return string
   *   The identity.
   */
  protected function key(string $field, string $source, string $type, string $factKey, string $found): string {
    return implode("\0", [$field, $source, $type, $factKey, $found]);
  }

  /**
   * Gets the finding storage.
   *
   * @return \Drupal\Core\Entity\EntityStorageInterface
   *   The storage.
   */
  protected function storage(): EntityStorageInterface {
    return $this->entityTypeManager->getStorage('fact_drift_finding');
  }

}
