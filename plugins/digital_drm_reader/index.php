<?php

define('INDEX_AUTH', '1');
define('DB_ACCESS', 'fa');

require '../../../sysconfig.inc.php';
require LIB . 'ip_based_access.inc.php';
do_checkIP('smc');
require SB . 'admin/default/session.inc.php';
require SB . 'admin/default/session_check.inc.php';
require_once __DIR__ . '/bootstrap.php';

digital_drm_reader_init();

if (!utility::havePrivilege('bibliography', 'r')) {
    die('<div class="errorBox">' . __('You don\'t have enough privileges to access this area!') . '</div>');
}

$canWrite = utility::havePrivilege('bibliography', 'w');
$message = '';
$error = false;

if ($canWrite && isset($_POST['drm_action'])) {
    $action = trim($_POST['drm_action']);

    if ($action === 'save_profile') {
        $name = trim($_POST['profile_name'] ?? '');
        $printLimit = max(0, (int)($_POST['print_limit'] ?? 0));
        $allowDownload = isset($_POST['allow_download']) ? 1 : 0;
        $durationDays = max(1, (int)($_POST['access_duration_days'] ?? 1));
        $parallel = max(1, (int)($_POST['max_parallel_sessions'] ?? 1));

        if ($name === '') {
            $message = __('Profile name is required');
            $error = true;
        } else {
            $nameEsc = $dbs->escape_string($name);
            $ok = $dbs->query("INSERT INTO digital_drm_profile (profile_name, print_limit, allow_download, access_duration_days, max_parallel_sessions)
                VALUES ('{$nameEsc}', {$printLimit}, {$allowDownload}, {$durationDays}, {$parallel})");
            $message = $ok ? __('DRM profile saved') : __('Failed to save DRM profile');
            $error = !$ok;
        }
    }

    if ($action === 'save_map') {
        $biblioId = (int)($_POST['biblio_id'] ?? 0);
        $attachmentId = (int)($_POST['attachment_id'] ?? 0);
        $profileId = (int)($_POST['profile_id'] ?? 0);
        $viewMode = trim($_POST['view_mode'] ?? 'other');
        $allowedMode = ['pdf_reader', 'image_viewer', 'audio_player', 'video_player', 'other'];
        if (!in_array($viewMode, $allowedMode, true)) {
            $viewMode = 'other';
        }

        $attachmentQ = $dbs->query("SELECT biblio_id, file_id FROM biblio_attachment WHERE file_id={$attachmentId} AND biblio_id={$biblioId} LIMIT 1");
        if (!$attachmentQ || $attachmentQ->num_rows < 1) {
            $message = __('Attachment not found for selected bibliography');
            $error = true;
        } else {
            $att = $attachmentQ->fetch_assoc();
            $mapFileId = (int)$att['file_id'];
            $ok = $dbs->query("INSERT INTO digital_drm_map (biblio_id, attachment_id, file_id, profile_id, view_mode)
                VALUES ({$biblioId}, {$mapFileId}, {$mapFileId}, {$profileId}, '" . $dbs->escape_string($viewMode) . "')
                ON DUPLICATE KEY UPDATE
                    biblio_id=VALUES(biblio_id),
                    file_id=VALUES(file_id),
                    profile_id=VALUES(profile_id),
                    view_mode=VALUES(view_mode)");
            $message = $ok ? __('DRM mapping saved') : __('Failed to save DRM mapping');
            $error = !$ok;
        }
    }

    if ($action === 'save_setting') {
        $requireLoan = isset($_POST['require_active_loan']) ? '1' : '0';
        $ok = digital_drm_reader_set_setting('require_active_loan', $requireLoan);
        $message = $ok ? __('Settings saved') : __('Failed to save settings');
        $error = !$ok;
    }
}

$profiles = [];
$profileQ = $dbs->query('SELECT * FROM digital_drm_profile ORDER BY profile_name ASC');
if ($profileQ) {
    while ($row = $profileQ->fetch_assoc()) {
        $profiles[] = $row;
    }
}

$biblioIdFilter = isset($_GET['biblio_id']) ? (int)$_GET['biblio_id'] : 0;
$attachments = [];
if ($biblioIdFilter > 0) {
    $attachmentQ = $dbs->query("SELECT att.file_id, f.file_title, f.file_name
        FROM biblio_attachment AS att
        INNER JOIN files AS f ON f.file_id = att.file_id
        WHERE att.biblio_id={$biblioIdFilter}
        ORDER BY att.file_id DESC");
    if ($attachmentQ) {
        while ($row = $attachmentQ->fetch_assoc()) {
            $attachments[] = $row;
        }
    }
}

$maps = [];
$mapQ = $dbs->query("SELECT m.*, p.profile_name, b.title AS biblio_title, f.file_title, f.file_name
    FROM digital_drm_map AS m
    INNER JOIN digital_drm_profile AS p ON p.profile_id = m.profile_id
    LEFT JOIN biblio AS b ON b.biblio_id = m.biblio_id
    LEFT JOIN files AS f ON f.file_id = m.file_id
    ORDER BY m.map_id DESC");
if ($mapQ) {
    while ($row = $mapQ->fetch_assoc()) {
        $maps[] = $row;
    }
}

$requireActiveLoan = ((int)digital_drm_reader_get_setting('require_active_loan', '0') === 1);

echo '<div class="menuBox"><div class="menuBoxInner p-3">';
echo '<h3>' . __('Digital DRM Reader') . '</h3>';
echo '<p class="mb-3">' . __('Protect bibliographic digital attachments and force access through internal reader/player token session.') . '</p>';

if ($message !== '') {
    echo '<div class="' . ($error ? 'errorBox' : 'infoBox') . '"><div class="infoBoxContent">' . $message . '</div></div>';
}

if ($canWrite) {
    echo '<div class="card mb-3"><div class="card-header">' . __('DRM Settings') . '</div><div class="card-body">';
    echo '<form method="post">';
    echo '<input type="hidden" name="drm_action" value="save_setting">';
    echo '<label><input type="checkbox" name="require_active_loan" value="1" ' . ($requireActiveLoan ? 'checked' : '') . '> ' . __('Require active circulation loan before creating digital session') . '</label><br>';
    echo '<button type="submit" class="btn btn-primary btn-sm mt-2">' . __('Save Settings') . '</button>';
    echo '</form></div></div>';

    echo '<div class="card mb-3"><div class="card-header">' . __('Create DRM Profile') . '</div><div class="card-body">';
    echo '<form method="post" class="form-inline">';
    echo '<input type="hidden" name="drm_action" value="save_profile">';
    echo '<div class="form-group mr-2 mb-2"><input class="form-control" type="text" name="profile_name" placeholder="' . __('Profile Name') . '" required></div>';
    echo '<div class="form-group mr-2 mb-2"><input class="form-control" type="number" name="print_limit" min="0" value="0" placeholder="' . __('Print Limit') . '"></div>';
    echo '<div class="form-group mr-2 mb-2"><input class="form-control" type="number" name="access_duration_days" min="1" value="1" placeholder="' . __('Access Days') . '"></div>';
    echo '<div class="form-group mr-2 mb-2"><input class="form-control" type="number" name="max_parallel_sessions" min="1" value="1" placeholder="' . __('Max Parallel Sessions') . '"></div>';
    echo '<div class="form-group mr-2 mb-2"><label><input type="checkbox" name="allow_download" value="1"> ' . __('Allow Download') . '</label></div>';
    echo '<button type="submit" class="btn btn-success btn-sm mb-2">' . __('Save Profile') . '</button>';
    echo '</form></div></div>';

    echo '<div class="card mb-3"><div class="card-header">' . __('Map Attachment to DRM Profile') . '</div><div class="card-body">';
    echo '<form method="get" class="form-inline mb-3">';
    echo '<div class="form-group mr-2"><input class="form-control" type="number" name="biblio_id" min="1" value="' . $biblioIdFilter . '" placeholder="Biblio ID"></div>';
    echo '<button type="submit" class="btn btn-info btn-sm">' . __('Load Attachments') . '</button>';
    echo '</form>';

    echo '<form method="post" class="form-inline">';
    echo '<input type="hidden" name="drm_action" value="save_map">';
    echo '<input type="hidden" name="biblio_id" value="' . $biblioIdFilter . '">';

    echo '<div class="form-group mr-2 mb-2"><select class="form-control" name="attachment_id" required>';
    echo '<option value="">' . __('Select Attachment') . '</option>';
    foreach ($attachments as $attachment) {
        $label = '#' . (int)$attachment['file_id'] . ' - ' . ($attachment['file_title'] ?: $attachment['file_name']);
        echo '<option value="' . (int)$attachment['file_id'] . '">' . htmlspecialchars($label) . '</option>';
    }
    echo '</select></div>';

    echo '<div class="form-group mr-2 mb-2"><select class="form-control" name="profile_id" required>';
    echo '<option value="">' . __('Select DRM Profile') . '</option>';
    foreach ($profiles as $profile) {
        echo '<option value="' . (int)$profile['profile_id'] . '">' . htmlspecialchars($profile['profile_name']) . '</option>';
    }
    echo '</select></div>';

    echo '<div class="form-group mr-2 mb-2"><select class="form-control" name="view_mode">';
    foreach (['pdf_reader', 'image_viewer', 'audio_player', 'video_player', 'other'] as $mode) {
        echo '<option value="' . $mode . '">' . $mode . '</option>';
    }
    echo '</select></div>';

    echo '<button type="submit" class="btn btn-primary btn-sm mb-2">' . __('Save Mapping') . '</button>';
    echo '</form></div></div>';
}

echo '<div class="card mb-3"><div class="card-header">' . __('DRM Profiles') . '</div><div class="card-body">';
if (count($profiles) < 1) {
    echo '<div class="text-muted">' . __('No profile available') . '</div>';
} else {
    echo '<table class="table table-sm"><thead><tr><th>ID</th><th>' . __('Profile') . '</th><th>' . __('Print Limit') . '</th><th>' . __('Allow Download') . '</th><th>' . __('Access Days') . '</th><th>' . __('Max Sessions') . '</th></tr></thead><tbody>';
    foreach ($profiles as $profile) {
        echo '<tr><td>' . (int)$profile['profile_id'] . '</td><td>' . htmlspecialchars($profile['profile_name']) . '</td><td>' . (int)$profile['print_limit'] . '</td><td>' . ((int)$profile['allow_download'] === 1 ? __('Yes') : __('No')) . '</td><td>' . (int)$profile['access_duration_days'] . '</td><td>' . (int)$profile['max_parallel_sessions'] . '</td></tr>';
    }
    echo '</tbody></table>';
}
echo '</div></div>';

echo '<div class="card"><div class="card-header">' . __('DRM Mappings') . '</div><div class="card-body">';
if (count($maps) < 1) {
    echo '<div class="text-muted">' . __('No mapped attachment yet') . '</div>';
} else {
    echo '<table class="table table-sm"><thead><tr><th>ID</th><th>' . __('Biblio ID') . '</th><th>' . __('Title') . '</th><th>' . __('Attachment') . '</th><th>' . __('Profile') . '</th><th>' . __('View Mode') . '</th></tr></thead><tbody>';
    foreach ($maps as $map) {
        $attachmentName = $map['file_title'] ?: $map['file_name'];
        echo '<tr><td>' . (int)$map['map_id'] . '</td><td>' . (int)$map['biblio_id'] . '</td><td>' . htmlspecialchars((string)$map['biblio_title']) . '</td><td>#' . (int)$map['file_id'] . ' ' . htmlspecialchars((string)$attachmentName) . '</td><td>' . htmlspecialchars($map['profile_name']) . '</td><td>' . htmlspecialchars($map['view_mode']) . '</td></tr>';
    }
    echo '</tbody></table>';
}
echo '</div></div>';

echo '</div></div>';
