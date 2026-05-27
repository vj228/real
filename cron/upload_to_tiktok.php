<?php
/**
 * Download video and Direct Post to TikTok (auto-publish, no Inbox step).
 *
 * Requires video.publish scope — re-run tiktok_oauth_start.php once after updating.
 * Tokens: cron/.tiktok_tokens.json
 *
 * Usage: php cron/upload_to_tiktok.php
 */

declare(strict_types=1);

require_once dirname(__DIR__) . '/helpers/tiktok_oauth.php';

const TIKTOK_DIRECT_POST_INIT_URL = 'https://open.tiktokapis.com/v2/post/publish/video/init/';
const TIKTOK_CREATOR_INFO_URL = 'https://open.tiktokapis.com/v2/post/publish/creator_info/query/';
const TIKTOK_POST_STATUS_URL = 'https://open.tiktokapis.com/v2/post/publish/status/fetch/';
const TIKTOK_CHUNK_MIN = 5 * 1024 * 1024;
const TIKTOK_CHUNK_DEFAULT = 10 * 1024 * 1024;

function script_out(string $msg): void
{
    echo $msg . PHP_EOL;
    if (ob_get_level() > 0) {
        @ob_flush();
    }
    @flush();
}

/**
 * @return array{chunk_size: int, total_chunk_count: int}
 */
function tiktok_upload_chunk_plan(int $videoSize): array
{
    if ($videoSize < TIKTOK_CHUNK_MIN) {
        return ['chunk_size' => $videoSize, 'total_chunk_count' => 1];
    }

    $chunkSize = TIKTOK_CHUNK_DEFAULT;
    $totalChunks = (int) ceil($videoSize / $chunkSize);

    return ['chunk_size' => $chunkSize, 'total_chunk_count' => max(1, $totalChunks)];
}

function tiktok_download_video(string $videoUrl): string
{
    $tmp = tempnam(sys_get_temp_dir(), 'tiktok_vid_');
    if ($tmp === false) {
        throw new RuntimeException('Could not create temp file.');
    }
    $path = $tmp . '.mp4';
    @unlink($tmp);

    $fp = fopen($path, 'wb');
    if ($fp === false) {
        throw new RuntimeException('Could not open temp file for writing.');
    }

    $ch = curl_init($videoUrl);
    if ($ch === false) {
        fclose($fp);
        @unlink($path);
        throw new RuntimeException('curl_init failed for video download.');
    }

    curl_setopt_array($ch, [
        CURLOPT_FILE => $fp,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_TIMEOUT => 600,
        CURLOPT_SSL_VERIFYPEER => true,
    ]);

    $ok = curl_exec($ch);
    $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);
    fclose($fp);

    if ($ok === false || $httpCode < 200 || $httpCode >= 300) {
        @unlink($path);
        throw new RuntimeException(
            'Video download failed (HTTP ' . $httpCode . '): ' . ($curlError !== '' ? $curlError : $videoUrl)
        );
    }

    $size = filesize($path);
    if ($size === false || $size < 1) {
        @unlink($path);
        throw new RuntimeException('Downloaded video file is empty.');
    }

    return $path;
}

function tiktok_video_mime_type(string $path): string
{
    $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));

    return match ($ext) {
        'webm' => 'video/webm',
        'mov' => 'video/quicktime',
        default => 'video/mp4',
    };
}

/**
 * @param array<string, mixed>|null $body
 * @return array{http_code: int, body: array<string, mixed>|null, raw: string}
 */
function tiktok_api_post(string $accessToken, string $url, ?array $body = null): array
{
    $ch = curl_init($url);
    if ($ch === false) {
        return ['http_code' => 0, 'body' => null, 'raw' => 'curl_init failed'];
    }

    $headers = [
        'Authorization: Bearer ' . $accessToken,
        'Content-Type: application/json; charset=UTF-8',
    ];

    $opts = [
        CURLOPT_POST => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_TIMEOUT => 120,
    ];

    if ($body !== null) {
        $opts[CURLOPT_POSTFIELDS] = json_encode($body, JSON_THROW_ON_ERROR);
    } else {
        $opts[CURLOPT_POSTFIELDS] = '{}';
    }

    curl_setopt_array($ch, $opts);

    $raw = curl_exec($ch);
    $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);

    if ($raw === false) {
        return ['http_code' => $httpCode, 'body' => null, 'raw' => $curlError !== '' ? $curlError : 'curl_exec failed'];
    }

    $decoded = json_decode($raw, true);

    return [
        'http_code' => $httpCode,
        'body' => is_array($decoded) ? $decoded : null,
        'raw' => $raw,
    ];
}

/**
 * @return array{ok: bool, http_code: int, error_code: string, error_message: string, publish_id: string, raw: string, upload_url: string}
 */
function tiktok_parse_api_response(array $result): array
{
    $body = $result['body'];
    $httpCode = $result['http_code'];
    if ($body === null) {
        return [
            'ok' => false,
            'http_code' => $httpCode,
            'error_code' => 'invalid_json',
            'error_message' => '',
            'publish_id' => '',
            'raw' => $result['raw'],
            'upload_url' => '',
        ];
    }

    $error = is_array($body['error'] ?? null) ? $body['error'] : [];
    $errorCode = (string) ($error['code'] ?? '');
    $data = is_array($body['data'] ?? null) ? $body['data'] : [];

    return [
        'ok' => $httpCode >= 200 && $httpCode < 300 && $errorCode === 'ok',
        'http_code' => $httpCode,
        'error_code' => $errorCode,
        'error_message' => (string) ($error['message'] ?? ''),
        'publish_id' => (string) ($data['publish_id'] ?? ''),
        'raw' => $result['raw'],
        'upload_url' => (string) ($data['upload_url'] ?? ''),
    ];
}

/**
 * @return array<string, mixed>
 */
function tiktok_query_creator_info(string $accessToken): array
{
    $result = tiktok_api_post($accessToken, TIKTOK_CREATOR_INFO_URL);
    $parsed = tiktok_parse_api_response($result);
    if (!$parsed['ok']) {
        $msg = $parsed['error_code'] === 'scope_not_authorized'
            ? 'Missing video.publish scope. Re-authorize: https://yhome.pro/tiktok_oauth_start.php'
            : ($parsed['error_message'] !== '' ? $parsed['error_message'] : $parsed['error_code']);
        throw new RuntimeException('Creator info failed: ' . $msg);
    }

    $data = $result['body']['data'] ?? null;

    return is_array($data) ? $data : [];
}

/**
 * @param list<string> $options
 */
function tiktok_pick_privacy_level(array $options): string
{
    $preferred = trim(TIKTOK_PRIVACY_LEVEL);
    if ($preferred !== '' && in_array($preferred, $options, true)) {
        return $preferred;
    }

    foreach (['PUBLIC_TO_EVERYONE', 'MUTUAL_FOLLOW_FRIENDS', 'FOLLOWER_OF_CREATOR', 'SELF_ONLY'] as $level) {
        if (in_array($level, $options, true)) {
            return $level;
        }
    }

    if ($options === []) {
        throw new RuntimeException('No privacy_level_options returned from TikTok.');
    }

    return (string) $options[0];
}

/**
 * @param array<string, mixed> $creator
 * @param array<string, mixed> $sourceInfo
 * @return array{http_code: int, body: array<string, mixed>|null, raw: string}
 */
function tiktok_direct_post_init(string $accessToken, array $creator, array $sourceInfo): array
{
    $privacyOptions = $creator['privacy_level_options'] ?? [];
    if (!is_array($privacyOptions)) {
        $privacyOptions = [];
    }

    $privacyLevel = tiktok_pick_privacy_level($privacyOptions);

    $postInfo = [
        'title' => TIKTOK_POST_TITLE,
        'privacy_level' => $privacyLevel,
        'disable_duet' => (bool) ($creator['duet_disabled'] ?? false),
        'disable_stitch' => (bool) ($creator['stitch_disabled'] ?? false),
        'disable_comment' => (bool) ($creator['comment_disabled'] ?? false),
        'brand_content_toggle' => TIKTOK_BRAND_CONTENT_TOGGLE,
        'brand_organic_toggle' => TIKTOK_BRAND_ORGANIC_TOGGLE,
    ];

    return tiktok_api_post($accessToken, TIKTOK_DIRECT_POST_INIT_URL, [
        'post_info' => $postInfo,
        'source_info' => $sourceInfo,
    ]);
}

/**
 * @return array<string, mixed>|null
 */
function tiktok_fetch_post_status(string $accessToken, string $publishId): ?array
{
    $result = tiktok_api_post($accessToken, TIKTOK_POST_STATUS_URL, ['publish_id' => $publishId]);
    $parsed = tiktok_parse_api_response($result);
    if (!$parsed['ok']) {
        return null;
    }

    $data = $result['body']['data'] ?? null;

    return is_array($data) ? $data : null;
}

/**
 * @return array{status: string, tiktok_video_url: string, fail_reason: string}
 */
function tiktok_wait_until_published(string $accessToken, string $publishId, string $creatorUsername = ''): array
{
    $terminal = ['PUBLISH_COMPLETE', 'FAILED'];
    $last = ['status' => 'UNKNOWN', 'tiktok_video_url' => '', 'fail_reason' => ''];

    for ($attempt = 0; $attempt < 60; $attempt++) {
        $data = tiktok_fetch_post_status($accessToken, $publishId);
        if ($data === null) {
            if ($attempt < 59) {
                sleep(5);
            }
            continue;
        }

        $status = (string) ($data['status'] ?? 'UNKNOWN');
        $failReason = (string) ($data['fail_reason'] ?? '');
        $postIds = $data['publicaly_available_post_id'] ?? [];
        if (!is_array($postIds)) {
            $postIds = [];
        }

        $tiktokVideoUrl = '';
        if ($postIds !== []) {
            $postId = trim((string) $postIds[0]);
            if ($postId !== '') {
                $username = trim((string) ($data['creator_username'] ?? ''));
                if ($username === '') {
                    $username = $creatorUsername;
                }
                $tiktokVideoUrl = $username !== ''
                    ? 'https://www.tiktok.com/@' . $username . '/video/' . $postId
                    : 'https://www.tiktok.com/video/' . $postId;
            }
        }

        $last = [
            'status' => $status,
            'tiktok_video_url' => $tiktokVideoUrl,
            'fail_reason' => $failReason,
        ];

        script_out('Publish status: ' . $status);

        if (in_array($status, $terminal, true)) {
            return $last;
        }

        if ($attempt < 59) {
            sleep(5);
        }
    }

    return $last;
}

function tiktok_upload_file_chunks(string $uploadUrl, string $filePath, int $videoSize, int $chunkSize, int $totalChunks, string $mimeType): void
{
    $handle = fopen($filePath, 'rb');
    if ($handle === false) {
        throw new RuntimeException('Could not read video file for upload.');
    }

    try {
        for ($chunkIndex = 0; $chunkIndex < $totalChunks; $chunkIndex++) {
            $firstByte = $chunkIndex * $chunkSize;
            $remaining = $videoSize - $firstByte;
            $thisChunkSize = (int) min($chunkSize, $remaining);
            $lastByte = $firstByte + $thisChunkSize - 1;

            $data = fread($handle, $thisChunkSize);
            if ($data === false || strlen($data) !== $thisChunkSize) {
                throw new RuntimeException('Failed to read video chunk ' . ($chunkIndex + 1) . ' of ' . $totalChunks);
            }

            $ch = curl_init($uploadUrl);
            if ($ch === false) {
                throw new RuntimeException('curl_init failed for chunk upload.');
            }

            curl_setopt_array($ch, [
                CURLOPT_CUSTOMREQUEST => 'PUT',
                CURLOPT_POSTFIELDS => $data,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_HTTPHEADER => [
                    'Content-Type: ' . $mimeType,
                    'Content-Length: ' . $thisChunkSize,
                    'Content-Range: bytes ' . $firstByte . '-' . $lastByte . '/' . $videoSize,
                ],
                CURLOPT_TIMEOUT => 600,
            ]);

            $raw = curl_exec($ch);
            $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $curlError = curl_error($ch);
            curl_close($ch);

            if ($raw === false) {
                throw new RuntimeException('Chunk upload failed: ' . $curlError);
            }

            $expected = ($chunkIndex === $totalChunks - 1) ? [200, 201] : [200, 201, 206];
            if (!in_array($httpCode, $expected, true)) {
                throw new RuntimeException(
                    'Chunk ' . ($chunkIndex + 1) . '/' . $totalChunks . ' HTTP ' . $httpCode . ': ' . $raw
                );
            }

            script_out('Uploaded chunk ' . ($chunkIndex + 1) . '/' . $totalChunks . ' (HTTP ' . $httpCode . ')');
        }
    } finally {
        fclose($handle);
    }
}

/**
 * @return array<string, mixed>
 */
function tiktok_upload_video(string $accessToken, string $videoUrl): array
{
    script_out('Downloading video...');
    script_out('Source: ' . $videoUrl);

    $creator = tiktok_query_creator_info($accessToken);
    $creatorUsername = (string) ($creator['creator_username'] ?? '');
    if ($creatorUsername !== '') {
        script_out('Posting as @' . $creatorUsername);
    }

    $localPath = tiktok_download_video($videoUrl);

    try {
        $videoSize = (int) filesize($localPath);
        $plan = tiktok_upload_chunk_plan($videoSize);
        $mimeType = tiktok_video_mime_type($localPath);

        script_out('Downloaded ' . number_format($videoSize) . ' bytes');

        $sourceInfo = [
            'source' => 'FILE_UPLOAD',
            'video_size' => $videoSize,
            'chunk_size' => $plan['chunk_size'],
            'total_chunk_count' => $plan['total_chunk_count'],
        ];

        $privacyOptions = $creator['privacy_level_options'] ?? [];
        if (!is_array($privacyOptions)) {
            $privacyOptions = [];
        }
        $privacyLevel = tiktok_pick_privacy_level($privacyOptions);
        script_out('Initializing Direct Post (privacy: ' . $privacyLevel . ')...');

        $initResult = tiktok_direct_post_init($accessToken, $creator, $sourceInfo);
        $parsed = tiktok_parse_api_response($initResult);

        if (!$parsed['ok']) {
            return $parsed;
        }

        $uploadUrl = (string) ($parsed['upload_url'] ?? '');
        if ($uploadUrl === '') {
            return [
                'ok' => false,
                'http_code' => $parsed['http_code'],
                'error_code' => 'missing_upload_url',
                'error_message' => 'TikTok did not return upload_url',
                'publish_id' => $parsed['publish_id'],
                'raw' => $parsed['raw'],
                'upload_url' => '',
            ];
        }

        script_out('Uploading to TikTok (' . $plan['total_chunk_count'] . ' chunk(s))...');
        tiktok_upload_file_chunks(
            $uploadUrl,
            $localPath,
            $videoSize,
            $plan['chunk_size'],
            $plan['total_chunk_count'],
            $mimeType
        );

        return array_merge($parsed, ['creator_username' => $creatorUsername]);
    } finally {
        @unlink($localPath);
    }
}

// --- main ---

if (PHP_SAPI !== 'cli') {
    header('Content-Type: text/plain; charset=UTF-8');
}

$videoUrl = trim(TIKTOK_VIDEO_URL);

script_out(tiktok_is_sandbox() ? 'Mode: TikTok SANDBOX' : 'Mode: TikTok PRODUCTION');

try {
    $accessToken = tiktok_ensure_access_token();
    tiktok_require_scopes(['video.upload', 'video.publish']);
} catch (RuntimeException $e) {
    script_out($e->getMessage());
    exit(1);
}

if ($videoUrl === '' || !filter_var($videoUrl, FILTER_VALIDATE_URL)) {
    script_out('Set a valid HTTPS TIKTOK_VIDEO_URL in cron/tiktok_credentials.inc.php');
    exit(1);
}

if (!preg_match('#^https://#i', $videoUrl)) {
    script_out('video_url must be HTTPS.');
    exit(1);
}

try {
    $parsed = tiktok_upload_video($accessToken, $videoUrl);
} catch (RuntimeException $e) {
    script_out('Error: ' . $e->getMessage());
    exit(1);
}

if (!$parsed['ok'] && ($parsed['error_code'] ?? '') === 'access_token_invalid') {
    try {
        $tokens = tiktok_tokens_load();
        if (is_array($tokens)) {
            $tokens['expires_at'] = 0;
            tiktok_tokens_save($tokens);
        }
        $accessToken = tiktok_ensure_access_token();
        script_out('Access token refreshed, retrying...');
        $parsed = tiktok_upload_video($accessToken, $videoUrl);
    } catch (RuntimeException $e) {
        script_out($e->getMessage());
        exit(1);
    }
}

if (!$parsed['ok']) {
    script_out('TikTok API error (HTTP ' . $parsed['http_code'] . '):');
    script_out('  code: ' . ($parsed['error_code'] !== '' ? $parsed['error_code'] : '(none)'));
    if ($parsed['error_message'] !== '') {
        script_out('  message: ' . $parsed['error_message']);
    }
    if (($parsed['error_code'] ?? '') === 'unaudited_client_can_only_post_to_private_accounts') {
        script_out('  tip: Set TIKTOK_PRIVACY_LEVEL = \'SELF_ONLY\' until TikTok audits your app.');
    }
    script_out('Raw response:');
    script_out($parsed['raw']);
    exit(1);
}

script_out('Waiting for TikTok to publish...');

$publishId = (string) ($parsed['publish_id'] ?? '');
$creatorUsername = (string) ($parsed['creator_username'] ?? '');

if ($publishId === '') {
    script_out('No publish_id returned.');
    exit(1);
}

script_out('publish_id: ' . $publishId);

$statusInfo = tiktok_wait_until_published($accessToken, $publishId, $creatorUsername);

script_out('Success.');
script_out('source_video_url: ' . $videoUrl);
script_out('tiktok_status: ' . $statusInfo['status']);

if ($statusInfo['tiktok_video_url'] !== '') {
    script_out('tiktok_video_url: ' . $statusInfo['tiktok_video_url']);
} elseif ($statusInfo['status'] === 'PUBLISH_COMPLETE') {
    script_out('tiktok_video_url: (published — link may appear after moderation; check your TikTok profile)');
} elseif ($statusInfo['status'] === 'FAILED') {
    script_out('Publish failed: ' . ($statusInfo['fail_reason'] !== '' ? $statusInfo['fail_reason'] : 'unknown'));
    exit(1);
} else {
    script_out('tiktok_video_url: (still processing — check profile @' . $creatorUsername . ' in a few minutes)');
}

exit(0);
