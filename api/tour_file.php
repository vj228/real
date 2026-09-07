<?php

declare(strict_types=1);

/**
 * Stream a queued house-tour upload by submission id.
 * Used by local admin processing to pull files stored on production.
 *
 * GET /api/tour_file.php?id=123
 */

$submissionId = isset($_GET['id']) ? (int) $_GET['id'] : 0;
if ($submissionId <= 0) {
    http_response_code(400);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'Missing submission id';
    exit;
}

require_once dirname(__DIR__) . '/pdo_connect.php';

$pdo = db_pdo_connect();
if (!$pdo instanceof PDO) {
    http_response_code(500);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'Database unavailable';
    exit;
}

$stmt = $pdo->prepare(
    'SELECT id, source, stored_path, original_filename
     FROM house_tour_submissions
     WHERE id = ? LIMIT 1'
);
$stmt->execute([$submissionId]);
$row = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$row || (string) ($row['source'] ?? '') !== 'upload') {
    http_response_code(404);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'Upload not found';
    exit;
}

$rel = str_replace('\\', '/', (string) ($row['stored_path'] ?? ''));
$rel = ltrim($rel, '/');
if ($rel === '' || str_contains($rel, '..')) {
    http_response_code(404);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'Invalid stored path';
    exit;
}

$abs = dirname(__DIR__) . '/' . $rel;
if (!is_readable($abs) || !is_file($abs)) {
    http_response_code(404);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'File missing on this server';
    exit;
}

$ext = strtolower(pathinfo($abs, PATHINFO_EXTENSION));
$mime = match ($ext) {
    'mp4', 'm4v' => 'video/mp4',
    'mov' => 'video/quicktime',
    'webm' => 'video/webm',
    'mkv' => 'video/x-matroska',
    'avi' => 'video/x-msvideo',
    default => 'application/octet-stream',
};
$name = (string) ($row['original_filename'] ?? ('tour.' . $ext));
$name = preg_replace('/[^\w.\- ()]+/', '_', $name) ?: ('tour.' . $ext);
$size = filesize($abs);

header('Content-Type: ' . $mime);
header('Content-Length: ' . (string) $size);
header('Content-Disposition: inline; filename="' . $name . '"');
header('Cache-Control: private, max-age=3600');
header('X-Content-Type-Options: nosniff');

$fp = fopen($abs, 'rb');
if ($fp === false) {
    http_response_code(500);
    echo 'Cannot open file';
    exit;
}
fpassthru($fp);
fclose($fp);
exit;
