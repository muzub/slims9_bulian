<?php
/**
 * Plugin Name: Safe Import Rollback
 * Plugin URI: https://github.com/slims/slims9_bulian
 * Description: Import bibliographic and item data with preview, history, and rollback support.
 * Version: 0.1.0
 * Author: GitHub Copilot
 * Author URI: https://github.com/features/copilot
 */

$plugin = \SLiMS\Plugins::getInstance();
$plugin->registerMenu('bibliography', __('Safe Import & Rollback'), __DIR__ . '/index.php', __('Import bibliography and item data with rollback support'));
