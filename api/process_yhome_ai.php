<?php

declare(strict_types=1);

/**
 * Download a YouTube video OR accept an uploaded video file, extract frames.
 *
 * POST JSON: { "url": "https://www.youtube.com/watch?v=...", "listing_id"?: 1 }
 * POST multipart: video=<file>, listing_id?, url? (optional YouTube if no file)
 */

header('Content-Type: application/json; charset=utf-8');

set_time_limit(0);
ignore_user_abort(true);

const YT_MIN_FRAMES = 16;
const YT_MAX_FRAMES = 24;
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

/** @return array{public_base_url?:string} */
function yt_site_config(): array
{
    static $cfg = null;
    if (is_array($cfg)) {
        return $cfg;
    }
    $path = dirname(__DIR__) . '/config/site.php';
    $cfg = is_readable($path) ? (require $path) : [];
    if (!is_array($cfg)) {
        $cfg = [];
    }

    return $cfg;
}

function yt_public_base_url(): string
{
    $cfg = yt_site_config();
    $base = trim((string) ($cfg['public_base_url'] ?? 'https://yhome.pro'));

    return rtrim($base, '/');
}

/**
 * Download a remote file into $destPath. Returns true on success.
 */
function yt_download_url(string $url, string $destPath, ?string &$error = null): bool
{
    $error = null;
    $dir = dirname($destPath);
    if (!is_dir($dir) && !mkdir($dir, 0755, true) && !is_dir($dir)) {
        $error = 'Cannot create download directory';
        return false;
    }
    $fp = fopen($destPath, 'wb');
    if ($fp === false) {
        $error = 'Cannot open destination for writing';
        return false;
    }
    $ch = curl_init($url);
    if ($ch === false) {
        fclose($fp);
        @unlink($destPath);
        $error = 'curl_init failed';
        return false;
    }
    curl_setopt_array($ch, [
        CURLOPT_FILE => $fp,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS => 5,
        CURLOPT_CONNECTTIMEOUT => 30,
        CURLOPT_TIMEOUT => 600,
        CURLOPT_USERAGENT => 'yHome-local-processor/1.0',
        CURLOPT_FAILONERROR => false,
    ]);
    $ok = curl_exec($ch);
    $http = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $cerr = curl_error($ch);
    curl_close($ch);
    fclose($fp);
    if ($ok === false || $http < 200 || $http >= 300) {
        @unlink($destPath);
        $error = 'HTTP ' . $http . ($cerr !== '' ? (': ' . $cerr) : '');
        return false;
    }
    if (!is_readable($destPath) || filesize($destPath) <= 0) {
        @unlink($destPath);
        $error = 'Downloaded file empty';
        return false;
    }

    return true;
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

/** Prefer less motion-blurred neighbor within ±radius of each target index. */
function yt_sharpness(string $path): float
{
    $im = @imagecreatefromjpeg($path);
    if ($im === false) {
        return 0.0;
    }
    $w = imagesx($im);
    $h = imagesy($im);
    if ($w < 3 || $h < 3) {
        return 0.0;
    }
    $tw = 100;
    $th = max(3, (int) round($h * $tw / $w));
    $small = imagecreatetruecolor($tw, $th);
    imagecopyresampled($small, $im, 0, 0, 0, 0, $tw, $th, $w, $h);
    $sum = 0.0;
    $sum2 = 0.0;
    $n = 0;
    for ($y = 1; $y < $th - 1; $y++) {
        for ($x = 1; $x < $tw - 1; $x++) {
            $c = imagecolorat($small, $x, $y);
            $g = (int) (0.299 * (($c >> 16) & 0xFF) + 0.587 * (($c >> 8) & 0xFF) + 0.114 * ($c & 0xFF));
            $l = imagecolorat($small, $x - 1, $y);
            $r = imagecolorat($small, $x + 1, $y);
            $u = imagecolorat($small, $x, $y - 1);
            $d = imagecolorat($small, $x, $y + 1);
            $gl = (int) (0.299 * (($l >> 16) & 0xFF) + 0.587 * (($l >> 8) & 0xFF) + 0.114 * ($l & 0xFF));
            $gr = (int) (0.299 * (($r >> 16) & 0xFF) + 0.587 * (($r >> 8) & 0xFF) + 0.114 * ($r & 0xFF));
            $gu = (int) (0.299 * (($u >> 16) & 0xFF) + 0.587 * (($u >> 8) & 0xFF) + 0.114 * ($u & 0xFF));
            $gd = (int) (0.299 * (($d >> 16) & 0xFF) + 0.587 * (($d >> 8) & 0xFF) + 0.114 * ($d & 0xFF));
            $lap = (float) (4 * $g - $gl - $gr - $gu - $gd);
            $sum += $lap;
            $sum2 += $lap * $lap;
            $n++;
        }
    }
    if ($n === 0) {
        return 0.0;
    }
    $mean = $sum / $n;

    return ($sum2 / $n) - ($mean * $mean);
}

/**
 * @param list<string> $allFrames
 * @param list<int> $indices
 * @return list<int>
 */
function yt_refine_sharpest(array $allFrames, array $indices, int $radius = 1): array
{
    $total = count($allFrames);
    $used = [];
    $out = [];
    foreach ($indices as $idx) {
        $lo = max(0, $idx - $radius);
        $hi = min($total - 1, $idx + $radius);
        $best = $idx;
        $bestScore = -1.0;
        for ($i = $lo; $i <= $hi; $i++) {
            if (isset($used[$i])) {
                continue;
            }
            $s = yt_sharpness($allFrames[$i]);
            if ($s > $bestScore) {
                $bestScore = $s;
                $best = $i;
            }
        }
        $used[$best] = true;
        $out[] = $best;
    }
    sort($out);

    return $out;
}

/** Outdoor likelihood 0..1 — sky/grass frames score higher. */
function yt_outdoor_likelihood(string $path): float
{
    $im = @imagecreatefromjpeg($path);
    if ($im === false) {
        return 0.5;
    }
    $w = imagesx($im);
    $h = imagesy($im);
    if ($w < 3 || $h < 3) {
        return 0.5;
    }
    $tw = 80;
    $th = max(3, (int) round($h * $tw / $w));
    $small = imagecreatetruecolor($tw, $th);
    imagecopyresampled($small, $im, 0, 0, 0, 0, $tw, $th, $w, $h);
    $topEnd = max(1, (int) floor($th * 0.38));
    $sky = 0;
    $topN = 0;
    $green = 0;
    $n = 0;
    for ($y = 0; $y < $th; $y++) {
        for ($x = 0; $x < $tw; $x++) {
            $c = imagecolorat($small, $x, $y);
            $r = ($c >> 16) & 0xFF;
            $g = ($c >> 8) & 0xFF;
            $b = $c & 0xFF;
            $lum = (0.299 * $r + 0.587 * $g + 0.114 * $b) / 255.0;
            $n++;
            if ($y < $topEnd) {
                $topN++;
                if ($b > $r + 12 && $b > $g + 4 && $lum > 0.35) {
                    $sky++;
                }
            }
            if ($g > $r + 18 && $g > $b + 12 && $lum > 0.2 && $lum < 0.85) {
                $green++;
            }
        }
    }
    if ($n === 0) {
        return 0.5;
    }
    $skyFrac = $topN > 0 ? $sky / $topN : 0.0;
    $greenFrac = $green / $n;

    return max(0.0, min(1.0, 0.6 * $skyFrac + 0.4 * $greenFrac));
}

/**
 * Prefer sharp indoor frames across the tour (skip likely exterior walk-ups).
 *
 * @param list<string> $allFrames
 * @return list<int>
 */
function yt_pick_indoor_indices(array $allFrames, int $want): array
{
    $total = count($allFrames);
    if ($total === 0 || $want <= 0) {
        return [];
    }
    $want = min($want, $total);
    $scored = [];
    for ($i = 0; $i < $total; $i++) {
        $sharp = yt_sharpness($allFrames[$i]);
        $outdoor = yt_outdoor_likelihood($allFrames[$i]);
        $indoor = 1.0 - $outdoor;
        // Softly de-prioritize very start/end (often curb appeal / closing exterior)
        $edge = ($i < $total * 0.08 || $i > $total * 0.92) ? 0.75 : 1.0;
        $scored[] = [
            'i' => $i,
            'score' => $sharp * (0.2 + 0.8 * $indoor) * $edge,
            'outdoor' => $outdoor,
        ];
    }
    usort($scored, static fn ($a, $b) => $b['score'] <=> $a['score']);

    $minGap = max(1, (int) floor($total / max(1, $want * 2)));
    $picked = [];
    foreach ($scored as $row) {
        if (count($picked) >= $want) {
            break;
        }
        if ($row['outdoor'] >= 0.5 && count($scored) > $want * 2) {
            continue;
        }
        $tooClose = false;
        foreach ($picked as $pi) {
            if (abs($pi - $row['i']) < $minGap) {
                $tooClose = true;
                break;
            }
        }
        if ($tooClose && count($picked) + 1 < $want) {
            continue;
        }
        $picked[] = $row['i'];
    }
    if (count($picked) < $want) {
        $seeds = yt_refine_sharpest($allFrames, yt_pick_even_indices($total, $want), 1);
        foreach ($seeds as $i) {
            if (count($picked) >= $want) {
                break;
            }
            if (!in_array($i, $picked, true)) {
                $picked[] = $i;
            }
        }
    }
    sort($picked);

    return $picked;
}

function yt_target_count(int $available): int
{
    if ($available <= YT_MAX_FRAMES) {
        return max(1, $available);
    }

    return min(YT_MAX_FRAMES, max(YT_MIN_FRAMES, 20));
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
$body = is_string($raw) && $raw !== '' ? json_decode($raw, true) : null;
$url = '';
$listingId = 0;
$email = '';
$submissionId = 0;
if (is_array($body) && isset($body['url'])) {
    $url = trim((string) $body['url']);
} elseif (isset($_POST['url'])) {
    $url = trim((string) $_POST['url']);
}
if (is_array($body) && isset($body['listing_id'])) {
    $listingId = (int) $body['listing_id'];
} elseif (isset($_POST['listing_id'])) {
    $listingId = (int) $_POST['listing_id'];
}
if (is_array($body) && isset($body['email'])) {
    $email = strtolower(trim((string) $body['email']));
} elseif (isset($_POST['email'])) {
    $email = strtolower(trim((string) $_POST['email']));
}
if (is_array($body) && isset($body['submission_id'])) {
    $submissionId = (int) $body['submission_id'];
} elseif (isset($_POST['submission_id'])) {
    $submissionId = (int) $_POST['submission_id'];
}
if ($email !== '') {
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        yt_fail('Enter a valid email address.');
    }
    if (strlen($email) > 255) {
        yt_fail('Email is too long.');
    }
}

$upload = $_FILES['video'] ?? null;
$hasUpload = is_array($upload)
    && (int) ($upload['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE;
$videoId = null;
$watchUrl = null;
$title = '';
$source = 'youtube';
$submissionStoredAbs = '';

if ($submissionId > 0) {
    require_once dirname(__DIR__) . '/pdo_connect.php';
    $pdoSub = db_pdo_connect();
    if (!$pdoSub instanceof PDO) {
        yt_fail('Database unavailable', 500);
    }
    $sStmt = $pdoSub->prepare(
        'SELECT id, listing_id, contact_email, source, youtube_url, original_filename, stored_path, status
         FROM house_tour_submissions WHERE id = ? LIMIT 1'
    );
    $sStmt->execute([$submissionId]);
    $sub = $sStmt->fetch(PDO::FETCH_ASSOC);
    if (!$sub) {
        yt_fail('Submission not found: ' . $submissionId, 404);
    }
    if ($listingId <= 0) {
        $listingId = (int) $sub['listing_id'];
    }
    if ($email === '') {
        $email = strtolower(trim((string) $sub['contact_email']));
    }
    $pdoSub->prepare('UPDATE house_tour_submissions SET status = \'processing\' WHERE id = ?')
        ->execute([$submissionId]);

    if ((string) $sub['source'] === 'upload') {
        $rel = (string) ($sub['stored_path'] ?? '');
        $abs = dirname(__DIR__) . '/' . ltrim(str_replace('\\', '/', $rel), '/');
        $origName = (string) ($sub['original_filename'] ?? basename($abs !== '' ? $abs : 'tour.mp4'));
        if ($rel !== '' && is_readable($abs)) {
            $submissionStoredAbs = $abs;
        } else {
            // File lives on production (user uploaded on yhome.pro); pull it here.
            $remoteUrl = yt_public_base_url() . '/api/tour_file.php?id=' . $submissionId;
            $ext = strtolower(pathinfo($origName, PATHINFO_EXTENSION));
            if ($ext === '' && $rel !== '') {
                $ext = strtolower(pathinfo($rel, PATHINFO_EXTENSION));
            }
            if ($ext === '') {
                $ext = 'mp4';
            }
            $tmpDir = sys_get_temp_dir() . '/yhome_sub_' . $submissionId;
            if (!is_dir($tmpDir) && !mkdir($tmpDir, 0755, true) && !is_dir($tmpDir)) {
                yt_fail('Cannot create temp folder to download production upload.', 500);
            }
            $tmpPath = $tmpDir . '/video.' . $ext;
            $dlErr = null;
            if (!yt_download_url($remoteUrl, $tmpPath, $dlErr)) {
                yt_fail(
                    'Stored upload missing locally and download from production failed'
                    . ' (' . $remoteUrl . '): ' . ($dlErr ?? 'unknown'),
                    502
                );
            }
            $submissionStoredAbs = $tmpPath;
        }
        $hasUpload = false;
        $source = 'upload';
        $videoId = 'upl_' . substr(bin2hex(random_bytes(4)), 0, 8);
        $title = pathinfo($origName, PATHINFO_FILENAME) ?: $origName;
    } else {
        $url = (string) ($sub['youtube_url'] ?? '');
        $videoId = yt_parse_id($url);
        if ($videoId === null) {
            yt_fail('Submission has no valid YouTube URL.');
        }
        $watchUrl = 'https://www.youtube.com/watch?v=' . $videoId;
        $title = $videoId;
        $source = 'youtube';
    }
} elseif ($hasUpload) {
    $err = (int) ($upload['error'] ?? UPLOAD_ERR_NO_FILE);
    if ($err !== UPLOAD_ERR_OK) {
        $map = [
            UPLOAD_ERR_INI_SIZE => 'Video is too large for this server (php upload_max_filesize).',
            UPLOAD_ERR_FORM_SIZE => 'Video is too large.',
            UPLOAD_ERR_PARTIAL => 'Upload was interrupted — try again.',
            UPLOAD_ERR_NO_TMP_DIR => 'Server missing temp upload folder.',
            UPLOAD_ERR_CANT_WRITE => 'Server could not save the upload.',
            UPLOAD_ERR_EXTENSION => 'Upload blocked by a PHP extension.',
        ];
        yt_fail($map[$err] ?? ('Upload failed (code ' . $err . ').'), 400);
    }
    $origName = (string) ($upload['name'] ?? 'tour.mp4');
    $ext = strtolower(pathinfo($origName, PATHINFO_EXTENSION));
    $allowedExt = ['mp4', 'mov', 'webm', 'mkv', 'm4v', 'avi'];
    if (!in_array($ext, $allowedExt, true)) {
        yt_fail('Unsupported file type. Upload mp4, mov, webm, mkv, m4v, or avi.');
    }
    $tmp = (string) ($upload['tmp_name'] ?? '');
    if ($tmp === '' || !is_uploaded_file($tmp)) {
        yt_fail('Invalid uploaded file.', 400);
    }
    $maxBytes = 400 * 1024 * 1024; // 400 MB soft for long tours
    $size = (int) ($upload['size'] ?? 0);
    if ($size <= 0 || $size > $maxBytes) {
        yt_fail('Video must be under 400 MB.', 400);
    }
    $videoId = 'upl_' . substr(bin2hex(random_bytes(4)), 0, 8);
    $title = pathinfo($origName, PATHINFO_FILENAME) ?: $origName;
    $source = 'upload';
} else {
    $videoId = yt_parse_id($url);
    if ($videoId === null) {
        yt_fail('Paste a YouTube URL or upload a video file (mp4, mov, webm…).');
    }
    $watchUrl = 'https://www.youtube.com/watch?v=' . $videoId;
    $title = $videoId;
}

$ytDlp = yt_find_bin('yt-dlp');
$ffmpeg = yt_find_bin('ffmpeg');
$ffprobe = yt_find_bin('ffprobe');
if ($source === 'youtube' && $ytDlp === '') {
    yt_fail('yt-dlp not found. Place it at bin/yt-dlp or install with brew/pip.', 500);
}
if ($ffmpeg === '' || $ffprobe === '') {
    yt_fail('ffmpeg/ffprobe not found. Install: brew install ffmpeg', 500);
}

if (!is_dir(YT_WORK_ROOT) && !mkdir(YT_WORK_ROOT, 0755, true) && !is_dir(YT_WORK_ROOT)) {
    yt_fail('Cannot create work directory.', 500);
}

$jobId = ($source === 'upload' ? 'upl_' : '') . bin2hex(random_bytes(4));
$workDir = YT_WORK_ROOT . '/' . $jobId;
$framesDir = $workDir . '/frames';
$selectedDir = $workDir . '/selected';
if (!mkdir($framesDir, 0755, true) || !mkdir($selectedDir, 0755, true)) {
    yt_fail('Cannot create job directories.', 500);
}

$videoPath = '';
if ($source === 'upload') {
    if ($submissionStoredAbs !== '') {
        $ext = strtolower(pathinfo($submissionStoredAbs, PATHINFO_EXTENSION)) ?: 'mp4';
        $videoPath = $workDir . '/video.' . $ext;
        if (!@copy($submissionStoredAbs, $videoPath)) {
            yt_rm_dir($workDir);
            yt_fail('Could not copy submission video into job folder.', 500);
        }
    } else {
        $ext = strtolower(pathinfo((string) $upload['name'], PATHINFO_EXTENSION));
        $videoPath = $workDir . '/video.' . $ext;
        if (!move_uploaded_file((string) $upload['tmp_name'], $videoPath)) {
            yt_rm_dir($workDir);
            yt_fail('Could not store uploaded video.', 500);
        }
    }
} else {
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

    foreach (glob($workDir . '/*.info.json') ?: [] as $infoPath) {
        $info = json_decode((string) file_get_contents($infoPath), true);
        if (is_array($info) && !empty($info['title'])) {
            $title = (string) $info['title'];
        }
        break;
    }
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
$indices = yt_pick_indoor_indices($allFrames, $want);
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

// Keep selected frames + meta. Drop raw every-2s dump.
// For uploads, keep the source video for playback; for YouTube, drop the large download.
if ($source === 'upload') {
    $keepExt = strtolower(pathinfo($videoPath, PATHINFO_EXTENSION)) ?: 'mp4';
    $keepPath = $workDir . '/source.' . $keepExt;
    if ($videoPath !== $keepPath) {
        @rename($videoPath, $keepPath);
    }
    $resultSourceUrl = YT_PUBLIC_BASE . '/' . rawurlencode($jobId) . '/source.' . rawurlencode($keepExt);
} else {
    @unlink($videoPath);
    $resultSourceUrl = null;
}
foreach ($allFrames as $f) {
    @unlink($f);
}
@rmdir($framesDir);

$result = [
    'ok' => true,
    'job_id' => $jobId,
    'video_id' => $videoId,
    'source' => $source,
    'source_url' => $resultSourceUrl,
    'title' => $title,
    'email' => $email,
    'duration_sec' => (int) round($duration),
    'extracted_every_sec' => YT_FRAME_EVERY_SEC,
    'extracted_count' => $total,
    'selected_count' => count($frames),
    'created_at' => date('c'),
    'frames' => $frames,
];
if ($listingId > 0) {
    $result['listing_id'] = $listingId;
}
file_put_contents($workDir . '/meta.json', json_encode($result, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
file_put_contents($workDir . '/job.json', json_encode([
    'listing_id' => $listingId > 0 ? $listingId : null,
    'job_id' => $jobId,
    'video_id' => $videoId,
    'youtube_url' => $watchUrl,
    'source' => $source,
    'source_url' => $resultSourceUrl,
    'title' => $title,
    'email' => $email,
    'submission_id' => $submissionId > 0 ? $submissionId : null,
], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));

if ($submissionId > 0) {
    try {
        require_once dirname(__DIR__) . '/pdo_connect.php';
        $pdoDone = db_pdo_connect();
        if ($pdoDone instanceof PDO) {
            $pdoDone->prepare(
                'UPDATE house_tour_submissions
                 SET status = \'processed\', job_id = ?, processed_at = NOW(), error_message = NULL
                 WHERE id = ?'
            )->execute([$jobId, $submissionId]);
        }
    } catch (Throwable $e) {
        // Frame extraction already succeeded; admin can still analyze.
    }
}

yt_json_out($result);
