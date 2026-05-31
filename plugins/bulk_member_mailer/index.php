<?php

use SLiMS\{DB, Mail};

defined('INDEX_AUTH') OR die('Direct access not allowed!');

require LIB . 'ip_based_access.inc.php';
do_checkIP('smc');
do_checkIP('smc-membership');
require SB . 'admin/default/session.inc.php';

$can_read = utility::havePrivilege('membership', 'r');
$can_write = utility::havePrivilege('membership', 'w');

if (!$can_read) {
    die('<div class="errorBox">' . __('You are not authorized to view this section') . '</div>');
}

$settingName = 'bulk_member_mailer_settings';
$defaultSettings = [
    'batch_size' => 25,
    'batch_delay' => 1,
    'subject' => __('Library notice for {member_name}'),
    'message' => __('Dear {member_name},')
];

$settingStatement = DB::getInstance()->prepare('SELECT setting_value FROM setting WHERE setting_name = ? LIMIT 1');
$settingStatement->execute([$settingName]);
$storedSetting = $settingStatement->fetchColumn();
$storedSetting = $storedSetting ? @unserialize($storedSetting) : [];
$storedSetting = is_array($storedSetting) ? $storedSetting : [];
$settings = array_merge($defaultSettings, $storedSetting);
$isSaveAction = isset($_POST['save_settings']);
$isSendAction = isset($_POST['send_bulk_mail']);

if (($isSaveAction || $isSendAction) && $can_write) {
    $settings['batch_size'] = max(1, min(200, (int) ($_POST['batch_size'] ?? $settings['batch_size'])));
    $settings['batch_delay'] = max(0, min(20, (int) ($_POST['batch_delay'] ?? $settings['batch_delay'])));
    $settings['subject'] = trim((string) ($_POST['subject'] ?? $settings['subject']));
    $settings['message'] = trim((string) ($_POST['message'] ?? $settings['message']));
    $settings['subject'] = $settings['subject'] ?: $defaultSettings['subject'];
    $settings['message'] = $settings['message'] ?: $defaultSettings['message'];
}

if ($isSaveAction && $can_write) {
    $saveSettingStatement = DB::getInstance()->prepare('REPLACE INTO setting (setting_name, setting_value) VALUES (?, ?)');
    $saveSettingStatement->execute([$settingName, serialize($settings)]);
    echo '<div class="alert alert-success">' . __('Settings inserted.') . '</div>';
}

$summary = ['total' => 0, 'success' => 0, 'failed' => 0];
$failedReport = [];

if ($isSendAction && $can_write) {
    if (is_null(config('mail'))) {
        echo '<div class="alert alert-warning">' . __('E-Mail configuration is not ready!') . '</div>';
    } else {
        $memberStatement = DB::getInstance()->query('SELECT member_id, member_name, member_email FROM member ORDER BY member_id ASC');
        $members = $memberStatement->fetchAll(PDO::FETCH_ASSOC);
        $summary['total'] = count($members);
        $batches = array_chunk($members, (int) $settings['batch_size']);

        foreach ($batches as $batchIndex => $batchMembers) {
            foreach ($batchMembers as $member) {
                $memberEmail = trim((string) $member['member_email']);
                $memberName = (string) $member['member_name'];

                if ($memberEmail === '') {
                    $summary['failed']++;
                    $failedReport[] = [
                        'member_id' => $member['member_id'],
                        'member_name' => $memberName,
                        'member_email' => '-',
                        'reason' => __('No email address')
                    ];
                    continue;
                }

                if (!filter_var($memberEmail, FILTER_VALIDATE_EMAIL)) {
                    $summary['failed']++;
                    $failedReport[] = [
                        'member_id' => $member['member_id'],
                        'member_name' => $memberName,
                        'member_email' => $memberEmail,
                        'reason' => __('Invalid email format')
                    ];
                    continue;
                }

                try {
                    $subject = str_replace(
                        ['{member_id}', '{member_name}', '{member_email}'],
                        [$member['member_id'], $memberName, $memberEmail],
                        $settings['subject']
                    );
                    $message = str_replace(
                        ['{member_id}', '{member_name}', '{member_email}'],
                        [$member['member_id'], $memberName, $memberEmail],
                        $settings['message']
                    );

                    Mail::to($memberEmail, $memberName)
                        ->subject($subject)
                        ->message($message)
                        ->send();

                    $summary['success']++;
                } catch (Exception $exception) {
                    $summary['failed']++;
                    $failedReport[] = [
                        'member_id' => $member['member_id'],
                        'member_name' => $memberName,
                        'member_email' => $memberEmail,
                        'reason' => Mail::getInstance()->ErrorInfo ?: $exception->getMessage()
                    ];
                }
            }

            if ($settings['batch_delay'] > 0 && $batchIndex < count($batches) - 1) {
                sleep((int) $settings['batch_delay']);
            }
        }

        echo '<div class="alert alert-info">' .
            sprintf(
                '%s: %d | %s: %d | %s: %d',
                __('Total member'),
                $summary['total'],
                __('Sent'),
                $summary['success'],
                __('Failed'),
                $summary['failed']
            ) .
            '</div>';
    }
}
?>

<div class="menuBox">
    <div class="menuBoxInner printIcon">
        <div class="per_title">
            <h2><?php echo __('Bulk Member Mailer'); ?></h2>
        </div>
        <div class="infoBox">
            <?php echo __('Send one email campaign to all members with batching to reduce spam risk.'); ?>
        </div>
        <?php if (!$can_write): ?>
            <div class="alert alert-warning"><?php echo __('You don\'t have enough privileges to do this action!'); ?></div>
        <?php endif; ?>
        <form method="post">
            <div class="form-group">
                <label><?php echo __('Batch size'); ?></label>
                <input type="number" min="1" max="200" class="form-control col-md-2" name="batch_size" value="<?php echo (int) $settings['batch_size']; ?>">
                <small class="text-muted"><?php echo __('Number of members processed in one batch.'); ?></small>
            </div>
            <div class="form-group">
                <label><?php echo __('Batch delay (seconds)'); ?></label>
                <input type="number" min="0" max="20" class="form-control col-md-2" name="batch_delay" value="<?php echo (int) $settings['batch_delay']; ?>">
                <small class="text-muted"><?php echo __('Pause between batches.'); ?></small>
            </div>
            <div class="form-group">
                <label><?php echo __('E-mail subject'); ?></label>
                <input type="text" class="form-control col-md-8" name="subject" value="<?php echo htmlspecialchars((string) $settings['subject']); ?>">
            </div>
            <div class="form-group">
                <label><?php echo __('E-mail message'); ?></label>
                <textarea class="form-control col-md-8" rows="8" name="message"><?php echo htmlspecialchars((string) $settings['message']); ?></textarea>
                <small class="text-muted"><?php echo __('Available placeholders: {member_id}, {member_name}, {member_email}'); ?></small>
            </div>
            <button type="submit" class="s-btn btn btn-default" name="save_settings" value="1" <?php echo $can_write ? '' : 'disabled'; ?>><?php echo __('Save'); ?></button>
            <button type="submit" class="s-btn btn btn-primary" name="send_bulk_mail" value="1" onclick="return confirm('<?php echo __('Send email to all members now?'); ?>')" <?php echo $can_write ? '' : 'disabled'; ?>><?php echo __('Send'); ?></button>
        </form>
    </div>
</div>

<?php if (!empty($failedReport)): ?>
    <div class="menuBox">
        <div class="menuBoxInner printIcon">
            <div class="per_title">
                <h2><?php echo __('Failed Delivery Report'); ?></h2>
            </div>
            <table class="table table-striped">
                <thead>
                    <tr>
                        <th><?php echo __('Member ID'); ?></th>
                        <th><?php echo __('Member Name'); ?></th>
                        <th><?php echo __('E-mail'); ?></th>
                        <th><?php echo __('Reason'); ?></th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($failedReport as $failed): ?>
                    <tr>
                        <td><?php echo htmlspecialchars((string) $failed['member_id']); ?></td>
                        <td><?php echo htmlspecialchars((string) $failed['member_name']); ?></td>
                        <td><?php echo htmlspecialchars((string) $failed['member_email']); ?></td>
                        <td><?php echo htmlspecialchars((string) $failed['reason']); ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
<?php endif; ?>
