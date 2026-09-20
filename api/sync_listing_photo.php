<?php

declare(strict_types=1);

/**
 * Receive a listing card photo from local and write listing_photos/{id}.jpg on the public host.
 * POST multipart: key, listing_id, photo (jpeg/png/webp).
 */

header('Content-Type: application/json; charset=utf-8');
set_time_limit(0);
ini_set('display_errors', '0');

const LISTING_PHOTOS_ROOT = __DIR__ . '/../listing_photos';

require_once dirname(__DIR__) . '/config/job_frames_sync.php';

function listing_photo_out(array $payload, int $code = 200): void
{
    http_response_code($code);
    echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    listing_photo_out(['ok' => false, 'error' => 'POST required'], 405);
}

$expected = yai_sync_key();
$key = (string) ($_POST['key'] ?? '');
if ($expected === '' || $key === '' || !hash_equals($expected, $key)) {
    listing_photo_out(['ok' => false, 'error' => 'Unauthorized'], 403);
}

$listingId = (int) ($_POST['listing_id'] ?? 0);
if ($listingId < 1) {
    listing_photo_out(['ok' => false, 'error' => 'Invalid listing_id'], 400);
}

if (!isset($_FILES['photo']) || !is_array($_FILES['photo'])) {
    listing_photo_out(['ok' => false, 'error' => 'No photo uploaded'], 400);
}

$err = (int) ($_FILES['photo']['error'] ?? UPLOAD_ERR_NO_FILE);
if ($err !== UPLOAD_ERR_OK) {
    listing_photo_out(['ok' => false, 'error' => 'Upload error ' . $err], 400);
}

$tmp = (string) ($_FILES['photo']['tmp_name'] ?? '');
if ($tmp === '' || !is_uploaded_file($tmp)) {
    listing_photo_out(['ok' => false, 'error' => 'Missing temp file'], 400);
}

$size = (int) ($_FILES['photo']['size'] ?? 0);
if ($size < 1 || $size > 8 * 1024 * 1024) {
    listing_photo_out(['ok' => false, 'error' => 'Photo must be 1 byte–8 MB'], 400);
}

$finfo = new finfo(FILEINFO_MIME_TYPE);
$mime = (string) $finfo->file($tmp);
$extByMime = [
    'image/jpeg' => 'jpg',
    'image/png' => 'png',
    'image/webp' => 'webp',
];
if (!isset($extByMime[$mime])) {
    listing_photo_out(['ok' => false, 'error' => 'Unsupported image type'], 400);
}

if (!is_dir(LISTING_PHOTOS_ROOT) && !mkdir(LISTING_PHOTOS_ROOT, 0755, true) && !is_dir(LISTING_PHOTOS_ROOT)) {
    listing_photo_out(['ok' => false, 'error' => 'Failed to create listing_photos directory'], 500);
}

$dest = LISTING_PHOTOS_ROOT . '/' . $listingId . '.jpg';
// Normalize to JPEG for a stable public path.
if ($mime === 'image/jpeg') {
    if (!move_uploaded_file($tmp, $dest)) {
        listing_photo_out(['ok' => false, 'error' => 'Could not save photo'], 500);
    }
} else {
    $blob = (string) file_get_contents($tmp);
    if ($blob === '') {
        listing_photo_out(['ok' => false, 'error' => 'Empty photo'], 400);
    }
    $im = @imagecreatefromstring($blob);
    if ($im === false) {
        listing_photo_out(['ok' => false, 'error' => 'Could not decode image'], 400);
    }
    if (!imagejpeg($im, $dest, 90)) {
        imagedestroy($im);
        listing_photo_out(['ok' => false, 'error' => 'Could not write JPEG'], 500);
    }
    imagedestroy($im);
}
@chmod($dest, 0644);

listing_photo_out([
    'ok' => true,
    'listing_id' => $listingId,
    'path' => '/listing_photos/' . $listingId . '.jpg',
    'bytes' => (int) filesize($dest),
]);
