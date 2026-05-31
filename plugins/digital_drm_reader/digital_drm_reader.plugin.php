<?php
/**
 * Plugin Name: Digital DRM Reader
 * Plugin URI: https://github.com/muzub/slims9_bulian
 * Description: Protect digital attachments with tokenized online reader/player access.
 * Version: 0.1.0
 * Author: SLiMS Contributor
 */

require_once __DIR__ . '/bootstrap.php';

$plugin = \SLiMS\Plugins::getInstance();
$plugin->registerMenu('bibliography', __('Digital DRM Reader'), __DIR__ . '/index.php');

digital_drm_reader_init();
