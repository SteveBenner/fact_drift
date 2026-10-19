<?php

namespace Drupal\fact_drift\Entity;

use Drupal\Core\Config\Entity\ConfigEntityBase;

/**
 * Defines the fact drift source configuration entity.
 *
 * A source is a configured place the facts come from, read by a FactSource
 * plugin. Its status is the built-in enabled flag of configuration entities.
 *
 * @ConfigEntityType(
 *   id = "fact_drift_source",
 *   label = @Translation("Fact drift source"),
 *   label_collection = @Translation("Fact drift sources"),
 *   label_singular = @Translation("fact drift source"),
 *   label_plural = @Translation("fact drift sources"),
 *   config_prefix = "source",
 *   admin_permission = "administer fact drift",
 *   entity_keys = {
 *     "id" = "id",
 *     "label" = "label",
 *     "status" = "status",
 *   },
 *   config_export = {
 *     "id",
 *     "label",
 *     "plugin",
 *     "plugin_settings",
 *     "claim_types",
 *   },
 *   handlers = {
 *     "list_builder" = "Drupal\fact_drift\SourceListBuilder",
 *     "form" = {
 *       "add" = "Drupal\fact_drift\Form\SourceForm",
 *       "edit" = "Drupal\fact_drift\Form\SourceForm",
 *       "delete" = "Drupal\Core\Entity\EntityDeleteForm",
 *     },
 *     "route_provider" = {
 *       "html" = "Drupal\Core\Entity\Routing\AdminHtmlRouteProvider",
 *     },
 *   },
 *   links = {
 *     "collection" = "/admin/config/content/fact-drift/sources",
 *     "add-form" = "/admin/config/content/fact-drift/sources/add",
 *     "edit-form" = "/admin/config/content/fact-drift/sources/{fact_drift_source}",
 *     "delete-form" = "/admin/config/content/fact-drift/sources/{fact_drift_source}/delete",
 *   },
 * )
 */
class Source extends ConfigEntityBase {

  /**
   * The machine name of the source.
   *
   * @var string
   */
  protected $id;

  /**
   * The label of the source.
   *
   * @var string
   */
  protected $label;

  /**
   * The ID of the FactSource plugin that reads the source.
   *
   * @var string
   */
  protected $plugin = '';

  /**
   * The settings of the plugin.
   *
   * @var array<string, mixed>
   */
  protected $plugin_settings = [];

  /**
   * The claim types the source provides.
   *
   * @var string[]
   */
  protected $claim_types = [];

  /**
   * Gets the ID of the FactSource plugin.
   *
   * @return string
   *   The plugin ID.
   */
  public function getPluginId(): string {
    return (string) $this->plugin;
  }

  /**
   * Gets the settings of the plugin.
   *
   * @return array<string, mixed>
   *   The plugin settings.
   */
  public function getPluginSettings(): array {
    return $this->plugin_settings;
  }

  /**
   * Gets the claim types the source provides.
   *
   * @return string[]
   *   The claim types.
   */
  public function getClaimTypes(): array {
    return array_values($this->claim_types);
  }

}
