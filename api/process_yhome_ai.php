<?php

declare(strict_types=1);

/**
 * Download a YouTube video, extract 1 frame every 2s, return 20–30 evenly spaced frames.
 *
 * POST JSON: { "url": "https://www.youtube.com/watch?v=..." }
 */

header('Content-Type: application/json; charset=utf-8');

set_time_limit(0);
ignore_user_abort(true);

const YT_MIN_FRAMES = 20;
const YT_MAX_FRAMES = 30;
const YT_FRAME_EVERY_SEC = 2;
const YT_WORK_ROOT = __DIR__ . '/../yhome_ai';
const YT_PUBLIC_BASE = '/yhome_ai';

function yt_json_out(array $payload, int $code = 200): void
{
    http_response_code($code);
    echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

function yt_fail(string $message, int $code = 400): void
{
    yt_json_out(['ok' => false, 'error' => $message], $code);
}

function yt_find_bin(string $name): string
{
    $candidates = [
        dirname(__DIR__) . '/bin/' . $name,
        '/opt/homebrew/bin/' . $name,
        '/usr/local/bin/' . $name,
        trim((string) shell_exec('command -v ' . escapeshellarg($name) . ' 2>/dev/null')),
    ];
    foreach ($candidates as $path) {
        if ($path !== '' && is_executable($path)) {
            return $path;
        }
    }

    return '';
}

function yt_parse_id(string $url): ?string
{
    $url = trim($url);
    if ($url === '') {
        return null;
    }
    if (preg_match('/^[A-Za-z0-9_-]{11}$/', $url)) {
        return $url;
    }
    $parts = parse_url($url);
    if (!is_array($parts) || empty($parts['host'])) {
        return null;
    }
    $host = strtolower((string) $parts['host']);
    $host = preg_replace('/^www\./', '', $host) ?? $host;
    $path = (string) ($parts['path'] ?? '');

    if (in_array($host, ['youtube.com', 'm.youtube.com', 'music.youtube.com', 'youtube-nocookie.com'], true)) {
        parse_str((string) ($parts['query'] ?? ''), $q);
        if (!empty($q['v']) && preg_match('/^[A-Za-z0-9_-]{11}$/', (string) $q['v'])) {
            return (string) $q['v'];
        }
        if (preg_match('#/(?:embed|shorts|live)/([A-Za-z0-9_-]{11})#', $path, $m)) {
            return $m[1];
        }
    }
    if ($host === 'youtu.be' && preg_match('#^/([A-Za-z0-9_-]{11})#', $path, $m)) {
        return $m[1];
    }

    return null;
}

function yt_run(array $cmd, ?string &$stderr = null): int
{
    $proc = proc_open($cmd, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    if (!is_resource($proc)) {
        $stderr = 'Failed to start process';
        return 1;
    }
    stream_get_contents($pipes[1]);
    $stderr = (string) stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);

    return proc_close($proc);
}

function yt_probe_duration(string $ffprobe, string $videoPath): float
{
    $cmd = [
        $ffprobe, '-v', 'error',
        '-show_entries', 'format=duration',
        '-of', 'default=noprint_wrappers=1:nokey=1',
        $videoPath,
    ];
    $proc = proc_open($cmd, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    if (!is_resource($proc)) {
        return 0.0;
    }
    $out = trim((string) stream_get_contents($pipes[1]));
    fclose($pipes[1]);
    fclose($pipes[2]);
    proc_close($proc);

    return max(0.0, (float) $out);
}

/** @return list<int> */
function yt_pick_even_indices(int $total, int $want): array
{
    if ($total <= 0) {
        return [];
    }
    $want = max(1, min($want, $total));
    if ($want === 1) {
        return [0];
    }
    $picked = [];
    for ($i = 0; $i < $want; $i++) {
        $picked[] = (int) round($i * ($total - 1) / ($want - 1));
    }

    return array_values(array_unique($picked));
}

function yt_target_count(int $available): int
{
    if ($available <= YT_MAX_FRAMES) {
        return max(1, $available);
    }
    // Prefer ~25 when the video is long enough.
    return min(YT_MAX_FRAMES, max(YT_MIN_FRAMES, 25));
}

function yt_rm_dir(string $dir): void
{
    if (!is_dir($dir)) {
        return;
    }
    foreach (glob($dir . '/*') ?: [] as $f) {
        if (is_dir($f)) {
            yt_rm_dir($f);
        } else {
            @unlink($f);
        }
    }
    @rmdir($dir);
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    yt_fail('POST required', 405);
}

$raw = file_get_contents('php://input');
$body = is_string($raw) ? json_decode($raw, true) : null;
$url = '';
if (is_array($body) && isset($body['url'])) {
    $url = trim((string) $body['url']);
} elseif (isset($_POST['url'])) {
    $url = trim((string) $_POST['url']);
}

$videoId = yt_parse_id($url);
if ($videoId === null) {
    yt_fail('Enter a valid YouTube video URL.');
}

$ytDlp = yt_find_bin('yt-dlp');
$ffmpeg = yt_find_bin('ffmpeg');
$ffprobe = yt_find_bin('ffprobe');
if ($ytDlp === '') {
    yt_fail('yt-dlp not found. Place it at bin/yt-dlp or install with brew/pip.', 500);
}
if ($ffmpeg === '' || $ffprobe === '') {
    yt_fail('ffmpeg/ffprobe not found. Install: brew install ffmpeg', 500);
}

if (!is_dir(YT_WORK_ROOT) && !mkdir(YT_WORK_ROOT, 0755, true) && !is_dir(YT_WORK_ROOT)) {
    yt_fail('Cannot create work directory.', 500);
}

$jobId = bin2hex(random_bytes(4)); // e.g. a7f3c291
$workDir = YT_WORK_ROOT . '/' . $jobId;
$framesDir = $workDir . '/frames';
$selectedDir = $workDir . '/selected';
if (!mkdir($framesDir, 0755, true) || !mkdir($selectedDir, 0755, true)) {
    yt_fail('Cannot create job directories.', 500);
}

$watchUrl = 'https://www.youtube.com/watch?v=' . $videoId;
$outTpl = $workDir . '/video.%(ext)s';

$dlErr = '';
yt_run([
    $ytDlp,
    '--no-playlist',
    '--no-warnings',
    '-f', 'bv*[height<=720]+ba/b[height<=720]/b',
    '--merge-output-format', 'mp4',
    '-o', $outTpl,
    '--write-info-json',
    $watchUrl,
], $dlErr);

$videoPath = '';
foreach (glob($workDir . '/video.*') ?: [] as $f) {
    $ext = strtolower(pathinfo($f, PATHINFO_EXTENSION));
    if (in_array($ext, ['mp4', 'mkv', 'webm', 'mov'], true)) {
        $videoPath = $f;
        break;
    }
}
if ($videoPath === '') {
    $tail = trim(implode("\n", array_slice(explode("\n", trim($dlErr)), -8)));
    yt_rm_dir($workDir);
    yt_fail('YouTube download failed.' . ($tail !== '' ? "\n" . $tail : ''), 502);
}

$title = $videoId;
foreach (glob($workDir . '/*.info.json') ?: [] as $infoPath) {
    $info = json_decode((string) file_get_contents($infoPath), true);
    if (is_array($info) && !empty($info['title'])) {
        $title = (string) $info['title'];
    }
    break;
}

$duration = yt_probe_duration($ffprobe, $videoPath);
if ($duration <= 0) {
    yt_rm_dir($workDir);
    yt_fail('Could not read video duration.', 500);
}

$fps = '1/' . YT_FRAME_EVERY_SEC;
$framePattern = $framesDir . '/frame_%05d.jpg';
$extErr = '';
$extCode = yt_run([
    $ffmpeg, '-y',
    '-i', $videoPath,
    '-vf', 'fps=' . $fps,
    '-q:v', '3',
    $framePattern,
], $extErr);
if ($extCode !== 0) {
    $tail = trim(implode("\n", array_slice(explode("\n", trim($extErr)), -6)));
    yt_rm_dir($workDir);
    yt_fail('FFmpeg frame extraction failed.' . ($tail !== '' ? "\n" . $tail : ''), 500);
}

$allFrames = glob($framesDir . '/frame_*.jpg') ?: [];
natcasesort($allFrames);
$allFrames = array_values($allFrames);
$total = count($allFrames);
if ($total === 0) {
    yt_rm_dir($workDir);
    yt_fail('No frames were extracted from the video.', 500);
}

$want = yt_target_count($total);
$indices = yt_pick_even_indices($total, $want);
$frames = [];
foreach ($indices as $idx) {
    $src = $allFrames[$idx];
    $timeSec = $idx * YT_FRAME_EVERY_SEC;
    $name = sprintf('t%05d.jpg', $timeSec);
    $dest = $selectedDir . '/' . $name;
    if (!@copy($src, $dest)) {
        continue;
    }
    $frames[] = [
        'time_sec' => $timeSec,
        'url' => YT_PUBLIC_BASE . '/' . rawurlencode($jobId) . '/selected/' . rawurlencode($name),
    ];
}

if ($frames === []) {
    yt_rm_dir($workDir);
    yt_fail('Failed to prepare selected frames.', 500);
}

// Keep selected frames + meta; drop the large source video and raw every-2s dump.
@unlink($videoPath);
foreach ($allFrames as $f) {
    @unlink($f);
}
@rmdir($framesDir);

$result = [
    'ok' => true,
    'job_id' => $jobId,
    'video_id' => $videoId,
    'title' => $title,
    'duration_sec' => (int) round($duration),
    'extracted_every_sec' => YT_FRAME_EVERY_SEC,
    'extracted_count' => $total,
    'selected_count' => count($frames),
    'created_at' => date('c'),
    'frames' => $frames,
];
file_put_contents($workDir . '/meta.json', json_encode($result, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));

yt_json_out($result);
