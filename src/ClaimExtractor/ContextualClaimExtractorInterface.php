<?php

namespace Drupal\fact_drift\ClaimExtractor;

use Drupal\fact_drift\Value\ExtractionContext;

/**
 * Defines an extractor that also needs the node, the field and earlier claims.
 *
 * A contextual extractor runs after the plain extractors, in weight order. It
 * may defer or hold its work on a field through the context it is given.
 */
interface ContextualClaimExtractorInterface extends ClaimExtractorInterface {

  /**
   * Tells whether the extractor is configured to run at all.
   *
   * An inactive extractor is skipped, and its findings close like those of any
   * extractor that is not configured to run.
   *
   * @return bool
   *   TRUE when the extractor should run.
   */
  public function isActive(): bool;

  /**
   * Finds claims in the plain text of one field of one node.
   *
   * @param string $text
   *   The plain text, with markup stripped.
   * @param \Drupal\fact_drift\Value\ExtractionContext $context
   *   The node, the field name and the claims earlier extractors found in the
   *   field; the extractor records deferred or held work on it.
   *
   * @return \Drupal\fact_drift\Value\Claim[]
   *   The claims, each with its character offset and a snippet.
   */
  public function extractInContext(string $text, ExtractionContext $context): array;

  /**
   * Reports the counters of the current PHP process.
   *
   * @return array<string, int>
   *   Counter values keyed by name, in the order they are shown.
   */
  public function runSummary(): array;

}
