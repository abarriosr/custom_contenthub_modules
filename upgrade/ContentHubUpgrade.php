<?php

namespace Drupal\ContentHubUpgradeCommands;

/**
 * Class ContentHubUpgrade
 *
 * Run these commands using the --include option - e.g. `drush --include=/path/to/drush/file ach-prepare-upgrade`
 *
 * For an example of a Drupal module implementing commands, see
 * - http://cgit.drupalcode.org/devel/tree/devel_generate/src/Commands
 * - http://cgit.drupalcode.org/devel/tree/devel_generate/drush.services.yml
 *
 * @package Drupal\ContentHubUpgradeCommands
 */
class ContentHubUpgrade {

  /**
   * The environment drush alias.
   *
   * @var string
   */
  protected $alias;

  /**
   * The list of Site URLs in a Site Factory Farm.
   *
   * @var array
   */
  protected $sitesUri;

  /**
   * The docroot directory path for the site factory farm.
   *
   * @var string
   */
  protected $docroot;

  /**
   * Collects a list of Site Factory URIs and drush alias.
   */
  protected function getSiteFactorySites() {
    $sites = gardens_site_data_load_file();
    if (empty($sites)) {
      return drush_log('There are no sites found in this Site Factory Farm.');
    }
    $this->alias = '@' . $sites['cloud']['site'] . '.' . $sites['cloud']['env'];
    $this->sitesUri = array_keys($sites['sites']);
    $this->docroot = "/var/www/html/{$sites['cloud']['site']}.{$sites['cloud']['env']}/docroot";
  }

  /**
   * Prepares the sites for upgrade.
   *
   * @command ach-upgrade
   * @aliases ach-up
   * @usage drush ach-upgrade
   *   Executes the upgrade from 1.x to 2.x
   */
  public function contentHubUpgrade() {
    $this->getSiteFactorySites();
    $this->removeRestResourceAndSetSchema();
    $this->updateDbs();
    $this->upgradePublishers();
    $this->upgradeSubscribers();
  }

  /**
   * Removes REST resource and sets module schema to 8200..
   */
  protected function removeRestResourceAndSetSchema() {
    foreach ($this->sitesUri as $site_uri) {
      $options = [
        'uri' => 'http://' . $site_uri,
        'root' => $this->docroot,
      ];

      // Remove REST resource.
      $arguments = [
        'DELETE FROM config WHERE name = "rest.resource.contenthub_filter";',
      ];
      drush_print(sprintf('Removing REST Resource from site %s:', $site_uri));
      drush_invoke_process($this->alias, 'sqlq', $arguments, $options);
      $arguments = [
        'DELETE FROM router WHERE name LIKE "rest.contenthub_filter%";',
      ];
      drush_invoke_process($this->alias, 'sqlq', $arguments, $options);

      // Clear cache.
      drush_print(sprintf('Clearing cache for site %s:', $site_uri));
      drush_invoke_process($this->alias, 'cr', [], $options);

      // Set module schema.
      drush_print(sprintf('Setting up Module Schema for site %s:', $site_uri));
      $arguments = [
        'drupal_set_installed_schema_version("acquia_contenthub","8200");',
      ];
      drush_invoke_process($this->alias, 'ev', $arguments, $options);
    }
  }

  protected function updateDbs() {
    foreach ($this->sitesUri as $site_uri) {
      $options = [
        'uri' => 'http://' . $site_uri,
        'yes' => true,
      ];
      $arguments = [
      ];
      drush_print(sprintf('Running Database updates for site %s:', $site_uri));
      drush_invoke_process($this->alias, 'updb', $arguments, $options);
    }
  }

  protected function upgradePublishers() {
    foreach ($this->sitesUri as $site_uri) {
      $options = [
        'uri' => 'http://' . $site_uri,
        'root' => $this->docroot,
      ];
      $arguments = [
        '$e = \Drupal::database()->query("SELECT count(*) as export FROM acquia_contenthub_entities_tracking WHERE status_export IS NOT NULL")->fetchAssoc(); $publisher = $e[\'export\'] ?? 0; if ($publisher) {  \Drupal::service("module_installer")->install(["acquia_contenthub_publisher"]);}',
      ];

      drush_print(sprintf('Checking if site is publisher: %s:', $site_uri));
      $output = drush_invoke_process($this->alias, 'ev', $arguments, $options);

      // If publisher then run upgrade.
      drush_print(sprintf('Installing and upgrading publisher module in %s:', $site_uri));
      drush_invoke_process($this->alias, 'ach-publisher-upgrade', [], $options);
    }
  }

  protected function upgradeSubscribers() {
    foreach ($this->sitesUri as $site_uri) {
      $options = [
        'uri' => 'http://' . $site_uri,
        'root' => $this->docroot,
      ];

      // If subscriber then run upgrade.
      drush_print(sprintf('Upgrading subscriber module in %s:', $site_uri));
      drush_invoke_process($this->alias, 'ach-subscriber-upgrade', [], $options);
    }
  }
}
