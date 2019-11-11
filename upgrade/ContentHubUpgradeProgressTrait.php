<?php

namespace Drupal\ContentHubUpgradeCommands;

/**
 * Trait ContentHubUpgradeProgressTrait
 *
 * @package Drupal\ContentHubUpgradeCommands
 */
trait ContentHubUpgradeProgressTrait {

  /**
   * An array of progress listing all methods that have to be executed.
   *
   * @var array
   */
  protected $progress;

  /**
   * The filename including path to store the progress ID.
   *
   * @var string
   */
  protected $progressTrackerFile = '/tmp/progress-tracker-file.txt';

  /**
   * Sets the Progress Tracker file.
   *
   * @param string $filepath
   *   The progress tracker filename including full path.
   *
   * @throws \Exception
   */
  protected function setProgressTrackerFile($filepath) {
    $this->fileExistsOrDirectoryIsWritable($filepath);
    $this->progressTrackerFile = $filepath;
  }

  /**
   * Obtains the Progress tracking string.
   *
   * @param string $method
   *   The method name.
   * @param string $site_uri
   *   The Site URI.
   *
   * @return mixed|string
   *   The progress tracking string.
   */
  protected function getProgress($method, $site_uri) {
    $stage = str_replace(__CLASS__ . '::', '', $method);
    return empty($site_uri) ? $stage : $stage . '|' . $site_uri;
  }

  /**
   * Obtains the Progress ID.
   *
   * @param string $method
   *   The method name.
   * @param string $site_uri
   *   The Site URI.
   *
   * @return false|int|string
   *   The progress ID.
   */
  protected function getProgressId($method, $site_uri) {
    $progress = $this->getProgress($method, $site_uri);
    drush_print('We are in stage: ' . $progress);
    return array_search($progress, $this->progress);
  }

  /**
   * Tracks progress by writing the Progress ID in a temporal file.
   *
   * @param string $method
   *   The method name.
   * @param string $site_uri
   *   The Site URI.
   */
  protected function trackProgress($method, $site_uri) {
    $pid = $this->getProgressId($method, $site_uri);
    file_put_contents($this->progressTrackerFile, $pid, FILE_EXISTS_OVERWRITE);
    drush_print('We are in stage: ' . $pid);
  }

  /**
   * Obtains the Progress ID by reading from the temporal file.
   *
   * @return false|int|string
   *   The progress ID or FALSE.
   */
  protected function getStoredProgressId() {
    if ($pid = file_get_contents($this->progressTrackerFile)) {
      return $pid;
    }
    return 0;
  }

  /**
   * Checks whether a directory is writable or file exists.
   *
   * @param string $file_path
   *   The filename including path.
   *
   * @return bool
   *   TRUE if file exists and is writable or directory is writable.
   * @throws \Exception
   */
  protected function fileExistsOrDirectoryIsWritable($file_path) {
    if (!file_exists($file_path) && !is_writable(dirname($file_path))) {
      throw new \Exception(sprintf("The %s directory is not writable.", dirname($file_path)));
    }
    if (file_exists($file_path) && !is_writable($file_path)) {
      throw new \Exception(sprintf("The %s file is not writable.", $file_path));
    }
    return TRUE;
  }

  /**
   * Cleans up temporal file by deleting it.
   */
  protected function cleanUp() {
    unlink($this->progressTrackerFile);
  }
}
