<?php
/**
 * @author Drajat Hasan
 * @email drajathasan20@gmail.com
 * @create date 2023-04-02 09:06:39
 * @modify date 2023-04-02 15:17:17
 * @license GPLv3
 * @desc [description]
 */

use SLiMS\Config;

// key to authenticate
if (!defined('INDEX_AUTH')) {
    define('INDEX_AUTH', '1');
}

// key to get full database access
define('DB_ACCESS', 'fa');

if (!defined('SB')) {
// main system configuration
require '../../../sysconfig.inc.php';
// start the session
require SB . 'admin/default/session.inc.php';
}
// IP based access limitation
require LIB . 'ip_based_access.inc.php';
do_checkIP('smc');
do_checkIP('smc-system');

require SB . 'admin/default/session_check.inc.php';
require SIMBIO . 'simbio_FILE/simbio_directory.inc.php';
require SIMBIO . 'simbio_GUI/table/simbio_table.inc.php';
require SIMBIO . 'simbio_GUI/form_maker/simbio_form_table_AJAX.inc.php';
require SIMBIO . 'simbio_DB/simbio_dbop.inc.php';

$googleDriveHelper = SB . 'plugins/google_drive_backup/GoogleDriveBackup.php';
if (file_exists($googleDriveHelper)) {
    require_once $googleDriveHelper;
}

if (isset($_POST['updateData'])) {
    if (isset($_POST['database_backup'])) {
        foreach($_POST['database_backup'] as $option => $value) {
            if (is_array($value)) {
                foreach ($value as $suboption => $subvalue) {
                    $_POST['database_backup'][$option][$suboption] = in_array($subvalue, [0,1]) ? (bool)$subvalue : $subvalue;
                }
            } else {
                $_POST['database_backup'][$option] = in_array($value, [0,1]) ? (bool)$value : $value;
            }
        }
    }

    $saveStatus = true;
    if (isset($_POST['database_backup'])) {
        $saveStatus = Config::createOrUpdate('database_backup', $_POST['database_backup']);
    }

    if (class_exists('\SLiMS\Plugins\GoogleDriveBackup\GoogleDriveBackup') && isset($_POST['google_drive_backup'])) {
        $saveStatus = \SLiMS\Plugins\GoogleDriveBackup\GoogleDriveBackup::saveSettings($_POST['google_drive_backup']) && $saveStatus;
    }

    if ($saveStatus) {
        toastr(__('Data has been saved'))->success();
    } else {
        toastr(__('Failed to save data'))->error();
    }
    exit;
}

$googleDriveStatus = class_exists('\SLiMS\Plugins\GoogleDriveBackup\GoogleDriveBackup')
    ? \SLiMS\Plugins\GoogleDriveBackup\GoogleDriveBackup::status()
    : null;
$googleDriveSettings = class_exists('\SLiMS\Plugins\GoogleDriveBackup\GoogleDriveBackup')
    ? \SLiMS\Plugins\GoogleDriveBackup\GoogleDriveBackup::settings()
    : [];
$googleDriveUrl = class_exists('\SLiMS\Plugins\GoogleDriveBackup\GoogleDriveBackup')
    ? \SLiMS\Plugins\GoogleDriveBackup\GoogleDriveBackup::pageUrl()
    : SWB . 'plugins/google_drive_backup/index.php';

ob_start();
// create new instance
$form = new simbio_form_table_AJAX('mainForm', $_SERVER['PHP_SELF'], 'post');
$form->submit_button_attr = 'name="updateData" value="' . __('Save Settings') . '" class="btn btn-default"';
// form table attributes
$form->table_attr = 'id="dataList" class="s-table table"';
$form->table_header_attr = 'class="alterCell font-weight-bold"';
$form->table_content_attr = 'class="alterCell2"';

$selectData = [
    [1, __('Enable')],
    [0, __('Disable')]
];
foreach (config('database_backup') as $option => $value) {
    if (is_array($value)) {
        $advopt = '<button type="button" id="showadv" class="btn btn-outline-primary">' . __('Show') . '</button>';
        $advopt .= '<div id="advopt" style="display : none">';
        foreach ($value as $option_in_value => $subvalue) {
            $advopt .= '<div>';
            $advopt .= '<label>' . ucwords(str_replace('-', ' ', $option_in_value)) . '</label>';

            $attributeName = 'database_backup['.$option.'][' . $option_in_value . ']';
            if (is_bool($subvalue)) {
                $advopt .= simbio_form_element::selectList($attributeName, $selectData, (int)$subvalue, 'class="form-control"');
            } else {
                $advopt .= simbio_form_element::textField('text', $attributeName, $subvalue, 'class="form-control"');
            }
        }
        $advopt .= '</div>';
        $form->addAnything('Advance Options', $advopt);
        continue;
    }

    if (is_bool($value)) {
        $form->addSelectList('database_backup['.$option.']', ucwords(str_replace('-', ' ', $option)), $selectData, (int)$value, 'class="form-control"');  
    }

    if (is_string($value)) {
        $form->addTextField('text', 'database_backup['.$option.']', ucwords(str_replace('-', ' ', $option)), $value, 'class="form-control"');
    }
}
// print out the form object
echo $form->printOut();

if ($googleDriveStatus !== null) {
    $googleDriveForm = new simbio_form_table_AJAX('googleDriveForm', $_SERVER['PHP_SELF'], 'post');
    $googleDriveForm->submit_button_attr = 'name="updateData" value="' . __('Save Settings') . '" class="btn btn-default"';
    $googleDriveForm->table_attr = 'id="googleDriveSettings" class="s-table table mt-4"';
    $googleDriveForm->table_header_attr = 'class="alterCell font-weight-bold"';
    $googleDriveForm->table_content_attr = 'class="alterCell2"';
    $googleDriveForm->addAnything(__('Google Drive Backup'), '<div class="alert alert-info mb-0">' . __('This feature is designed for admin browser sessions. Backups stay local first, then can be uploaded to Google Drive automatically or manually.') . '</div>');
    $googleDriveForm->addSelectList('google_drive_backup[enabled]', __('Enable Google Drive Integration'), $selectData, (int) $googleDriveSettings['enabled'], 'class="form-control"');
    $googleDriveForm->addSelectList('google_drive_backup[auto_upload]', __('Upload Automatically After Backup'), $selectData, (int) $googleDriveSettings['auto_upload'], 'class="form-control"');
    $googleDriveForm->addTextField('text', 'google_drive_backup[client_id]', __('Google Client ID'), $googleDriveSettings['client_id'], 'class="form-control"');
    $googleDriveForm->addTextField('password', 'google_drive_backup[client_secret]', __('Google Client Secret'), '', 'class="form-control" autocomplete="new-password" placeholder="' . __('Leave empty to keep current secret') . '"');
    $googleDriveForm->addTextField('text', 'google_drive_backup[folder_id]', __('Google Drive Folder ID'), $googleDriveSettings['folder_id'], 'class="form-control"');
    $googleDriveForm->addTextField('text', 'google_drive_backup[filename_prefix]', __('Uploaded Filename Prefix'), $googleDriveSettings['filename_prefix'], 'class="form-control"');
    $googleDriveForm->addTextField('text', 'google_drive_backup[redirect_uri]', __('Public Redirect URI (optional)'), $googleDriveSettings['redirect_uri'], 'class="form-control" placeholder="' . __('Leave empty to use the generated plugin callback URL') . '"');
    $statusText = $googleDriveStatus['connected']
        ? __('Connected') . ' : ' . ($googleDriveStatus['connected_email'] ?: __('Google account'))
        : __('Not connected');
    $buttons = '<div><strong>' . $statusText . '</strong></div>';
    $buttons .= '<div class="mt-2">';
    $buttons .= '<a class="btn btn-secondary mr-1" target="_blank" rel="noopener" href="' . $googleDriveUrl . '">' . __('Open Google Drive Backup Page') . '</a>';
    if ($googleDriveStatus['configured']) {
        $buttons .= '<a class="btn btn-primary mr-1" target="_blank" rel="noopener" href="' . $googleDriveUrl . '?action=connect">' . __('Connect / Reconnect') . '</a>';
    }
    if ($googleDriveStatus['connected']) {
        $buttons .= '<a class="btn btn-danger" target="_blank" rel="noopener" href="' . $googleDriveUrl . '?action=disconnect">' . __('Disconnect') . '</a>';
    }
    $buttons .= '<div class="mt-2"><label class="font-weight-bold d-block">' . __('Generated Redirect URI') . '</label><input type="text" readonly class="form-control" value="' . simbio_security::xssFree($googleDriveStatus['redirect_uri']) . '"></div>';
    $googleDriveForm->addAnything(__('Connection'), $buttons);
    echo $googleDriveForm->printOut();
}

$confirm = __('Are you sure you want to make it automatically on first login?. It will take longer to complete if your SLiMS has a large collection.');
$autoUploadConfirm = __('Automatic upload needs an active Google Drive connection and runs only when an admin starts backup from the browser.');
echo <<<HTML
<script>
    $('#showadv').click(function() {
        $('#advopt').slideDown()
        $(this).addClass('d-none')
    })
    $('select[name="database_backup[auto]"]').change(function(){
        if ($(this).val() == 1 && !confirm('{$confirm}')) {
            $(this).val(0)
            return
        }
    })
    $('select[name="google_drive_backup[auto_upload]"]').change(function(){
        if ($(this).val() == 1 && !confirm('{$autoUploadConfirm}')) {
            $(this).val(0)
            return
        }
    })
</script>
HTML;
$content = ob_get_clean();
require SB . '/admin/' . $sysconf['admin_template']['dir'] . '/notemplate_page_tpl.php';