<?php
/**
 * Upload a video to your TikTok inbox (Content Posting API).
 *
 * Downloads TIKTOK_VIDEO_URL, uploads via FILE_UPLOAD (no domain verification needed).
 * Tokens: cron/.tiktok_tokens.json (authorize once via tiktok_oauth_start.php)
 *
 * Usage: php cron/upload_to_tiktok.php
 */

declare(strict_types=1);

require_once dirname(__DIR__) . '/helpers/tiktok_oauth.php';

const TIKTOK_INBOX_VIDEO_INIT_URL = 'https://open.tiktokapis.com/v2/post/publish/inbox/video/init/';
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
 * @param array<string, mixed> $sourceInfo
 * @return array{http_code: int, body: array<string, mixed>|null, raw: string}
 */
function tiktok_inbox_init_file_upload(string $accessToken, array $sourceInfo): array
{
    $payload = ['source_info' => $sourceInfo];

    $ch = curl_init(TIKTOK_INBOX_VIDEO_INIT_URL);
    if ($ch === false) {
        return ['http_code' => 0, 'body' => null, 'raw' => 'curl_init failed'];
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
 * @return array{ok: bool, http_code: int, error_code: string, error_message: string, publish_id: string, raw: string}
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

    $localPath = tiktok_download_video($videoUrl);

    try {
        $videoSize = (int) filesize($localPath);
        $plan = tiktok_upload_chunk_plan($videoSize);
        $mimeType = tiktok_video_mime_type($localPath);

        script_out('Downloaded ' . number_format($videoSize) . ' bytes');
        script_out('Initializing TikTok inbox upload (FILE_UPLOAD)...');

        $sourceInfo = [
            'source' => 'FILE_UPLOAD',
            'video_size' => $videoSize,
            'chunk_size' => $plan['chunk_size'],
            'total_chunk_count' => $plan['total_chunk_count'],
        ];

        $initResult = tiktok_inbox_init_file_upload($accessToken, $sourceInfo);
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

        return $parsed;
    } finally {
        @unlink($localPath);
    }
}

// --- main ---

if (PHP_SAPI !== 'cli') {
    header('Content-Type: text/plain; charset=UTF-8');
}

$videoUrl = trim(TIKTOK_VIDEO_URL);

try {
    $accessToken = tiktok_ensure_access_token();
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
    script_out('Raw response:');
    script_out($parsed['raw']);
    exit(1);
}

script_out('Success.');
script_out('video_url: ' . $videoUrl);
if ($parsed['publish_id'] !== '') {
    script_out('publish_id: ' . $parsed['publish_id']);
}
script_out('Open the TikTok app → Inbox to finish editing and publish the video.');

exit(0);
