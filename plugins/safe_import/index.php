<?php

defined('INDEX_AUTH') OR die('Direct access not allowed!');

use SLiMS\Filesystems\Storage;

require LIB . 'ip_based_access.inc.php';
do_checkIP('smc');
do_checkIP('smc-bibliography');
require SB . 'admin/default/session.inc.php';
require SIMBIO . 'simbio_GUI/table/simbio_table.inc.php';
require SIMBIO . 'simbio_DB/simbio_dbop.inc.php';
require MDLBS . 'bibliography/biblio_utils.inc.php';
require __DIR__ . '/helper.php';

$can_read = utility::havePrivilege('bibliography', 'r');
$can_write = utility::havePrivilege('bibliography', 'w');

if (!$can_read) {
    die('<div class="errorBox">' . __('You are not authorized to view this section') . '</div>');
}

$settings = safeImportSettings();
$flashMessage = '';
$flashClass = 'infoBox';

if ($sysconf['index']['type'] == 'index') {
    require MDLBS.'system/biblio_indexer.inc.php';
    $indexer = new biblio_indexer($dbs);
}

if (isset($_GET['download_sample'])) {
    safeImportDownloadSample($_GET['download_sample'] === 'item' ? 'item' : 'biblio');
}

if (isset($_GET['cancel']) && $can_write) {
    $session = safeImportGetSession((int)$_GET['cancel']);
    if ($session) {
        safeImportDeleteTempFile($session['temp_file']);
        safeImportUpdateSession((int)$session['id'], [
            'status' => 'canceled',
            'temp_file' => null,
            'updated_at' => safeImportNow()
        ]);
        $flashMessage = __('Import preview has been canceled.');
    }
}

if (isset($_POST['saveSettings']) && $can_write) {
    $settings = safeImportSaveSettings($_POST);
    $flashMessage = __('Import settings have been saved.');
    $flashClass = 'infoBox';
}

if (isset($_POST['prepareImport']) && $can_write) {
    safeImportEnsureStorage();

    if (empty($_FILES['importFile']['name']) || empty($_POST['fieldSep']) || empty($_POST['fieldEnc'])) {
        $flashMessage = __('Required fields (*) must be filled correctly!');
        $flashClass = 'errorBox';
    } else {
        $disk = Storage::files();
        $importType = $_POST['import_type'] === 'item' ? 'item' : 'biblio';
        $batchName = trim($_POST['batch_name'] ?? '');
        if ($batchName === '') $batchName = strtoupper($importType) . ' ' . date('Ymd-His');
        $fileHash = md5($_FILES['importFile']['name'] . microtime(true));
        $tempFile = safeImportStorageDirectory() . DS . $fileHash . '.csv';

        if ($disk->isExists($tempFile)) $disk->delete($tempFile);

        $upload = $disk->upload('importFile', function($file) use ($sysconf) {
            $file->isExtensionAllowed(['.csv']);
            $file->isLimitExceeded($sysconf['max_upload']*1024);
            if (!empty($file->getError())) $file->destroyIfFailed();
        })->as(substr($tempFile, 0, -4));

        if (!$upload->getUploadStatus()) {
            $flashMessage = __('Upload failed! File type not allowed or the file is larger than the configured maximum upload size.');
            $flashClass = 'errorBox';
        } else {
            $sessionId = safeImportCreateSession([
                'batch_name' => $batchName,
                'import_type' => $importType,
                'file_name' => $_FILES['importFile']['name'],
                'temp_file' => $tempFile,
                'format_options' => [
                    'recordNum' => (int)($_POST['recordNum'] ?? 0),
                    'fieldEnc' => trim($_POST['fieldEnc']),
                    'fieldSep' => trim($_POST['fieldSep']),
                    'recordOffset' => (int)($_POST['recordOffset'] ?? 1),
                    'header' => empty($_POST['header']) ? 0 : 1
                ],
                'notes' => trim($_POST['notes'] ?? ''),
                'uid' => $_SESSION['uid'] ?? null
            ]);

            header('Location: ' . safeImportUrl(['preview' => $sessionId], ['cancel']));
            exit;
        }
    }
}

if (isset($_POST['executeImport']) && $can_write) {
    $sessionId = (int)($_POST['session_id'] ?? 0);
    $reloadUrl = safeImportUrl([], ['preview', 'cancel']);

    try {
        $result = safeImportRun($sessionId, $settings, $dbs, $indexer ?? null);
        $message = str_replace(
            ['{success}', '{processed}', '{skipped}'],
            [$result['success_rows'], $result['processed_rows'], $result['skipped_rows']],
            __('Import finished. Success: <strong>{success}</strong>, processed: <strong>{processed}</strong>, skipped/errors: <strong>{skipped}</strong>.')
        );
        if (!empty($result['error_message'])) $message .= '<br><small>' . nl2br(htmlspecialchars($result['error_message'])) . '</small>';
        $toastMessage = json_encode(strip_tags(str_replace('<br>', ' ', $message)));
        exit(<<<HTML
<script>
parent.$('#preview').addClass('d-none');
parent.$('#progress').removeClass('d-none');
parent.$('.progress-bar').attr('style', 'width: 100%').html('100%');
parent.$('#mainContent').simbioAJAX('{$reloadUrl}');
parent.toastr && parent.toastr.success({$toastMessage});
</script>
HTML);
    } catch (Throwable $error) {
        $safeMessage = htmlspecialchars($error->getMessage(), ENT_QUOTES);
        safeImportUpdateSession($sessionId, [
            'status' => 'failed',
            'error_message' => $error->getMessage(),
            'updated_at' => safeImportNow(),
            'finished_at' => safeImportNow()
        ]);
        exit(<<<HTML
<script>
parent.$('.infoBox').html('{$safeMessage}');
parent.$('.infoBox').addClass('errorBox');
parent.$('.infoBox').removeClass('infoBox');
parent.$('#mainContent').simbioAJAX('{$reloadUrl}');
</script>
HTML);
    }
}

if (isset($_POST['rollbackImport']) && $can_write) {
    $sessionId = (int)($_POST['session_id'] ?? 0);
    $reloadUrl = safeImportUrl([], ['preview', 'cancel']);

    try {
        $result = safeImportRollback($sessionId, $dbs, $indexer ?? null);
        $message = str_replace('{row_count}', $result['rollback_rows'], __('Rollback finished. Restored/deleted <strong>{row_count}</strong> logged record(s).'));
        $toastMessage = json_encode(strip_tags($message));
        exit(<<<HTML
<script>
parent.$('#mainContent').simbioAJAX('{$reloadUrl}');
parent.toastr && parent.toastr.success({$toastMessage});
</script>
HTML);
    } catch (Throwable $error) {
        $safeMessage = json_encode($error->getMessage());
        exit(<<<HTML
<script>
parent.$('#mainContent').simbioAJAX('{$reloadUrl}');
parent.toastr && parent.toastr.error({$safeMessage});
</script>
HTML);
    }
}

$previewSession = isset($_GET['preview']) ? safeImportGetSession((int)$_GET['preview']) : null;
$previewLimit = isset($_GET['perpage']) ? max(1, (int)$_GET['perpage']) : (int)$settings['preview_per_page'];
$recentSessions = safeImportRecentSessions((int)$settings['history_limit']);
?>

<div class="menuBox">
    <div class="menuBoxInner importIcon">
        <div class="per_title">
            <h2><?php echo __('Safe Import & Rollback'); ?></h2>
        </div>
        <div class="infoBox">
            <?= __('Use this plugin to import bibliographic or item/exemplar CSV data with preview, import history, and manual rollback when something goes wrong.') ?>
        </div>
        <?php if (!empty($flashMessage)): ?>
        <div class="<?= $flashClass ?> mt-2"><?= $flashMessage ?></div>
        <?php endif; ?>
    </div>
</div>

<div id="progress" class="d-none my-2 mx-2">
    <p class="w-100 block"><?= __('Importing data to SLiMS') ?></p>
    <div class="progress">
        <div class="progress-bar progress-bar-striped progress-bar-animated" role="progressbar" style="width: 0%">0%</div>
    </div>
</div>

<?php if ($previewSession): ?>
<?php
    $previewRows = safeImportPreviewRows($previewSession, $previewLimit);
    $columns = safeImportColumns($previewSession['import_type']);
?>
<div id="preview" class="my-3 mx-2">
    <div class="card mb-3">
        <div class="card-body">
            <h4 class="card-title"><?= __('Preview Import') ?></h4>
            <p class="card-text mb-1"><strong><?= __('Batch') ?>:</strong> <?= htmlspecialchars($previewSession['batch_name']) ?></p>
            <p class="card-text mb-1"><strong><?= __('Type') ?>:</strong> <?= htmlspecialchars($columns['label']) ?></p>
            <p class="card-text"><strong><?= __('File') ?>:</strong> <?= htmlspecialchars($previewSession['file_name']) ?></p>
            <?php if (!empty($previewSession['notes'])): ?>
            <p class="card-text"><strong><?= __('Notes') ?>:</strong> <?= nl2br(htmlspecialchars($previewSession['notes'])) ?></p>
            <?php endif; ?>
            <div class="d-flex flex-row align-items-center">
                <label class="mr-2"><?= __('Show per') ?>:</label>
                <select class="perpage form-control col-2">
                    <?php foreach ([5,10,15,20,25,30,40,50,100] as $num): ?>
                    <option value="<?= $num ?>" <?= $previewLimit === $num ? 'selected' : '' ?>><?= $num ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
        </div>
    </div>

    <div class="overflow-auto">
        <?php
        $table = new simbio_table();
        $table->table_attr = 'class="table table-bordered table-striped"';
        $table->appendTableRow(explode(',', $columns['header']));
        foreach ($previewRows as $order => $field) {
            $field = array_map(static fn($value) => htmlspecialchars((string)$value), $field);
            $table->appendTableRow(array_merge([$order + 1], $field));
        }
        echo $table->printTable();
        ?>
    </div>

    <div class="d-flex flex-row mt-3">
        <form action="<?= safeImportUrl([], ['preview', 'cancel']) ?>" method="post" target="blindSubmit" class="mr-2">
            <input type="hidden" name="session_id" value="<?= (int)$previewSession['id'] ?>">
            <button type="submit" name="executeImport" class="btn btn-primary"><?= __('Import Now') ?></button>
        </form>
        <a href="<?= safeImportUrl([], ['preview']) ?>&cancel=<?= (int)$previewSession['id'] ?>" class="btn btn-secondary"><?= __('Cancel Preview') ?></a>
    </div>
</div>
<?php endif; ?>

<div class="row mx-1">
    <div class="col-lg-7">
        <div class="card mb-3">
            <div class="card-header"><?= __('New Import') ?></div>
            <div class="card-body">
                <form action="<?= safeImportUrl([], ['preview', 'cancel']) ?>" method="post" enctype="multipart/form-data">
                    <div class="form-group">
                        <label><?= __('Import Type') ?> *</label>
                        <select name="import_type" class="form-control">
                            <option value="biblio"><?= __('Biblio Import') ?></option>
                            <option value="item"><?= __('Item / Exemplar Import') ?></option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label><?= __('Batch Name') ?></label>
                        <input type="text" name="batch_name" class="form-control" value="<?= 'BIBLIO ' . date('Ymd-His') ?>">
                        <small class="form-text text-muted"><?= __('Use a clear batch name so it is easy to find later for rollback.') ?></small>
                    </div>
                    <div class="form-group">
                        <label><?= __('Notes') ?></label>
                        <textarea name="notes" class="form-control" rows="2" placeholder="<?= __('Optional note about this import batch') ?>"></textarea>
                    </div>
                    <div class="form-group">
                        <label><?= __('CSV File') ?> *</label>
                        <input type="file" name="importFile" class="form-control-file" accept=".csv">
                        <small class="form-text text-muted"><?= __('Maximum') ?> <?= $sysconf['max_upload'] ?> KB</small>
                    </div>
                    <div class="form-row">
                        <div class="form-group col-md-3">
                            <label><?= __('Field Separator') ?> *</label>
                            <input type="text" name="fieldSep" class="form-control" maxlength="3" value="<?= htmlentities(config('csv.separator')) ?>">
                        </div>
                        <div class="form-group col-md-3">
                            <label><?= __('Field Enclosed With') ?> *</label>
                            <input type="text" name="fieldEnc" class="form-control" value="<?= htmlentities(config('csv.enclosed_with')) ?>">
                        </div>
                        <div class="form-group col-md-3">
                            <label><?= __('Number of Records') ?></label>
                            <input type="number" min="0" name="recordNum" class="form-control" value="0">
                        </div>
                        <div class="form-group col-md-3">
                            <label><?= __('Start From Record') ?></label>
                            <input type="number" min="1" name="recordOffset" class="form-control" value="1">
                        </div>
                    </div>
                    <div class="form-group form-check">
                        <input type="checkbox" name="header" value="1" class="form-check-input" id="safe-import-header" checked>
                        <label class="form-check-label" for="safe-import-header"><?= __('The first row is the columns names') ?></label>
                    </div>
                    <div class="btn-group">
                        <button type="submit" name="prepareImport" class="btn btn-primary"><?= __('Preview Import') ?></button>
                        <a href="<?= safeImportUrl(['download_sample' => 'biblio']) ?>" class="btn btn-secondary notAJAX"><?= __('Download Biblio Sample') ?></a>
                        <a href="<?= safeImportUrl(['download_sample' => 'item']) ?>" class="btn btn-secondary notAJAX"><?= __('Download Item Sample') ?></a>
                    </div>
                </form>
            </div>
        </div>

        <div class="card mb-3">
            <div class="card-header"><?= __('Import History & Rollback') ?></div>
            <div class="card-body table-responsive">
                <table class="table table-bordered table-striped">
                    <thead>
                    <tr>
                        <th><?= __('Batch') ?></th>
                        <th><?= __('Type') ?></th>
                        <th><?= __('Status') ?></th>
                        <th><?= __('Result') ?></th>
                        <th><?= __('Created') ?></th>
                        <th><?= __('Action') ?></th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php if (empty($recentSessions)): ?>
                    <tr><td colspan="6"><?= __('No import history yet.') ?></td></tr>
                    <?php endif; ?>
                    <?php foreach ($recentSessions as $session): ?>
                    <tr>
                        <td>
                            <div class="font-weight-bold"><?= htmlspecialchars($session['batch_name']) ?></div>
                            <small><?= htmlspecialchars($session['file_name']) ?></small>
                            <?php if (!empty($session['error_message'])): ?>
                            <div><small class="text-danger"><?= nl2br(htmlspecialchars($session['error_message'])) ?></small></div>
                            <?php endif; ?>
                        </td>
                        <td><?= htmlspecialchars(safeImportColumns($session['import_type'])['label']) ?></td>
                        <td><span class="badge badge-<?= safeImportStatusClass($session['status']) ?>"><?= htmlspecialchars(safeImportStatusLabel($session['status'])) ?></span></td>
                        <td>
                            <?= __('Processed') ?>: <?= (int)$session['processed_rows'] ?><br>
                            <?= __('Success') ?>: <?= (int)$session['success_rows'] ?><br>
                            <?= __('Skipped') ?>: <?= (int)$session['skipped_rows'] ?><br>
                            <?= __('Rolled back') ?>: <?= (int)$session['rollback_rows'] ?>
                        </td>
                        <td><?= htmlspecialchars($session['created_at']) ?></td>
                        <td>
                            <div class="btn-group-vertical">
                                <?php if (!empty($session['temp_file']) && $session['status'] === 'prepared'): ?>
                                <a class="btn btn-sm btn-outline-primary mb-1" href="<?= safeImportUrl(['preview' => (int)$session['id']]) ?>"><?= __('Open Preview') ?></a>
                                <?php endif; ?>
                                <?php if ($can_write && !in_array($session['status'], ['rolled_back', 'canceled'], true) && ((int)$session['success_rows'] > 0 || (int)$session['processed_rows'] > 0)): ?>
                                <form action="<?= safeImportUrl([], ['preview', 'cancel']) ?>" method="post" target="blindSubmit" onsubmit="return confirm('<?= __('Rollback this import batch?') ?>')">
                                    <input type="hidden" name="session_id" value="<?= (int)$session['id'] ?>">
                                    <button type="submit" name="rollbackImport" class="btn btn-sm btn-danger"><?= __('Rollback') ?></button>
                                </form>
                                <?php endif; ?>
                            </div>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
                <small class="text-muted"><?= __('Rollback data is stored in the database, so admins can log back in later and undo problematic imports from this history table.') ?></small>
            </div>
        </div>
    </div>

    <div class="col-lg-5">
        <div class="card mb-3">
            <div class="card-header"><?= __('Default Settings') ?></div>
            <div class="card-body">
                <form action="<?= safeImportUrl([], ['preview', 'cancel']) ?>" method="post">
                    <div class="form-group">
                        <label><?= __('Preview rows per page') ?></label>
                        <input type="number" min="5" max="100" name="preview_per_page" class="form-control" value="<?= (int)$settings['preview_per_page'] ?>">
                    </div>
                    <div class="form-group">
                        <label><?= __('History rows to show') ?></label>
                        <input type="number" min="5" max="100" name="history_limit" class="form-control" value="<?= (int)$settings['history_limit'] ?>">
                    </div>
                    <div class="form-group form-check">
                        <input type="checkbox" name="stop_on_error" value="1" class="form-check-input" id="safe-import-stop" <?= !empty($settings['stop_on_error']) ? 'checked' : '' ?>>
                        <label class="form-check-label" for="safe-import-stop"><?= __('Stop import immediately on the first row error') ?></label>
                    </div>
                    <div class="form-group form-check">
                        <input type="checkbox" name="auto_delete_temp" value="1" class="form-check-input" id="safe-import-cleanup" <?= !empty($settings['auto_delete_temp']) ? 'checked' : '' ?>>
                        <label class="form-check-label" for="safe-import-cleanup"><?= __('Delete uploaded CSV after import finishes') ?></label>
                    </div>
                    <button type="submit" name="saveSettings" class="btn btn-primary"><?= __('Save Settings') ?></button>
                </form>
            </div>
        </div>

        <div class="card mb-3">
            <div class="card-header"><?= __('How it helps users') ?></div>
            <div class="card-body">
                <ul class="mb-0">
                    <li><?= __('Preview data before running the import.') ?></li>
                    <li><?= __('Use clear batch names and notes so rollback is easy to find.') ?></li>
                    <li><?= __('Keep recent import history with status, counts, and error details.') ?></li>
                    <li><?= __('Rollback remains available after logout/login because the import log is stored in database tables, not only in PHP session state.') ?></li>
                </ul>
            </div>
        </div>
    </div>
</div>

<script>
$(document).ready(function(){
    $('.perpage').change(function(){
        let number = $(this).val();
        $('#mainContent').simbioAJAX(`<?= safeImportUrl(['preview' => (int)($previewSession['id'] ?? 0)], ['cancel']) ?>&perpage=${number}`);
    });
});
</script>
