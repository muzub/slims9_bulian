<?php
/**
 * Plugin Name: Google Drive Backup
 * Plugin URI: https://github.com/muzub/slims9_bulian
 * Description: Browser based Google Drive integration for database backup uploads
 * Version: 1.0.0
 * Author: GitHub Copilot
 * Author URI: https://github.com/features/copilot
 */

$plugin = \SLiMS\Plugins::getInstance();
$plugin->registerMenu('system', __('Google Drive Backup'), __DIR__ . '/index.php');
