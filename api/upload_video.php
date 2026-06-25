<?php

declare(strict_types=1);

/**
 * Receive rendered listing videos from local cron/send_to_ffmpeg.php.
 * POST multipart: token, folder (e.g. 6-22), file (.mp4)
 */

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'POST required']);
    exit;
}

$credsPath = dirname(__DIR__) . '/video_upload.credentials.php';
if (!is_readable($credsPath)) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'upload credentials not configured']);
    exit;
}

/** @var array<string, mixed> $creds */
$creds = require $credsPath;
$expectedToken = isset($creds['token']) ? (string) $creds['token'] : '';
$postedToken = isset($_POST['token']) ? (string) $_POST['token'] : '';

if ($expectedToken === '' || !hash_equals($expectedToken, $postedToken)) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'invalid token']);
    exit;
}

$folder = isset($_POST['folder']) ? trim((string) $_POST['folder']) : '';
if (!preg_match('/^\d{1,2}-\d{1,2}$/', $folder)) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'invalid folder']);
    exit;
}

if (!isset($_FILES['file']) || !is_array($_FILES['file'])) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'missing file']);
    exit;
}

$file = $_FILES['file'];
if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'upload failed', 'code' => (int) ($file['error'] ?? 0)]);
    exit;
}

$tmpPath = (string) ($file['tmp_name'] ?? '');
$name = basename((string) ($file['name'] ?? ''));
if ($name === '' || !preg_match('/^[A-Za-z0-9._-]+\.mp4$/', $name)) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'invalid filename']);
    exit;
}

$size = (int) ($file['size'] ?? 0);
if ($size <= 0 || $size > 120 * 1024 * 1024) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'invalid file size']);
    exit;
}

$finfo = finfo_open(FILEINFO_MIME_TYPE);
$mime = $finfo !== false ? (string) finfo_file($finfo, $tmpPath) : '';
if ($finfo !== false) {
    finfo_close($finfo);
}
if ($mime !== '' && $mime !== 'video/mp4' && $mime !== 'application/octet-stream') {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'not an mp4']);
    exit;
}

$videoRoot = dirname(__DIR__) . '/videos';
$destDir = $videoRoot . '/' . $folder;
if (!is_dir($destDir) && !mkdir($destDir, 0755, true) && !is_dir($destDir)) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'cannot create folder']);
    exit;
}

$destPath = $destDir . '/' . $name;
if (!move_uploaded_file($tmpPath, $destPath)) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'save failed']);
    exit;
}

@chmod($destPath, 0644);

$publicPath = '/videos/' . rawurlencode($folder) . '/' . rawurlencode($name);
echo json_encode([
    'ok' => true,
    'path' => $publicPath,
    'bytes' => $size,
], JSON_UNESCAPED_SLASHES);
