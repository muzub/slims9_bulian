<?php

if (!function_exists('digital_drm_reader_init')) {
    function digital_drm_reader_init()
    {
        static $initialized = false;
        if ($initialized) {
            return;
        }
        $initialized = true;

        $dbs = digital_drm_reader_db();
        if (!$dbs) {
            return;
        }

        $dbs->query("CREATE TABLE IF NOT EXISTS digital_drm_profile (
            profile_id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            profile_name VARCHAR(100) NOT NULL,
            print_limit INT UNSIGNED NOT NULL DEFAULT 0,
            allow_download TINYINT(1) NOT NULL DEFAULT 0,
            access_duration_days INT UNSIGNED NOT NULL DEFAULT 1,
            max_parallel_sessions INT UNSIGNED NOT NULL DEFAULT 1,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        $dbs->query("CREATE TABLE IF NOT EXISTS digital_drm_map (
            map_id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            biblio_id INT UNSIGNED NOT NULL,
            attachment_id INT UNSIGNED NOT NULL,
            file_id INT UNSIGNED NOT NULL,
            profile_id INT UNSIGNED NOT NULL,
            view_mode ENUM('pdf_reader','image_viewer','audio_player','video_player','other') NOT NULL DEFAULT 'other',
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY drm_unique_attachment (attachment_id),
            KEY drm_biblio_file (biblio_id, file_id),
            KEY drm_profile_id (profile_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        $dbs->query("CREATE TABLE IF NOT EXISTS digital_drm_session (
            session_id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            member_id INT UNSIGNED NOT NULL,
            biblio_id INT UNSIGNED NOT NULL,
            attachment_id INT UNSIGNED NOT NULL,
            loan_id INT UNSIGNED NULL,
            token VARCHAR(128) NOT NULL,
            expires_at DATETIME NOT NULL,
            client_fingerprint VARCHAR(128) NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY drm_unique_token (token),
            KEY drm_member_attachment (member_id, attachment_id),
            KEY drm_expires (expires_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        $dbs->query("CREATE TABLE IF NOT EXISTS digital_drm_setting (
            setting_key VARCHAR(80) PRIMARY KEY,
            setting_value VARCHAR(255) NOT NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        $dbs->query("INSERT IGNORE INTO digital_drm_setting (setting_key, setting_value) VALUES ('require_active_loan', '0')");
    }

    function digital_drm_reader_db()
    {
        global $dbs;
        return (isset($dbs) && is_object($dbs)) ? $dbs : null;
    }

    function digital_drm_reader_escape($value)
    {
        $dbs = digital_drm_reader_db();
        return $dbs ? $dbs->escape_string((string)$value) : '';
    }

    function digital_drm_reader_get_setting($key, $default = null)
    {
        digital_drm_reader_init();
        $dbs = digital_drm_reader_db();
        if (!$dbs) {
            return $default;
        }

        $key = digital_drm_reader_escape($key);
        $res = $dbs->query("SELECT setting_value FROM digital_drm_setting WHERE setting_key='{$key}' LIMIT 1");
        if (!$res || $res->num_rows < 1) {
            return $default;
        }

        $row = $res->fetch_assoc();
        return $row['setting_value'];
    }

    function digital_drm_reader_set_setting($key, $value)
    {
        digital_drm_reader_init();
        $dbs = digital_drm_reader_db();
        if (!$dbs) {
            return false;
        }

        $key = digital_drm_reader_escape($key);
        $value = digital_drm_reader_escape($value);
        return (bool) $dbs->query("REPLACE INTO digital_drm_setting (setting_key, setting_value) VALUES ('{$key}','{$value}')");
    }

    function digital_drm_reader_get_map_by_attachment($attachmentId)
    {
        static $cache = [];
        $attachmentId = (int) $attachmentId;
        if ($attachmentId < 1) {
            return null;
        }

        if (array_key_exists($attachmentId, $cache)) {
            return $cache[$attachmentId];
        }

        digital_drm_reader_init();
        $dbs = digital_drm_reader_db();
        if (!$dbs) {
            return null;
        }

        $query = "SELECT m.*, p.profile_name, p.print_limit, p.allow_download, p.access_duration_days, p.max_parallel_sessions
            FROM digital_drm_map AS m
            INNER JOIN digital_drm_profile AS p ON p.profile_id = m.profile_id
            WHERE m.file_id={$attachmentId} OR m.attachment_id={$attachmentId}
            LIMIT 1";
        $res = $dbs->query($query);
        $cache[$attachmentId] = ($res && $res->num_rows > 0) ? $res->fetch_assoc() : null;
        return $cache[$attachmentId];
    }

    function digital_drm_reader_get_map_by_biblio_file($biblioId, $fileId)
    {
        digital_drm_reader_init();
        $dbs = digital_drm_reader_db();
        if (!$dbs) {
            return null;
        }

        $biblioId = (int) $biblioId;
        $fileId = (int) $fileId;
        if ($biblioId < 1 || $fileId < 1) {
            return null;
        }

        $query = "SELECT m.*, p.profile_name, p.print_limit, p.allow_download, p.access_duration_days, p.max_parallel_sessions
            FROM digital_drm_map AS m
            INNER JOIN digital_drm_profile AS p ON p.profile_id = m.profile_id
            WHERE m.biblio_id={$biblioId} AND m.file_id={$fileId}
            LIMIT 1";
        $res = $dbs->query($query);
        return ($res && $res->num_rows > 0) ? $res->fetch_assoc() : null;
    }

    function digital_drm_reader_secure_link(array $attachment, $defaultUrl)
    {
        if (empty($attachment['biblio_id']) || empty($attachment['file_id'])) {
            return $defaultUrl;
        }

        $attachmentId = !empty($attachment['att_id']) ? (int)$attachment['att_id'] : (int)$attachment['file_id'];
        $map = digital_drm_reader_get_map_by_attachment($attachmentId);
        if (!$map) {
            return $defaultUrl;
        }

        return SWB . 'plugins/digital_drm_reader/open.php?bid=' . (int)$attachment['biblio_id']
            . '&aid=' . $attachmentId
            . '&fid=' . (int)$attachment['file_id'];
    }

    function digital_drm_reader_direct_access_allowed($biblioId, $fileId)
    {
        $map = digital_drm_reader_get_map_by_biblio_file((int)$biblioId, (int)$fileId);
        if (!$map) {
            return true;
        }
        return ((int)$map['allow_download'] === 1);
    }

    function digital_drm_reader_fingerprint()
    {
        $ua = $_SERVER['HTTP_USER_AGENT'] ?? 'unknown';
        $ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
        return hash('sha256', $ua . '|' . $ip);
    }

    function digital_drm_reader_find_active_loan($memberId, $biblioId)
    {
        $dbs = digital_drm_reader_db();
        if (!$dbs) {
            return null;
        }

        $memberId = (int)$memberId;
        $biblioId = (int)$biblioId;
        $query = "SELECT l.loan_id
            FROM loan AS l
            INNER JOIN item AS i ON i.item_code = l.item_code
            WHERE l.member_id={$memberId} AND i.biblio_id={$biblioId} AND l.is_return=0
            LIMIT 1";
        $res = $dbs->query($query);
        if (!$res || $res->num_rows < 1) {
            return null;
        }

        $row = $res->fetch_assoc();
        return (int)$row['loan_id'];
    }

    function digital_drm_reader_create_session($memberId, array $map)
    {
        digital_drm_reader_init();
        $dbs = digital_drm_reader_db();
        if (!$dbs) {
            return [false, 'Database unavailable'];
        }

        $memberId = (int)$memberId;
        $attachmentId = (int)$map['attachment_id'];
        $biblioId = (int)$map['biblio_id'];

        $parallelLimit = max(1, (int)$map['max_parallel_sessions']);
        $activeRes = $dbs->query("SELECT COUNT(*) AS total FROM digital_drm_session
            WHERE member_id={$memberId} AND attachment_id={$attachmentId} AND expires_at >= NOW()");
        $activeTotal = 0;
        if ($activeRes && $activeRes->num_rows > 0) {
            $activeTotal = (int)$activeRes->fetch_assoc()['total'];
        }
        if ($activeTotal >= $parallelLimit) {
            return [false, __('Maximum parallel session reached')];
        }

        $requireLoan = ((int)digital_drm_reader_get_setting('require_active_loan', '0') === 1);
        $loanId = null;
        if ($requireLoan) {
            $loanId = digital_drm_reader_find_active_loan($memberId, $biblioId);
            if (!$loanId) {
                return [false, __('Active loan is required for this digital content')];
            }
        }

        $durationDays = max(1, (int)$map['access_duration_days']);
        try {
            $token = bin2hex(random_bytes(32));
        } catch (\Exception $e) {
            return [false, __('Unable to generate secure token')];
        }
        $fingerprint = digital_drm_reader_fingerprint();

        $tokenEsc = digital_drm_reader_escape($token);
        $fingerEsc = digital_drm_reader_escape($fingerprint);
        $loanSQL = $loanId ? (int)$loanId : 'NULL';

        $insert = $dbs->query("INSERT INTO digital_drm_session
            (member_id, biblio_id, attachment_id, loan_id, token, expires_at, client_fingerprint)
            VALUES
            ({$memberId}, {$biblioId}, {$attachmentId}, {$loanSQL}, '{$tokenEsc}', DATE_ADD(NOW(), INTERVAL {$durationDays} DAY), '{$fingerEsc}')");

        if (!$insert) {
            return [false, __('Unable to create digital session')];
        }

        return [true, $token];
    }

    function digital_drm_reader_validate_session($token)
    {
        digital_drm_reader_init();
        $dbs = digital_drm_reader_db();
        if (!$dbs) {
            return null;
        }

        $token = trim((string)$token);
        if ($token === '') {
            return null;
        }

        $tokenEsc = digital_drm_reader_escape($token);
        $query = "SELECT s.*, m.file_id, m.view_mode, p.allow_download, p.print_limit, p.profile_name
            FROM digital_drm_session AS s
            INNER JOIN digital_drm_map AS m ON m.attachment_id = s.attachment_id
            INNER JOIN digital_drm_profile AS p ON p.profile_id = m.profile_id
            WHERE s.token='{$tokenEsc}' AND s.expires_at >= NOW()
            LIMIT 1";
        $res = $dbs->query($query);
        if (!$res || $res->num_rows < 1) {
            return null;
        }

        $session = $res->fetch_assoc();
        if (!empty($session['client_fingerprint']) && $session['client_fingerprint'] !== digital_drm_reader_fingerprint()) {
            return null;
        }

        return $session;
    }

    function digital_drm_reader_get_attachment($biblioId, $attachmentId, $fileId)
    {
        $dbs = digital_drm_reader_db();
        if (!$dbs) {
            return null;
        }

        $biblioId = (int)$biblioId;
        $attachmentId = (int)$attachmentId;
        $fileId = (int)$fileId;
        if ($biblioId < 1 || $attachmentId < 1 || $fileId < 1) {
            return null;
        }

        $query = "SELECT
                att.biblio_id,
                att.file_id,
                att.placement,
                att.access_type,
                att.access_limit,
                f.file_dir,
                f.file_name,
                f.file_title,
                f.file_desc,
                f.file_url,
                f.mime_type
            FROM biblio_attachment AS att
            INNER JOIN files AS f ON f.file_id = att.file_id
            WHERE att.biblio_id={$biblioId} AND att.file_id={$fileId}
            LIMIT 1";
        $res = $dbs->query($query);
        if (!$res || $res->num_rows < 1) {
            return null;
        }

        return $res->fetch_assoc();
    }

    function digital_drm_reader_get_attachment_by_session(array $session)
    {
        $dbs = digital_drm_reader_db();
        if (!$dbs) {
            return null;
        }

        $biblioId = (int)$session['biblio_id'];
        $fileId = (int)$session['file_id'];

        $query = "SELECT
                att.biblio_id,
                att.file_id,
                att.placement,
                att.access_type,
                att.access_limit,
                f.file_dir,
                f.file_name,
                f.file_title,
                f.file_desc,
                f.file_url,
                f.mime_type
            FROM biblio_attachment AS att
            INNER JOIN files AS f ON f.file_id = att.file_id
            WHERE att.biblio_id={$biblioId} AND att.file_id={$fileId}
            LIMIT 1";
        $res = $dbs->query($query);
        if (!$res || $res->num_rows < 1) {
            return null;
        }

        return $res->fetch_assoc();
    }

    function digital_drm_reader_file_path(array $attachment)
    {
        $repositoryRoot = realpath(REPOBS);
        if (!$repositoryRoot) {
            return null;
        }

        if (!empty($attachment['file_dir'])) {
            $candidate = REPOBS . '/' . trim($attachment['file_dir'], '/') . '/' . $attachment['file_name'];
        } else {
            $candidate = REPOBS . '/' . $attachment['file_name'];
        }

        $resolved = realpath($candidate);
        if (!$resolved) {
            return null;
        }

        if (strpos($resolved, $repositoryRoot) !== 0) {
            return null;
        }

        return $resolved;
    }

    function digital_drm_reader_send_nocache_headers()
    {
        header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
        header('Pragma: no-cache');
        header('Expires: 0');
    }
}
