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

$biblioId = (int)($_GET['bid'] ?? 0);
$attachmentId = (int)($_GET['aid'] ?? 0);
$fileId = (int)($_GET['fid'] ?? 0);

$attachment = digital_drm_reader_get_attachment($biblioId, $attachmentId, $fileId);
if (!$attachment) {
    die(__('Digital attachment not found'));
}

$map = digital_drm_reader_get_map_by_attachment($attachmentId);
if (!$map) {
    header('Location: ' . SWB . 'index.php?p=fstream&fid=' . $fileId . '&bid=' . $biblioId);
    exit;
}

list($ok, $tokenOrError) = digital_drm_reader_create_session((int)$_SESSION['mid'], $map);
if (!$ok) {
    die('<h3>' . htmlspecialchars((string)$tokenOrError) . '</h3>');
}

header('Location: ' . SWB . 'plugins/digital_drm_reader/reader.php?token=' . urlencode($tokenOrError));
exit;
