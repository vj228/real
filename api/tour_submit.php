<?php

declare(strict_types=1);

/**
 * Public queue: save YouTube URL or uploaded video for admin processing.
 *
 * POST JSON: { listing_id, email, url? }
 * POST multipart: listing_id, email, video=<file>
 */

header('Content-Type: application/json; charset=utf-8');

const TOUR_SUB_ROOT = __DIR__ . '/../yhome_ai/submissions';

function ts_out(array $payload, int $code = 200): void
{
    http_response_code($code);
    echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

function ts_fail(string $message, int $code = 400): void
{
    ts_out(['ok' => false, 'error' => $message], $code);
}

function ts_parse_youtube_id(string $url): ?string
{
    $url = trim($url);
    if ($url === '') {
        return null;
    }
    if (preg_match('/^[A-Za-z0-9_-]{11}$/', $url)) {
        return $url;
    }
    if (preg_match('/(?:youtube\.com\/(?:watch\?v=|embed\/|shorts\/)|youtu\.be\/)([A-Za-z0-9_-]{11})/', $url, $m)) {
        return $m[1];
    }

    return null;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    ts_fail('POST required', 405);
}

require_once dirname(__DIR__) . '/pdo_connect.php';

$raw = file_get_contents('php://input');
$body = is_string($raw) && $raw !== '' ? json_decode($raw, true) : null;

$listingId = 0;
$email = '';
$url = '';
if (is_array($body)) {
    $listingId = (int) ($body['listing_id'] ?? 0);
    $email = strtolower(trim((string) ($body['email'] ?? '')));
    $url = trim((string) ($body['url'] ?? ''));
}
if (isset($_POST['listing_id'])) {
    $listingId = (int) $_POST['listing_id'];
}
if (isset($_POST['email'])) {
    $email = strtolower(trim((string) $_POST['email']));
}
if (isset($_POST['url'])) {
    $url = trim((string) $_POST['url']);
}

if ($listingId <= 0) {
    ts_fail('Missing listing_id');
}
if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    ts_fail('A valid email is required.');
}
if (strlen($email) > 255) {
    ts_fail('Email is too long.');
}

$upload = $_FILES['video'] ?? null;
$hasUpload = is_array($upload)
    && (int) ($upload['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE;

$pdo = db_pdo_connect();
if (!$pdo instanceof PDO) {
    ts_fail('Database unavailable', 500);
}

$exists = $pdo->prepare('SELECT id FROM zillow_sale_listings WHERE id = ? LIMIT 1');
$exists->execute([$listingId]);
if (!$exists->fetch()) {
    ts_fail('Listing not found', 404);
}

$source = '';
$youtubeUrl = null;
$originalFilename = null;
$storedPath = null;

if ($hasUpload) {
    $err = (int) ($upload['error'] ?? UPLOAD_ERR_NO_FILE);
    if ($err !== UPLOAD_ERR_OK) {
        ts_fail('Upload failed (code ' . $err . ').');
    }
    $origName = (string) ($upload['name'] ?? 'tour.mp4');
    $ext = strtolower(pathinfo($origName, PATHINFO_EXTENSION));
    $allowedExt = ['mp4', 'mov', 'webm', 'mkv', 'm4v', 'avi'];
    if (!in_array($ext, $allowedExt, true)) {
        ts_fail('Unsupported file type. Upload mp4, mov, webm, mkv, m4v, or avi.');
    }
    $tmp = (string) ($upload['tmp_name'] ?? '');
    if ($tmp === '' || !is_uploaded_file($tmp)) {
        ts_fail('Invalid uploaded file.');
    }
    $maxBytes = 400 * 1024 * 1024;
    $size = (int) ($upload['size'] ?? 0);
    if ($size <= 0 || $size > $maxBytes) {
        ts_fail('Video must be under 400 MB.');
    }
    if (!is_dir(TOUR_SUB_ROOT) && !mkdir(TOUR_SUB_ROOT, 0755, true) && !is_dir(TOUR_SUB_ROOT)) {
        ts_fail('Cannot create submissions folder.', 500);
    }
    $token = bin2hex(random_bytes(8));
    $dir = TOUR_SUB_ROOT . '/' . $token;
    if (!mkdir($dir, 0755, true) && !is_dir($dir)) {
        ts_fail('Cannot create submission folder.', 500);
    }
    $dest = $dir . '/video.' . $ext;
    if (!move_uploaded_file($tmp, $dest)) {
        ts_fail('Could not store uploaded video.', 500);
    }
    $source = 'upload';
    $originalFilename = $origName;
    $storedPath = 'yhome_ai/submissions/' . $token . '/video.' . $ext;
} else {
    $ytId = ts_parse_youtube_id($url);
    if ($ytId === null) {
        ts_fail('Paste a YouTube URL or upload a video file.');
    }
    $source = 'youtube';
    $youtubeUrl = 'https://www.youtube.com/watch?v=' . $ytId;
}

try {
    $stmt = $pdo->prepare(
        'INSERT INTO house_tour_submissions
            (listing_id, contact_email, source, youtube_url, original_filename, stored_path, status)
         VALUES
            (:listing_id, :email, :source, :youtube_url, :original_filename, :stored_path, \'pending\')'
    );
    $stmt->execute([
        ':listing_id' => $listingId,
        ':email' => $email,
        ':source' => $source,
        ':youtube_url' => $youtubeUrl,
        ':original_filename' => $originalFilename,
        ':stored_path' => $storedPath,
    ]);
    $id = (int) $pdo->lastInsertId();
} catch (Throwable $e) {
    ts_fail('Could not save submission: ' . $e->getMessage(), 500);
}

ts_out([
    'ok' => true,
    'submission_id' => $id,
    'status' => 'pending',
    'message' => 'Thanks — your tour was submitted. We’ll email you when the renovation estimate is ready.',
]);
