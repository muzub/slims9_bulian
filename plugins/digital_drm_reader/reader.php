<?php

define('INDEX_AUTH', '1');
define('DB_ACCESS', 'fa');

require '../../sysconfig.inc.php';
require LIB . 'ip_based_access.inc.php';
do_checkIP('opac');
require SB . 'admin/default/session.inc.php';

if ($sysconf['member_login']) {
    require_once LIB . 'member_logon.inc.php';
}

require_once __DIR__ . '/bootstrap.php';
digital_drm_reader_init();

if (!utility::isMemberLogin()) {
    header('Location: ' . SWB . 'index.php?p=member');
    exit;
}

$token = trim((string)($_GET['token'] ?? ''));
$session = digital_drm_reader_validate_session($token);
if (!$session || (int)$session['member_id'] !== (int)$_SESSION['mid']) {
    die(__('Digital session is invalid or expired'));
}

$attachment = digital_drm_reader_get_attachment_by_session($session);
if (!$attachment) {
    die(__('Digital attachment is no longer available'));
}

$streamUrl = SWB . 'plugins/digital_drm_reader/stream.php?token=' . urlencode($token);
$title = trim((string)($attachment['file_title'] ?? $attachment['title'] ?? 'Digital Content'));
$viewMode = $session['view_mode'];
?><!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta http-equiv="Cache-Control" content="no-store, no-cache, must-revalidate, max-age=0">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= htmlspecialchars($title) ?> - Digital DRM Reader</title>
    <style>
        body { font-family: Arial, sans-serif; margin:0; background:#111; color:#fff; }
        .toolbar { padding: 12px 16px; background:#1f1f1f; border-bottom:1px solid #333; display:flex; justify-content:space-between; gap:8px; align-items:center; }
        .viewer { padding: 8px; height: calc(100vh - 58px); }
        iframe, video, audio, img { width:100%; height:100%; border:0; }
        audio { height: 80px; margin-top: 24px; }
        .badge { background:#2e7d32; color:#fff; padding:4px 8px; border-radius:4px; font-size:12px; }
    </style>
</head>
<body>
<div class="toolbar">
    <div><strong><?= htmlspecialchars($title) ?></strong></div>
    <div>
        <span class="badge"><?= htmlspecialchars($session['profile_name']) ?></span>
        <?php if ((int)$session['allow_download'] === 1): ?>
            <a style="color:#8bc34a; margin-left:12px" href="<?= htmlspecialchars($streamUrl . '&download=1') ?>"><?= __('Download') ?></a>
        <?php endif; ?>
    </div>
</div>
<div class="viewer">
    <?php if ($viewMode === 'pdf_reader'): ?>
        <iframe src="<?= htmlspecialchars(SWB . 'js/pdfjs/web/viewer.php?file=' . urlencode($streamUrl)) ?>"></iframe>
    <?php elseif ($viewMode === 'image_viewer'): ?>
        <img alt="<?= htmlspecialchars($title) ?>" src="<?= htmlspecialchars($streamUrl) ?>">
    <?php elseif ($viewMode === 'audio_player'): ?>
        <audio controls controlsList="nodownload" src="<?= htmlspecialchars($streamUrl) ?>"></audio>
    <?php elseif ($viewMode === 'video_player'): ?>
        <video controls controlsList="nodownload" src="<?= htmlspecialchars($streamUrl) ?>"></video>
    <?php else: ?>
        <iframe src="<?= htmlspecialchars($streamUrl) ?>"></iframe>
    <?php endif; ?>
</div>
</body>
</html>
