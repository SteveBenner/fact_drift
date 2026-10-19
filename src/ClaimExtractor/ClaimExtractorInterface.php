<?php

namespace Drupal\fact_drift\ClaimExtractor;

use Drupal\Component\Plugin\PluginInspectionInterface;

/**
 * Defines what a claim extractor plugin must do.
 */
interface ClaimExtractorInterface extends PluginInspectionInterface {

  /**
   * Finds claims of the plugin's type in plain text.
   *
   * @param string $text
   *   The plain text, with markup stripped.
   *
   * @return \Drupal\fact_drift\Value\Claim[]
   *   The claims, each with its character offset and a snippet.
   */
  public function extract(string $text): array;

}
