<?php
/**
 * Upload a video to your TikTok inbox via Content Posting API (PULL_FROM_URL).
 *
 *   POST https://open.tiktokapis.com/v2/post/publish/inbox/video/init/
 *
 * Credentials: cron/tiktok_credentials.inc.php (client key, secret, video URL)
 * Tokens: cron/.tiktok_tokens.json (auto-refreshed; authorize once via tiktok_oauth_start.php)
 *
 * Usage: php cron/upload_to_tiktok.php
 */

declare(strict_types=1);

require_once dirname(__DIR__) . '/helpers/tiktok_oauth.php';

const TIKTOK_INBOX_VIDEO_INIT_URL = 'https://open.tiktokapis.com/v2/post/publish/inbox/video/init/';

function script_out(string $msg): void
{
    echo $msg . PHP_EOL;
    if (ob_get_level() > 0) {
        @ob_flush();
    }
    @flush();
}

/**
 * @return array{ok: bool, http_code: int, body: array<string, mixed>|null, raw: string}
 */
function tiktok_inbox_video_init(string $accessToken, string $videoUrl): array
{
    $payload = [
        'source_info' => [
            'source' => 'PULL_FROM_URL',
            'video_url' => $videoUrl,
        ],
    ];

    $ch = curl_init(TIKTOK_INBOX_VIDEO_INIT_URL);
    if ($ch === false) {
        return ['ok' => false, 'http_code' => 0, 'body' => null, 'raw' => 'curl_init failed'];
    }

    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => [
            'Authorization: Bearer ' . $accessToken,
            'Content-Type: application/json; charset=UTF-8',
        ],
        CURLOPT_POSTFIELDS => json_encode($payload, JSON_THROW_ON_ERROR),
        CURLOPT_TIMEOUT => 120,
    ]);

    $raw = curl_exec($ch);
    $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);

    if ($raw === false) {
        return ['ok' => false, 'http_code' => $httpCode, 'body' => null, 'raw' => $curlError !== '' ? $curlError : 'curl_exec failed'];
    }

    $decoded = json_decode($raw, true);

    return [
        'ok' => true,
        'http_code' => $httpCode,
        'body' => is_array($decoded) ? $decoded : null,
        'raw' => $raw,
    ];
}

$videoUrl = trim(TIKTOK_VIDEO_URL);

try {
    $accessToken = tiktok_ensure_access_token();
} catch (RuntimeException $e) {
    script_out($e->getMessage());
    exit(1);
}

if ($videoUrl === '' || !filter_var($videoUrl, FILTER_VALIDATE_URL)) {
    script_out('Set a valid HTTPS TIKTOK_VIDEO_URL at the top of this file.');
    exit(1);
}

if (!preg_match('#^https://#i', $videoUrl)) {
    script_out('video_url must be HTTPS.');
    exit(1);
}

script_out('Initializing TikTok inbox upload (PULL_FROM_URL)...');
script_out('Video: ' . $videoUrl);

$result = tiktok_inbox_video_init($accessToken, $videoUrl);
$body = $result['body'];
$httpCode = $result['http_code'];

if ($body === null) {
    script_out('Invalid JSON response (HTTP ' . $httpCode . '):');
    script_out($result['raw']);
    exit(1);
}

$error = is_array($body['error'] ?? null) ? $body['error'] : [];
$errorCode = (string) ($error['code'] ?? '');
$errorMessage = (string) ($error['message'] ?? '');
$logId = (string) ($error['log_id'] ?? '');

if (($httpCode < 200 || $httpCode >= 300 || $errorCode !== 'ok') && $errorCode === 'access_token_invalid') {
    try {
        $tokens = tiktok_tokens_load();
        if (is_array($tokens)) {
            $tokens['expires_at'] = 0;
            tiktok_tokens_save($tokens);
        }
        $accessToken = tiktok_ensure_access_token();
        script_out('Access token refreshed, retrying upload...');
        $result = tiktok_inbox_video_init($accessToken, $videoUrl);
        $body = $result['body'];
        $httpCode = $result['http_code'];
        if ($body === null) {
            script_out('Invalid JSON on retry (HTTP ' . $httpCode . '):');
            script_out($result['raw']);
            exit(1);
        }
        $error = is_array($body['error'] ?? null) ? $body['error'] : [];
        $errorCode = (string) ($error['code'] ?? '');
        $errorMessage = (string) ($error['message'] ?? '');
        $logId = (string) ($error['log_id'] ?? '');
    } catch (RuntimeException $e) {
        script_out($e->getMessage());
        exit(1);
    }
}

if ($httpCode < 200 || $httpCode >= 300 || $errorCode !== 'ok') {
    script_out('TikTok API error (HTTP ' . $httpCode . '):');
    script_out('  code: ' . ($errorCode !== '' ? $errorCode : '(none)'));
    if ($errorMessage !== '') {
        script_out('  message: ' . $errorMessage);
    }
    if ($logId !== '') {
        script_out('  log_id: ' . $logId);
    }
    script_out('Raw response:');
    script_out($result['raw']);
    exit(1);
}

$data = is_array($body['data'] ?? null) ? $body['data'] : [];
$publishId = (string) ($data['publish_id'] ?? '');

script_out('Success.');
script_out('video_url: ' . $videoUrl);
if ($publishId !== '') {
    script_out('publish_id: ' . $publishId);
}
script_out('Open the TikTok app → Inbox to finish editing and publish the video.');

exit(0);
