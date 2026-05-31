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

// Intercept fstream access for DRM-protected files using the existing hook.
// The hook passes ['fileID', 'biblioID', 'memberID', 'userID', 'file_d'] as $data.
$plugin->registerHook('fstream_all_before_download', function ($data) {
    $biblioId = (int) ($data['biblioID'] ?? 0);
    $fileId   = (int) ($data['fileID'] ?? 0);
    if ($biblioId < 1 || $fileId < 1) {
        return;
    }
    if (!digital_drm_reader_direct_access_allowed($biblioId, $fileId)) {
        if (utility::isMemberLogin()) {
            header('Location: ' . SWB . 'plugins/digital_drm_reader/open.php?bid=' . $biblioId . '&aid=' . $fileId . '&fid=' . $fileId);
        } else {
            header('Location: index.php?p=member');
        }
        exit;
    }
});

// Intercept multimediastream access for DRM-protected files.
// This hook fires before file data is fetched, so we read GET params directly.
$plugin->registerHook('fstream_vid_before_download', function () {
    $fileId   = isset($_GET['fid']) ? (int) $_GET['fid'] : 0;
    $biblioId = isset($_GET['bid']) ? (int) $_GET['bid'] : 0;
    if ($fileId < 1 || $biblioId < 1) {
        return;
    }
    if (!digital_drm_reader_direct_access_allowed($biblioId, $fileId)) {
        if (utility::isMemberLogin()) {
            header('Location: ' . SWB . 'plugins/digital_drm_reader/open.php?bid=' . $biblioId . '&aid=' . $fileId . '&fid=' . $fileId);
        } else {
            header('Location: index.php?p=member');
        }
        exit;
    }
});
