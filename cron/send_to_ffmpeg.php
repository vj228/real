<?php
/**
 * Zillow listings → local FFmpeg slideshow (1080×1920). Same layout as send_to_j2v.php.
 *
 *   php cron/send_to_ffmpeg.php              # 1 pending listing (default)
 *   php cron/send_to_ffmpeg.php --limit=5  # up to 5 pending listings
 *   php cron/send_to_ffmpeg.php --zpid=21611386
 *   php cron/send_to_ffmpeg.php --json
 *
 * Requires: ffmpeg on PATH. Output: videos/{M-D}/{zpid}.mp4, uploaded to yhome.pro.
 */

set_time_limit(0);
ignore_user_abort(true);

require_once dirname(__DIR__) . '/pdo_connect.php';

const SCENE_SEC = 3;
const VIDEO_W = 1080;
const VIDEO_H = 1920;
const VIDEO_DIR = __DIR__ . '/../videos';
const VIDEO_URL_BASE = 'https://yhome.pro/videos';
const FALLBACK_IMAGE = 'https://images.pexels.com/photos/323780/pexels-photo-323780.jpeg';
const MAX_IMAGES = 7;
const VIDEO_CTA_LINES = "CHECK COST & RISK\nBEFORE YOU OFFER\n\nyhome.pro";

function out(string $msg): void
{
    echo $msg . "\n";
}

function hd_url(string $url): string
{
    return preg_match('/-p_[a-z]\.jpg$/i', $url)
        ? preg_replace('/-p_[a-z]\.jpg$/i', '-p_f.jpg', $url)
        : $url;
}

function money(?float $n): string
{
    return $n === null ? '—' : '$' . number_format($n, 0);
}

/** Month-day folder name, e.g. 6-22 */
function video_date_folder(): string
{
    return (int) date('n') . '-' . (int) date('j');
}

function video_public_url(string $dateFolder, string $zpid): string
{
    return VIDEO_URL_BASE . '/' . rawurlencode($dateFolder) . '/' . rawurlencode($zpid) . '.mp4';
}

/** @return array{upload_url: string, token: string} */
function video_upload_config(): array
{
    $path = dirname(__DIR__) . '/video_upload.credentials.php';
    if (!is_readable($path)) {
        throw new RuntimeException(
            'Need video_upload.credentials.php — copy from video_upload.credentials.example.php'
        );
    }
    /** @var array<string, mixed> $cfg */
    $cfg = require $path;
    $url = trim((string) ($cfg['upload_url'] ?? ''));
    $token = trim((string) ($cfg['token'] ?? ''));
    if ($url === '' || $token === '' || $token === 'replace-with-a-long-random-secret') {
        throw new RuntimeException('Set upload_url and token in video_upload.credentials.php');
    }

    return ['upload_url' => $url, 'token' => $token];
}

function upload_video_to_server(string $localPath, string $dateFolder, string $filename): void
{
    if (!is_readable($localPath)) {
        throw new RuntimeException('Video file missing: ' . $localPath);
    }

    $cfg = video_upload_config();
    $ch = curl_init($cfg['upload_url']);
    if ($ch === false) {
        throw new RuntimeException('curl_init failed for video upload');
    }

    $post = [
        'token' => $cfg['token'],
        'folder' => $dateFolder,
        'file' => new CURLFile($localPath, 'video/mp4', $filename),
    ];
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $post,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 600,
        CURLOPT_FOLLOWLOCATION => true,
    ]);

    $body = curl_exec($ch);
    $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);

    if ($body === false) {
        throw new RuntimeException('Upload failed: ' . ($curlError !== '' ? $curlError : 'curl error'));
    }

    /** @var array<string, mixed>|null $parsed */
    $parsed = json_decode((string) $body, true);
    if ($httpCode < 200 || $httpCode >= 300 || !is_array($parsed) || empty($parsed['ok'])) {
        $err = is_array($parsed) ? (string) ($parsed['error'] ?? 'unknown') : trim((string) $body);
        throw new RuntimeException("Upload failed (HTTP {$httpCode}): {$err}");
    }
}

function listing_from_row(array $row): array
{
    $images = json_decode((string) ($row['images_json'] ?? '[]'), true);
    if (!is_array($images)) {
        $images = [];
    }
    if ($images === [] && !empty($row['img_src'])) {
        $images = [(string) $row['img_src']];
    }

    $urls = [];
    $seen = [];
    foreach ($images as $u) {
        if (!is_string($u) || trim($u) === '') {
            continue;
        }
        $u = hd_url(trim($u));
        if (isset($seen[$u])) {
            continue;
        }
        $seen[$u] = true;
        $urls[] = $u;
    }
    if ($urls === []) {
        $urls[] = hd_url(FALLBACK_IMAGE);
    }
    $urls = array_slice($urls, 0, MAX_IMAGES);

    $beds = $row['beds'] !== null ? (float) $row['beds'] : null;
    $baths = $row['baths'] !== null ? (float) $row['baths'] : null;
    $b = $beds === null ? '—' : (string) (fmod($beds, 1.0) === 0.0 ? (int) $beds : $beds);
    $ba = $baths === null ? '—' : (string) (fmod($baths, 1.0) === 0.0 ? (int) $baths : $baths);

    $addr = trim((string) ($row['address'] ?? ''));
    $parts = array_values(array_filter(array_map('trim', explode(',', $addr))));
    if (count($parts) >= 3) {
        $street = strtoupper($parts[0]);
        $cityStateZip = strtoupper($parts[1] . ', ' . implode(', ', array_slice($parts, 2)));
    } elseif (count($parts) === 2) {
        $street = strtoupper($parts[0]);
        $cityStateZip = strtoupper($parts[1]);
    } else {
        $street = strtoupper($addr);
        $cityStateZip = '';
    }

    $overlayLines = [
        strtoupper("{$b} BEDROOM/{$ba} BATHROOM"),
        'LIST PRICE ' . money($row['list_price'] !== null ? (float) $row['list_price'] : null),
        $street,
    ];
    if ($cityStateZip !== '') {
        $overlayLines[] = $cityStateZip;
    }
    $overlayMain = implode("\n", $overlayLines) . "\n\nyhome.pro";
    $overlayLast = count($urls) === 1 ? $overlayMain : VIDEO_CTA_LINES;

    return [
        'zpid' => (string) ($row['zpid'] ?? ''),
        'address' => $addr,
        'image_urls' => $urls,
        'overlay_main' => $overlayMain,
        'overlay_last' => $overlayLast,
    ];
}

function ffmpeg_bin(): string
{
    $bin = trim((string) shell_exec('command -v ffmpeg 2>/dev/null'));
    if ($bin === '' || !is_executable($bin)) {
        throw new RuntimeException('ffmpeg not found. Install: brew install ffmpeg');
    }

    return $bin;
}

function overlay_font(): string
{
    foreach ([
        '/System/Library/Fonts/Supplemental/Arial Bold.ttf',
        '/usr/share/fonts/truetype/dejavu/DejaVuSans-Bold.ttf',
    ] as $path) {
        if (is_readable($path)) {
            return $path;
        }
    }
    throw new RuntimeException('No .ttf font found (need PHP GD + FreeType)');
}

/** Transparent PNG with white outlined text (no ffmpeg drawtext needed). */
function make_overlay_png(string $text, string $dest): void
{
    if (!function_exists('imagettftext')) {
        throw new RuntimeException('PHP GD with FreeType required (imagettftext)');
    }

    $im = imagecreatetruecolor(VIDEO_W, VIDEO_H);
    imagesavealpha($im, true);
    imagefill($im, 0, 0, imagecolorallocatealpha($im, 0, 0, 0, 127));
    $white = imagecolorallocate($im, 255, 255, 255);
    $black = imagecolorallocate($im, 0, 0, 0);
    $font = overlay_font();
    $size = 42;
    $lineH = 96;
    $lines = explode("\n", $text);
    $y = VIDEO_H - 120 - count($lines) * $lineH;

    foreach ($lines as $line) {
        if (trim($line) === '') {
            $y += $lineH;
            continue;
        }
        $box = imagettfbbox($size, 0, $font, $line);
        $x = (int) ((VIDEO_W - abs($box[2] - $box[0])) / 2);
        foreach ([-2, 0, 2] as $dx) {
            foreach ([-2, 0, 2] as $dy) {
                if ($dx || $dy) {
                    imagettftext($im, $size, 0, $x + $dx, $y + $dy, $black, $font, $line);
                }
            }
        }
        imagettftext($im, $size, 0, $x, $y, $white, $font, $line);
        $y += $lineH;
    }

    imagepng($im, $dest);
}

function download_file(string $url, string $dest): void
{
    $fp = fopen($dest, 'wb');
    if ($fp === false) {
        throw new RuntimeException('Cannot write ' . $dest);
    }
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_FILE => $fp,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_TIMEOUT => 120,
        CURLOPT_FAILONERROR => true,
    ]);
    $ok = curl_exec($ch);
    $err = curl_error($ch);
    fclose($fp);
    if (!$ok) {
        @unlink($dest);
        throw new RuntimeException('Download failed: ' . $url . ($err ? " ($err)" : ''));
    }
}

function ffmpeg_run(array $cmd): void
{
    $proc = proc_open($cmd, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    if (!is_resource($proc)) {
        throw new RuntimeException('Failed to start ffmpeg');
    }
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    if (proc_close($proc) !== 0) {
        $tail = implode("\n", array_slice(explode("\n", trim((string) $stderr)), -6));
        throw new RuntimeException("ffmpeg failed:\n" . $tail);
    }
}

function render_listing_video(array $listing): array
{
    $zpid = $listing['zpid'];
    if ($zpid === '') {
        throw new RuntimeException('Missing zpid');
    }

    $dateFolder = video_date_folder();
    if (!is_dir(VIDEO_DIR) && !mkdir(VIDEO_DIR, 0755, true) && !is_dir(VIDEO_DIR)) {
        throw new RuntimeException('Cannot create ' . VIDEO_DIR);
    }
    $outDir = VIDEO_DIR . '/' . $dateFolder;
    if (!is_dir($outDir) && !mkdir($outDir, 0755, true) && !is_dir($outDir)) {
        throw new RuntimeException('Cannot create ' . $outDir);
    }

    $outFile = $outDir . '/' . $zpid . '.mp4';
    $tmp = sys_get_temp_dir() . '/yhome_ffmpeg_' . $zpid . '_' . getmypid();
    if (!mkdir($tmp, 0700, true) && !is_dir($tmp)) {
        throw new RuntimeException('Cannot create temp dir');
    }

    $overlayMainPng = $tmp . '/overlay_main.png';
    $overlayLastPng = $tmp . '/overlay_last.png';
    make_overlay_png($listing['overlay_main'], $overlayMainPng);
    make_overlay_png($listing['overlay_last'], $overlayLastPng);

    $fc = '[0:v]scale=' . VIDEO_W . ':' . VIDEO_H . ':force_original_aspect_ratio=decrease,'
        . 'pad=' . VIDEO_W . ':' . VIDEO_H . ':(ow-iw)/2:(oh-ih)/2:color=black[bg];'
        . '[bg][1:v]overlay=0:0[out]';

    $ffmpeg = ffmpeg_bin();
    $segments = [];
    $imageUrls = $listing['image_urls'];
    $lastIdx = count($imageUrls) - 1;

    try {
        foreach ($imageUrls as $i => $url) {
            $img = $tmp . '/img_' . $i . '.jpg';
            download_file($url, $img);
            $seg = $tmp . '/seg_' . $i . '.mp4';
            $overlayPng = ($i === $lastIdx) ? $overlayLastPng : $overlayMainPng;
            ffmpeg_run([
                $ffmpeg, '-y', '-loop', '1', '-i', $img, '-loop', '1', '-i', $overlayPng,
                '-t', (string) SCENE_SEC, '-filter_complex', $fc, '-map', '[out]',
                '-c:v', 'libx264', '-pix_fmt', 'yuv420p', '-r', '30', $seg,
            ]);
            $segments[] = $seg;
        }

        $listFile = $tmp . '/list.txt';
        $list = '';
        foreach ($segments as $seg) {
            $list .= "file '" . str_replace("'", "'\\''", $seg) . "'\n";
        }
        file_put_contents($listFile, $list);

        ffmpeg_run([$ffmpeg, '-y', '-f', 'concat', '-safe', '0', '-i', $listFile, '-c', 'copy', $outFile]);
    } finally {
        foreach (glob($tmp . '/*') ?: [] as $f) {
            @unlink($f);
        }
        @rmdir($tmp);
    }

    return [
        'local_path' => $outFile,
        'date_folder' => $dateFolder,
        'public_url' => video_public_url($dateFolder, $zpid),
    ];
}

function mark_done(string $zpid, string $fileUrl): bool
{
    $pdo = db_pdo_connect();
    if ($pdo === null) {
        return false;
    }
    $pdo->prepare(
        'UPDATE zillow_sale_listings SET sent_to_j2v = 1, sent_to_j2v_at = NOW(), j2v_file_url = ?, j2v_file_type = ? WHERE zpid = ?'
    )->execute([$fileUrl, 'mp4', $zpid]);

    return true;
}

// --- run ---
$jsonOnly = in_array('--json', $argv ?? [], true);
$zpidArg = null;
$limit = 1;
foreach ($argv ?? [] as $arg) {
    if (str_starts_with((string) $arg, '--zpid=')) {
        $zpidArg = substr((string) $arg, 7);
    } elseif (str_starts_with((string) $arg, '--limit=')) {
        $limit = max(1, (int) substr((string) $arg, 8));
    }
}

$pdo = db_pdo_connect();
if ($pdo === null) {
    $err = db_pdo_last_error();
    fwrite(STDERR, ($err === 'db.credentials.php not found' ? 'Need db.credentials.php' : 'Database connection failed')
        . ($err && $err !== 'db.credentials.php not found' ? ": {$err}" : '') . "\n");
    exit(1);
}

if ($zpidArg !== null && $zpidArg !== '') {
    $stmt = $pdo->prepare('SELECT * FROM zillow_sale_listings WHERE zpid = ? LIMIT 1');
    $stmt->execute([$zpidArg]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
} else {
    $stmt = $pdo->prepare(
        'SELECT * FROM zillow_sale_listings WHERE sent_to_j2v = 0 ORDER BY created_at DESC LIMIT ' . $limit
    );
    $stmt->execute();
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
}
if ($rows === []) {
    out($zpidArg ? "No listing for zpid={$zpidArg}" : 'No pending listings (sent_to_j2v = 0).');
    exit(0);
}

$listings = array_map('listing_from_row', $rows);

if ($jsonOnly) {
    echo json_encode(count($listings) === 1 ? $listings[0] : $listings, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n";
    exit(0);
}

$failed = 0;
foreach ($listings as $listing) {
    try {
        $rendered = render_listing_video($listing);
        $localPath = $rendered['local_path'];
        $dateFolder = $rendered['date_folder'];
        $url = $rendered['public_url'];
        $n = count($listing['image_urls']);

        upload_video_to_server($localPath, $dateFolder, $listing['zpid'] . '.mp4');

        out("FFmpeg: {$listing['address']}");
        out("{$n} image(s), " . ($n * SCENE_SEC) . "s → videos/{$dateFolder}/{$listing['zpid']}.mp4");
        if (!mark_done($listing['zpid'], $url)) {
            out("DB update failed. URL: {$url}");
            $failed++;
            continue;
        }
        out("Uploaded: {$url}");
    } catch (Throwable $e) {
        fwrite(STDERR, "Video failed ({$listing['zpid']}): " . $e->getMessage() . "\n");
        $failed++;
    }
}

if ($failed > 0) {
    exit(1);
}
