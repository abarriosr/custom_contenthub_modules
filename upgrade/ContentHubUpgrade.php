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

  use ContentHubUpgradeProgressTrait;

  const CONTENT_HUB_UPGRADE_PROGRESS_TRACKER_FILE = '/tmp/content-hub-upgrade-progress.tmp';

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
   * Provides Lift Support.
   *
   * @var bool
   */
  protected $liftSupport = FALSE;

  /**
   * ContentHubUpgrade constructor.
   *
   * @param bool $lift_support
   *
   * @throws \Exception
   */
  public function __construct($lift_support = FALSE) {
    $this->liftSupport = $lift_support;
    $this->getSiteFactorySites();
    $this->buildProgressTracker();
    $this->setProgressTrackerFile(self::CONTENT_HUB_UPGRADE_PROGRESS_TRACKER_FILE);
  }

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

  public function buildProgressTracker() {
    $stages = [
      'removeRestResourceAndSetSchema',
      'updateDbs',
      'upgradePublishers',
      'runExportQueues',
      'upgradeSubscribers',
    ];

    if ($this->liftSupport) {
      $acquia_lift_support = [
        'enableAcquiaLiftSupport',
      ];
      array_splice( $stages, 2, 0, $acquia_lift_support );
    }
    foreach ($stages as $key => $stage) {
      foreach ($this->sitesUri as $site_uri) {
        $this->progress[] = $stage . '|' . $site_uri;
      }
    }
    print_r($this->progress);

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
    $pid = $this->getStoredProgressId();

    $this->removeRestResourceAndSetSchema($pid);
    $this->updateDbs($pid);
    if ($this->liftSupport) {
      $this->enableAcquiaLiftSupportModule($pid);
    }
    $this->upgradePublishers($pid);
    $this->upgradeSubscribers($pid);

    $this->cleanUp();
  }

  /**
   * Removes REST resource and sets module schema to 8200..
   */
  protected function removeRestResourceAndSetSchema($pid) {
    foreach ($this->sitesUri as $site_uri) {
      $current_pid = $this->getProgressId(__METHOD__, $site_uri);
      if ($pid && $current_pid < $pid) {
        continue;
      }
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

      // Tracking progress.
      $this->trackProgress(__METHOD__, $site_uri);
    }
  }

  protected function updateDbs($pid) {
    foreach ($this->sitesUri as $site_uri) {
      $current_pid = $this->getProgressId(__METHOD__, $site_uri);
      if ($pid && $current_pid < $pid) {
        continue;
      }
      $options = [
        'uri' => 'http://' . $site_uri,
        'yes' => true,
      ];
      $arguments = [
      ];
      drush_print(sprintf('Running Database updates for site %s:', $site_uri));
      drush_invoke_process($this->alias, 'updb', $arguments, $options);

      // Tracking progress.
      $this->trackProgress(__METHOD__, $site_uri);
    }
  }

  protected function enableAcquiaLiftSupportModule($pid) {
    foreach ($this->sitesUri as $site_uri) {
      $current_pid = $this->getProgressId(__METHOD__, $site_uri);
      if ($pid && $current_pid < $pid) {
        continue;
      }
      $arguments = [
        'acquia_lift_support',
      ];
      $options = [
        'uri' => 'http://' . $site_uri,
        'yes' => true,
      ];
      $output = drush_invoke_process($this->alias, 'en', $arguments, $options);
      if ($output['error_status'] === 0) {
        drush_print(sprintf('Finished installation of acquia_lift_support module on site %s', $site_uri));
      }
      // Tracking progress.
      $this->trackProgress(__METHOD__, $site_uri);
    }
  }

  protected function upgradePublishers($pid) {
    foreach ($this->sitesUri as $site_uri) {
      $current_pid = $this->getProgressId(__METHOD__, $site_uri);
      if ($pid && $current_pid < $pid) {
        continue;
      }
      $options = [
        'uri' => 'http://' . $site_uri,
        'root' => $this->docroot,
      ];
      $arguments = [
        '$e = \Drupal::database()->query("SELECT count(*) as export FROM acquia_contenthub_entities_tracking WHERE status_export IS NOT NULL")->fetchAssoc(); $publisher = $e[\'export\'] ?? 0; if ($publisher) {  \Drupal::service("module_installer")->install(["acquia_contenthub_publisher"]);}',
      ];

      drush_print(sprintf('If site is a publisher then enable publisher module for: %s:', $site_uri));
      drush_invoke_process($this->alias, 'ev', $arguments, $options);

      // checking is acquia_contenthub_publisher module has been enabled.
      $arguments = [
      ];
      $options = [
        'uri' => 'http://' . $site_uri,
        'status' => 'enabled',
        'package' => 'Acquia Content Hub',
      ];
      $output = drush_invoke_process($this->alias, 'pml', $arguments, $options);
      $modules = [
        'acquia_contenthub_publisher',
      ];
      $publisher_enabled = array_intersect($modules, array_keys($output['object']));

      if (!empty($publisher_enabled)) {
        // If publisher then run upgrade.
        $options = [
          'uri' => 'http://' . $site_uri,
          'root' => $this->docroot,
        ];
        drush_print(sprintf('Running upgrade for publisher module in site %s:', $site_uri));
        drush_invoke_process($this->alias, 'ach-publisher-upgrade', [], $options);
      }
      else {
        drush_print(sprintf('Site "%s" is not a publisher.', $site_uri));
      }
      // Tracking progress.
      $this->trackProgress(__METHOD__, $site_uri);
    }
  }

  protected function upgradeSubscribers($pid) {
    foreach ($this->sitesUri as $site_uri) {
      $current_pid = $this->getProgressId(__METHOD__, $site_uri);
      if ($pid && $current_pid < $pid) {
        continue;
      }
      $options = [
        'uri' => 'http://' . $site_uri,
        'root' => $this->docroot,
      ];

      // If subscriber then run upgrade.
      drush_print(sprintf('Upgrading subscriber module in %s:', $site_uri));
      drush_invoke_process($this->alias, 'ach-subscriber-upgrade', [], $options);

      // Tracking progress.
      $this->trackProgress(__METHOD__, $site_uri);
    }
  }
}
