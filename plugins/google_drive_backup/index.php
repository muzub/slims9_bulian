<?php

use SLiMS\Json;
use SLiMS\Plugins\GoogleDriveBackup\GoogleDriveBackup;

defined('INDEX_AUTH') or define('INDEX_AUTH', 1);
defined('DB_ACCESS') or define('DB_ACCESS', 'fa');

if (!defined('SB')) {
    require dirname(__DIR__, 2) . '/sysconfig.inc.php';
    require SB . 'admin/default/session.inc.php';
}

require SB . 'admin/default/session_check.inc.php';
require LIB . 'ip_based_access.inc.php';
require SIMBIO . 'simbio_DB/simbio_dbop.inc.php';

do_checkIP('smc');
do_checkIP('smc-system');

require_once __DIR__ . '/GoogleDriveBackup.php';

$canRead = utility::havePrivilege('system', 'r');
$canWrite = utility::havePrivilege('system', 'w');

if (!($canRead && $canWrite) || $_SESSION['uid'] != 1) {
    die('<div class="errorBox">' . __('You don\'t have enough privileges to view this section') . '</div>');
}

$action = $_GET['action'] ?? $_POST['action'] ?? '';

if ($action === 'upload') {
    header('Content-Type: application/json');

    try {
        if (empty($_POST['tkn']) || $_POST['tkn'] !== ($_SESSION['token'] ?? '')) {
            throw new \RuntimeException(__('Invalid upload request.'));
        }

        $backupLogId = (int) ($_POST['backup_log_id'] ?? 0);
        $query = $dbs->query('SELECT backup_file FROM backup_log WHERE backup_log_id=' . $backupLogId);
        $filePath = $query ? ($query->fetch_row()[0] ?? '') : '';

        if (empty($filePath)) {
            throw new \RuntimeException(__('Backup record was not found.'));
        }

        exit(Json::stringify(GoogleDriveBackup::uploadBackup($filePath, $backupLogId)));
    } catch (\Throwable $throwable) {
        exit(Json::stringify([
            'status' => false,
            'message' => $throwable->getMessage()
        ]));
    }
}

if ($action === 'connect') {
    try {
        header('Location: ' . GoogleDriveBackup::authorizationUrl());
    } catch (\Throwable $throwable) {
        utility::jsToastr(__('Google Drive Backup'), $throwable->getMessage(), 'error');
        echo '<script>top.$(\'#mainContent\').simbioAJAX(\'' . MWB . 'system/backup.php\')</script>';
    }
    exit;
}

if ($action === 'disconnect') {
    GoogleDriveBackup::disconnect();
    utility::jsToastr(__('Google Drive Backup'), __('Google Drive connection has been removed.'), 'success');
    echo '<script>window.location=\'' . GoogleDriveBackup::pageUrl() . '\'</script>';
    exit;
}

if ($action === 'callback') {
    try {
        GoogleDriveBackup::handleCallback($_GET);
        utility::jsToastr(__('Google Drive Backup'), __('Google Drive is connected.'), 'success');
    } catch (\Throwable $throwable) {
        utility::jsToastr(__('Google Drive Backup'), $throwable->getMessage(), 'error');
    }

    echo '<script>window.location=\'' . GoogleDriveBackup::pageUrl() . '\'</script>';
    exit;
}

$status = GoogleDriveBackup::status();
$latestUploads = GoogleDriveBackup::latestUploads();
?>
<div class="menuBox">
  <div class="menuBoxInner backupIcon">
    <div class="per_title">
      <h2><?php echo __('Google Drive Backup'); ?></h2>
    </div>
    <div class="sub_section">
      <div class="alert alert-info">
        <?php echo __('This integration uses browser based Google login. It works when an admin runs backup from SLiMS, without requiring server or hosting access.'); ?>
      </div>
      <div class="mb-3">
        <a class="btn btn-success" href="<?php echo MWB; ?>system/backup.php"><?php echo __('Open Database Backup'); ?></a>
        <a class="btn btn-secondary notAJAX openPopUp" href="<?php echo MWB; ?>system/backup_config.php"><?php echo __('Open Backup Settings'); ?></a>
        <?php if ($status['configured']): ?>
            <?php if ($status['connected']): ?>
            <a class="btn btn-danger" href="<?php echo GoogleDriveBackup::pageUrl(['action' => 'disconnect']); ?>"><?php echo __('Disconnect Google Drive'); ?></a>
            <?php else: ?>
            <a class="btn btn-primary" href="<?php echo GoogleDriveBackup::pageUrl(['action' => 'connect']); ?>"><?php echo __('Connect Google Drive'); ?></a>
            <?php endif; ?>
        <?php endif; ?>
      </div>
      <div class="card">
        <div class="card-body">
          <div><strong><?php echo __('Status'); ?>:</strong> <?php echo $status['connected'] ? __('Connected') : __('Not connected'); ?></div>
          <div><strong><?php echo __('Mode'); ?>:</strong> <?php echo $status['auto_upload'] ? __('Automatic upload after backup') : __('Manual upload from backup page'); ?></div>
          <div><strong><?php echo __('Account'); ?>:</strong> <?php echo $status['connected_email'] ?: '-'; ?></div>
          <div class="mt-2">
            <label class="font-weight-bold d-block"><?php echo __('Redirect URI'); ?></label>
            <input type="text" readonly class="form-control" value="<?php echo simbio_security::xssFree($status['redirect_uri']); ?>">
            <small class="text-muted"><?php echo __('Copy this URL into Google Cloud Console OAuth redirect URIs.'); ?></small>
          </div>
        </div>
      </div>
      <?php if (!empty($latestUploads)): ?>
      <div class="mt-3">
        <h5><?php echo __('Latest uploaded backups'); ?></h5>
        <ul class="list-group">
          <?php foreach ($latestUploads as $upload): ?>
          <li class="list-group-item d-flex justify-content-between align-items-center">
            <span><?php echo simbio_security::xssFree(($upload['name'] ?? '-') . ' - ' . ($upload['uploaded_at'] ?? '-')); ?></span>
            <a class="btn btn-sm btn-outline-primary" target="_blank" rel="noopener" href="<?php echo simbio_security::xssFree($upload['url'] ?? '#'); ?>"><?php echo __('Open in Google Drive'); ?></a>
          </li>
          <?php endforeach; ?>
        </ul>
      </div>
      <?php endif; ?>
    </div>
  </div>
</div>
