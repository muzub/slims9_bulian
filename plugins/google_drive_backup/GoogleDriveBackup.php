<?php

namespace SLiMS\Plugins\GoogleDriveBackup;

use GuzzleHttp\Client;
use SLiMS\Config;
use SLiMS\Url;

class GoogleDriveBackup
{
    public const CONFIG_KEY = 'google_drive_backup';
    public const SESSION_STATE_KEY = 'google_drive_backup_state';
    public const DEFAULT_SCOPE = 'https://www.googleapis.com/auth/drive.file https://www.googleapis.com/auth/userinfo.email';

    public static function defaults(): array
    {
        return [
            'enabled' => false,
            'auto_upload' => false,
            'client_id' => '',
            'client_secret' => '',
            'folder_id' => '',
            'filename_prefix' => '',
            'redirect_uri' => '',
            'access_token' => '',
            'refresh_token' => '',
            'token_type' => 'Bearer',
            'expires_at' => 0,
            'scope' => '',
            'connected_email' => '',
            'uploads' => []
        ];
    }

    public static function settings(): array
    {
        $settings = config(self::CONFIG_KEY, []);

        return array_merge(self::defaults(), is_array($settings) ? $settings : []);
    }

    public static function status(): array
    {
        $settings = self::settings();
        $hasCredential = !empty($settings['client_id']) && !empty($settings['client_secret']);
        $hasToken = !empty($settings['refresh_token']) || (!empty($settings['access_token']) && (int) $settings['expires_at'] > time());

        return [
            'enabled' => (bool) $settings['enabled'],
            'auto_upload' => (bool) $settings['auto_upload'],
            'configured' => $hasCredential,
            'connected' => $hasCredential && $hasToken,
            'connected_email' => $settings['connected_email'],
            'redirect_uri' => self::redirectUri(),
            'uploads' => $settings['uploads']
        ];
    }

    public static function saveSettings(array $input): bool
    {
        $current = self::settings();
        $settings = $current;

        $settings['enabled'] = !empty($input['enabled']);
        $settings['auto_upload'] = !empty($input['auto_upload']);
        $settings['client_id'] = trim($input['client_id'] ?? '');
        $settings['folder_id'] = trim($input['folder_id'] ?? '');
        $settings['filename_prefix'] = trim($input['filename_prefix'] ?? '');

        $redirectUri = trim($input['redirect_uri'] ?? '');
        $settings['redirect_uri'] = (empty($redirectUri) || filter_var($redirectUri, FILTER_VALIDATE_URL)) ? $redirectUri : '';

        $clientSecret = trim($input['client_secret'] ?? '');
        $settings['client_secret'] = $clientSecret !== '' ? $clientSecret : $current['client_secret'];

        $credentialsChanged = $current['client_id'] !== $settings['client_id'] || $current['client_secret'] !== $settings['client_secret'];
        if ($credentialsChanged) {
            $settings = array_merge($settings, [
                'access_token' => '',
                'refresh_token' => '',
                'token_type' => 'Bearer',
                'expires_at' => 0,
                'scope' => '',
                'connected_email' => ''
            ]);
        }

        return Config::createOrUpdate(self::CONFIG_KEY, $settings);
    }

    public static function authorizationUrl(): string
    {
        $settings = self::settings();

        if (empty($settings['client_id']) || empty($settings['client_secret'])) {
            throw new \RuntimeException(__('Google Drive client ID and client secret must be filled first.'));
        }

        $_SESSION[self::SESSION_STATE_KEY] = utility::createRandomString(32);

        return 'https://accounts.google.com/o/oauth2/v2/auth?' . http_build_query([
            'client_id' => $settings['client_id'],
            'redirect_uri' => self::redirectUri(),
            'response_type' => 'code',
            'scope' => self::DEFAULT_SCOPE,
            'access_type' => 'offline',
            'prompt' => 'consent',
            'state' => $_SESSION[self::SESSION_STATE_KEY]
        ]);
    }

    public static function handleCallback(array $query): array
    {
        if (!empty($query['error'])) {
            throw new \RuntimeException($query['error']);
        }

        if (empty($query['code']) || empty($query['state'])) {
            throw new \RuntimeException(__('Google Drive authorization response is incomplete.'));
        }

        if (empty($_SESSION[self::SESSION_STATE_KEY]) || !hash_equals($_SESSION[self::SESSION_STATE_KEY], $query['state'])) {
            unset($_SESSION[self::SESSION_STATE_KEY]);
            throw new \RuntimeException(__('Google Drive authorization state is invalid.'));
        }

        unset($_SESSION[self::SESSION_STATE_KEY]);

        $settings = self::settings();
        $response = self::httpClient()->post('https://oauth2.googleapis.com/token', [
            'form_params' => [
                'code' => $query['code'],
                'client_id' => $settings['client_id'],
                'client_secret' => $settings['client_secret'],
                'redirect_uri' => self::redirectUri(),
                'grant_type' => 'authorization_code'
            ]
        ]);

        $token = json_decode((string) $response->getBody(), true) ?: [];
        if (empty($token['access_token'])) {
            throw new \RuntimeException(__('Failed to get Google Drive access token.'));
        }

        self::persistToken($token);

        return [
            'status' => true,
            'message' => __('Google Drive is connected.')
        ];
    }

    public static function disconnect(): bool
    {
        return Config::createOrUpdate(self::CONFIG_KEY, array_merge(self::settings(), [
            'access_token' => '',
            'refresh_token' => '',
            'token_type' => 'Bearer',
            'expires_at' => 0,
            'scope' => '',
            'connected_email' => ''
        ]));
    }

    public static function uploadBackup(string $filePath, int $backupLogId = 0): array
    {
        if (!file_exists($filePath)) {
            throw new \RuntimeException(__('Backup file was not found.'));
        }

        $settings = self::settings();
        if (!$settings['enabled']) {
            throw new \RuntimeException(__('Google Drive backup integration is disabled.'));
        }

        $accessToken = self::accessToken();
        $fileName = trim(($settings['filename_prefix'] ?? '') . basename($filePath));

        $metadata = ['name' => $fileName];
        if (!empty($settings['folder_id'])) {
            $metadata['parents'] = [$settings['folder_id']];
        }

        $response = self::httpClient()->post('https://www.googleapis.com/upload/drive/v3/files?uploadType=multipart&fields=id,name,webViewLink', [
            'headers' => [
                'Authorization' => 'Bearer ' . $accessToken
            ],
            'multipart' => [
                [
                    'name' => 'metadata',
                    'contents' => json_encode($metadata),
                    'headers' => ['Content-Type' => 'application/json; charset=UTF-8']
                ],
                [
                    'name' => 'file',
                    'contents' => fopen($filePath, 'r'),
                    'filename' => basename($filePath),
                    'headers' => ['Content-Type' => function_exists('mime_content_type') ? mime_content_type($filePath) : 'application/octet-stream']
                ]
            ]
        ]);

        $body = json_decode((string) $response->getBody(), true) ?: [];
        if (empty($body['id'])) {
            throw new \RuntimeException(__('Google Drive did not return uploaded file information.'));
        }

        self::storeUpload($backupLogId, [
            'id' => $body['id'],
            'name' => $body['name'] ?? basename($filePath),
            'url' => $body['webViewLink'] ?? self::fileUrl($body['id']),
            'uploaded_at' => date('Y-m-d H:i:s')
        ]);

        return [
            'status' => true,
            'message' => __('Backup uploaded to Google Drive.'),
            'file_id' => $body['id'],
            'url' => $body['webViewLink'] ?? self::fileUrl($body['id'])
        ];
    }

    public static function upload(int $backupLogId): ?array
    {
        $uploads = self::settings()['uploads'] ?? [];

        return $uploads[$backupLogId] ?? null;
    }

    public static function removeUpload(int $backupLogId): bool
    {
        $settings = self::settings();
        $uploads = $settings['uploads'] ?? [];
        unset($uploads[$backupLogId]);

        return Config::createOrUpdate(self::CONFIG_KEY, array_merge($settings, ['uploads' => $uploads]));
    }

    public static function latestUploads(int $limit = 5): array
    {
        $uploads = array_values(self::settings()['uploads'] ?? []);
        usort($uploads, static function ($first, $second) {
            return strcmp($second['uploaded_at'] ?? '', $first['uploaded_at'] ?? '');
        });

        return array_slice($uploads, 0, $limit);
    }

    public static function redirectUri(): string
    {
        $settings = self::settings();
        if (!empty($settings['redirect_uri']) && filter_var($settings['redirect_uri'], FILTER_VALIDATE_URL)) {
            return $settings['redirect_uri'];
        }

        return (string) Url::getSlimsBaseUri('plugins/google_drive_backup/index.php?action=callback');
    }

    public static function pageUrl(array $query = []): string
    {
        $path = 'plugins/google_drive_backup/index.php';
        if (!empty($query)) {
            $path .= '?' . http_build_query($query);
        }

        return (string) Url::getSlimsBaseUri($path);
    }

    public static function fileUrl(string $fileId): string
    {
        return 'https://drive.google.com/file/d/' . rawurlencode($fileId) . '/view';
    }

    private static function accessToken(): string
    {
        $settings = self::settings();

        if (!empty($settings['access_token']) && (int) $settings['expires_at'] > (time() + 60)) {
            return $settings['access_token'];
        }

        if (empty($settings['refresh_token'])) {
            throw new \RuntimeException(__('Google Drive is not connected yet.'));
        }

        $response = self::httpClient()->post('https://oauth2.googleapis.com/token', [
            'form_params' => [
                'client_id' => $settings['client_id'],
                'client_secret' => $settings['client_secret'],
                'refresh_token' => $settings['refresh_token'],
                'grant_type' => 'refresh_token'
            ]
        ]);

        $token = json_decode((string) $response->getBody(), true) ?: [];
        if (empty($token['access_token'])) {
            throw new \RuntimeException(__('Unable to refresh Google Drive access token.'));
        }

        self::persistToken($token);

        return self::settings()['access_token'];
    }

    private static function persistToken(array $token): void
    {
        $settings = self::settings();
        $settings['access_token'] = $token['access_token'];
        $settings['refresh_token'] = $token['refresh_token'] ?? $settings['refresh_token'];
        $settings['token_type'] = $token['token_type'] ?? 'Bearer';
        $settings['expires_at'] = time() + (int) ($token['expires_in'] ?? 3600);
        $settings['scope'] = $token['scope'] ?? self::DEFAULT_SCOPE;
        $settings['connected_email'] = self::fetchEmail($settings['access_token']) ?: $settings['connected_email'];

        Config::createOrUpdate(self::CONFIG_KEY, $settings);
    }

    private static function storeUpload(int $backupLogId, array $upload): void
    {
        if ($backupLogId < 1) {
            return;
        }

        $settings = self::settings();
        $uploads = $settings['uploads'] ?? [];
        $uploads[$backupLogId] = $upload;

        Config::createOrUpdate(self::CONFIG_KEY, array_merge($settings, ['uploads' => $uploads]));
    }

    private static function fetchEmail(string $accessToken): ?string
    {
        try {
            $response = self::httpClient()->get('https://www.googleapis.com/oauth2/v2/userinfo', [
                'headers' => [
                    'Authorization' => 'Bearer ' . $accessToken
                ]
            ]);
            $body = json_decode((string) $response->getBody(), true) ?: [];

            return $body['email'] ?? null;
        } catch (\Throwable $throwable) {
            return null;
        }
    }

    private static function httpClient(): Client
    {
        return new Client(config('http.client') ?? []);
    }
}
