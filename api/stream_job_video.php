<?php

declare(strict_types=1);

/**
 * Stream a job source tour video with HTTP Range support.
 * Needed because `php -S` (and some hosts) do not Range-serve static files,
 * which breaks HTML5 video seeking.
 *
 * GET /api/stream_job_video.php?job=upl_xxxx
 */

header('X-Content-Type-Options: nosniff');

$jobId = isset($_GET['job']) ? trim((string) $_GET['job']) : '';
if ($jobId === '' || !preg_match('/^[A-Za-z0-9_-]{8,120}$/', $jobId)) {
    http_response_code(400);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'Missing or invalid job';
    exit;
}

$root = dirname(__DIR__) . '/yhome_ai/' . $jobId;
$abs = null;
$ext = 'mp4';
foreach (['mp4', 'm4v', 'webm', 'mov', 'mkv', 'avi'] as $try) {
    $candidate = $root . '/source.' . $try;
    if (is_readable($candidate) && is_file($candidate)) {
        $abs = $candidate;
        $ext = $try;
        break;
    }
}

if ($abs === null) {
    // Fall back to latest upload submission for this job
    require_once dirname(__DIR__) . '/pdo_connect.php';
    $pdo = db_pdo_connect();
    if ($pdo instanceof PDO) {
        try {
            $st = $pdo->prepare(
                'SELECT stored_path FROM house_tour_submissions
                 WHERE job_id = ? AND source = \'upload\'
                 ORDER BY id DESC LIMIT 1'
            );
            $st->execute([$jobId]);
            $rel = ltrim(str_replace('\\', '/', (string) ($st->fetchColumn() ?: '')), '/');
            if ($rel !== '' && !str_contains($rel, '..')) {
                $candidate = dirname(__DIR__) . '/' . $rel;
                if (is_readable($candidate) && is_file($candidate)) {
                    $abs = $candidate;
                    $ext = strtolower(pathinfo($candidate, PATHINFO_EXTENSION) ?: 'mp4');
                }
            }
        } catch (Throwable $e) {
            // ignore
        }
    }
}

if ($abs === null) {
    http_response_code(404);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'Video not found';
    exit;
}

$mime = match ($ext) {
    'mp4', 'm4v' => 'video/mp4',
    'mov' => 'video/quicktime',
    'webm' => 'video/webm',
    'mkv' => 'video/x-matroska',
    'avi' => 'video/x-msvideo',
    default => 'application/octet-stream',
};

$size = filesize($abs);
if ($size === false || $size < 0) {
    http_response_code(500);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'Bad file size';
    exit;
}
$size = (int) $size;

header('Content-Type: ' . $mime);
header('Accept-Ranges: bytes');
header('Cache-Control: public, max-age=86400');
header('Content-Disposition: inline; filename="tour.' . $ext . '"');

$start = 0;
$end = max(0, $size - 1);
$status = 200;

if ($size > 0 && isset($_SERVER['HTTP_RANGE'])
    && preg_match('/bytes=(\d*)-(\d*)/', (string) $_SERVER['HTTP_RANGE'], $m)) {
    if ($m[1] !== '') {
        $start = (int) $m[1];
    }
    if ($m[2] !== '') {
        $end = (int) $m[2];
    }
    if ($end >= $size) {
        $end = $size - 1;
    }
    if ($start > $end || $start >= $size) {
        http_response_code(416);
        header('Content-Range: bytes */' . $size);
        exit;
    }
    $status = 206;
}

$length = $end - $start + 1;
http_response_code($status);
if ($status === 206) {
    header('Content-Range: bytes ' . $start . '-' . $end . '/' . $size);
}
header('Content-Length: ' . (string) $length);

// HEAD: headers only
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'HEAD') {
    exit;
}

$fp = fopen($abs, 'rb');
if ($fp === false) {
    http_response_code(500);
    echo 'Cannot open file';
    exit;
}
if ($start > 0) {
    fseek($fp, $start);
}

while ($length > 0 && !feof($fp)) {
    $chunk = fread($fp, (int) min(8192, $length));
    if ($chunk === false || $chunk === '') {
        break;
    }
    echo $chunk;
    $length -= strlen($chunk);
    if (connection_aborted()) {
        break;
    }
}
fclose($fp);
exit;
