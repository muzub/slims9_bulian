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

$token = trim((string)($_GET['token'] ?? ''));
$session = digital_drm_reader_validate_session($token);
if (!$session) {
    http_response_code(403);
    exit('Invalid or expired token');
}

if (!utility::isMemberLogin() || (int)$_SESSION['mid'] !== (int)$session['member_id']) {
    http_response_code(403);
    exit('Unauthorized');
}

$attachment = digital_drm_reader_get_attachment_by_session($session);
if (!$attachment) {
    http_response_code(404);
    exit('File not found');
}

$filePath = digital_drm_reader_file_path($attachment);
if (!$filePath || !file_exists($filePath) || !is_readable($filePath)) {
    http_response_code(404);
    exit('File not found');
}

$allowDownload = ((int)$session['allow_download'] === 1);
$downloadRequested = isset($_GET['download']) && $_GET['download'] === '1';
if ($downloadRequested && !$allowDownload) {
    http_response_code(403);
    exit('Download not allowed');
}

digital_drm_reader_send_nocache_headers();

$mimeType = !empty($attachment['mime_type']) ? $attachment['mime_type'] : 'application/octet-stream';
$fileSize = filesize($filePath);
$filename = basename($attachment['file_name']);
$safeFilename = preg_replace('/[^A-Za-z0-9._-]/', '_', $filename);
if ($safeFilename === '') {
    $safeFilename = 'digital-file';
}

if ($downloadRequested) {
    header('Content-Type: application/octet-stream');
    header('Content-Disposition: attachment; filename="' . $safeFilename . '"; filename*=UTF-8\'\'' . rawurlencode($safeFilename));
    header('Content-Length: ' . $fileSize);
    readfile($filePath);
    exit;
}

header('Content-Type: ' . $mimeType);
header('Accept-Ranges: bytes');

if (isset($_SERVER['HTTP_RANGE'])) {
    $rangeParts = explode('=', $_SERVER['HTTP_RANGE'], 2);
    if (count($rangeParts) === 2) {
        list($unit, $range) = $rangeParts;
    } else {
        $unit = '';
        $range = '';
    }

    if ($unit === 'bytes') {
        list($start, $end) = array_pad(explode('-', $range, 2), 2, '');
        $start = ($start === '') ? 0 : (int)$start;
        $end = ($end === '') ? ($fileSize - 1) : (int)$end;
        $start = max(0, $start);
        $end = min($fileSize - 1, $end);

        if ($end >= $start) {
            $length = $end - $start + 1;
            header('HTTP/1.1 206 Partial Content');
            header("Content-Range: bytes {$start}-{$end}/{$fileSize}");
            header('Content-Length: ' . $length);

            $fp = fopen($filePath, 'rb');
            if ($fp === false) {
                http_response_code(500);
                exit('Unable to open stream');
            }
            fseek($fp, $start);
            $chunk = 8192;
            $remaining = $length;
            while ($remaining > 0 && !feof($fp)) {
                $read = ($remaining > $chunk) ? $chunk : $remaining;
                echo fread($fp, $read);
                $remaining -= $read;
                flush();
            }
            fclose($fp);
            exit;
        }
    }
}

header('Content-Length: ' . $fileSize);
readfile($filePath);
exit;
