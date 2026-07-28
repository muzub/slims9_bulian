<?php
/**
 * Plugin Name: Bulk Member Mailer
 * Plugin URI: https://slims.web.id
 * Description: Send bulk email to all members with configurable batch processing and failure report
 * Version: 0.0.1
 * Author: SLiMS Community
 * Author URI: https://slims.web.id
 */

$plugin = \SLiMS\Plugins::getInstance();
$plugin->registerMenu('membership', __('Bulk Member Mailer'), __DIR__ . '/index.php');
