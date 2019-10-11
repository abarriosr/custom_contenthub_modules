<?php

namespace Drupal\ContentHubUpgradeCommands;

/**
 * Class ContentHubPrepareUpgrade
 *
 * Run these commands using the --include option - e.g. `drush --include=/path/to/drush/file ach-prepare-upgrade`
 *
 * For an example of a Drupal module implementing commands, see
 * - http://cgit.drupalcode.org/devel/tree/devel_generate/src/Commands
 * - http://cgit.drupalcode.org/devel/tree/devel_generate/drush.services.yml
 */
class ContentHubPrepareUpgrade  {

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
    // This path works for Site Factory.
    $this->docroot = "/var/www/html/{$sites['cloud']['site']}.{$sites['cloud']['env']}/docroot";
  }

  /**
   * Prepares the sites for upgrade.
   *
   * @command ach-prepare-upgrade
   * @aliases ach-preup
   * @usage drush ach-prepare-upgrade
   *   Prepares the site in 1.x for upgrade to 2.x
   */
  public function contentHubPrepareUpgrade() {
    $this->getSiteFactorySites();
    $this->uninstallUnNeededAchModules1x();
    $this->installDepCalc();
    $this->printWebhooksInformation();
    $this->purgeSubscription();
  }

  /**
   * Uninstall unneeded Content Hub modules.
   *
   * Uninstalls the following modules, if they are found to be installed:
   *   - acquia_contenthub_audit
   *   - acquia_contenthub_status
   *   - acquia_contenthub_diagnostic
   */
  protected function uninstallUnNeededAchModules1x() {
    foreach ($this->sitesUri as $site_uri) {
      $arguments = [
      ];
      $options = [
        'uri' => 'http://' . $site_uri,
        'status' => 'enabled',
        'package' => 'Acquia Content Hub',
      ];
      $output = drush_invoke_process($this->alias, 'pml', $arguments, $options);
      $modules = [
        'acquia_contenthub_audit',
        'acquia_contenthub_status',
        'acquia_contenthub_diagnostic',
      ];
      $uninstall = array_intersect($modules, array_keys($output['object']));
      foreach ($uninstall as $module_name) {
        $arguments = [
          $module_name
        ];
        $options = [
          'uri' => 'http://' . $site_uri,
          'yes' => true,
        ];
        $output = drush_invoke_process($this->alias, 'pmu', $arguments, $options);
        if ($output['error_status'] === 0) {
          drush_print(sprintf('Uninstalled module %s', $module_name));
        }
      }
    }
  }

  /**
   * Installs depcalc module.
   */
  protected function installDepCalc() {
    foreach ($this->sitesUri as $site_uri) {
      $arguments = [
        'depcalc',
      ];
      $options = [
        'uri' => 'http://' . $site_uri,
        'yes' => true,
      ];
      $output = drush_invoke_process($this->alias, 'en', $arguments, $options);
      if ($output['error_status'] === 0) {
        drush_print(sprintf('Installed depcalc module on site %s', $site_uri));
      }
    }
  }

  /**
   * Prints Webhook Information in the Content Hub Subscription.
   */
  protected function printWebhooksInformation() {
    // Taking first site in the list.
    $site_uri = reset($this->sitesUri);
    $arguments = [
      '$c = \Drupal::getContainer()->get("acquia_contenthub.acquia_contenthub_subscription"); print_r($c->getSettings()->getWebhooks())',
    ];
    $options = [
      'uri' => 'http://' . $site_uri,
    ];
    drush_print('Webhook Information for this Subscription:');
    drush_invoke_process($this->alias, 'ev', $arguments, $options);
  }

  /**
   * Purges the Content Hub Subscription.
   */
  protected function purgeSubscription() {
    // Taking first site in the list.
    $site_uri = reset($this->sitesUri);
    $arguments = [
      '$c = \Drupal::service("acquia_contenthub.client_manager"); $response = $c->createRequest("purge");',
    ];
    $options = [
      'uri' => 'http://' . $site_uri,
    ];
    drush_print('Purging Content Hub Subscription:');
    drush_invoke_process($this->alias, 'ev', $arguments, $options);
  }
}
