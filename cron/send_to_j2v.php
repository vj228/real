<?php
/**
 * Zillow listings → J2V vertical slideshow videos (1080×1920).
 *
 *   php cron/send_to_j2v.php                    # render 1 pending listing (sent_to_j2v = 0)
 *   php cron/send_to_j2v.php --zpid=20883641    # re-render one listing (ignores sent_to_j2v)
 *   php cron/send_to_j2v.php --json             # print prompts only, no render
 *   php cron/send_to_j2v.php --draft             # low-quota test render (does not mark sent_to_j2v)
 *   php cron/send_to_j2v.php --draft --zpid=...  # draft test for one listing
 *
 * After editing text, you must re-render (script skips sent rows). Use --zpid or:
 *   UPDATE zillow_sale_listings SET sent_to_j2v=0, j2v_file_url=NULL WHERE zpid='...';
 */

set_time_limit(0);
ini_set('max_execution_time', '0');
ini_set('default_socket_timeout', '600');
ignore_user_abort(true);

require __DIR__ . '/../vendor/autoload.php';
require_once dirname(__DIR__) . '/pdo_connect.php';

use JSON2Video\Movie;
use JSON2Video\Scene;

const J2V_API_KEY = 'HfMvBu1lEY9MVWfVObFQqFLd48WbCHFKcyvCZhme';
const J2V_FALLBACK_IMAGE = 'https://images.pexels.com/photos/323780/pexels-photo-323780.jpeg';
const J2V_SCENE_SEC = 3;

/** Zillow listing thumbs (-p_e) are tiny; J2V needs a larger file for vertical video. */
function j2v_hd_photo_url(string $url): string
{
    if (preg_match('/-p_[a-z]\.jpg$/i', $url)) {
        return preg_replace('/-p_[a-z]\.jpg$/i', '-p_f.jpg', $url);
    }

    return $url;
}

function j2v_sanitize_text(string $s): string
{
    return str_replace(["\u{2022}", '—', '’'], [' | ', '-', "'"], $s);
}

function j2v_money(?float $n): string
{
    return $n === null ? '—' : '$' . number_format($n, 0);
}

function j2v_bed_bath_label(?float $beds, ?float $baths): string
{
    $b = $beds === null ? '—' : (string) (fmod($beds, 1.0) === 0.0 ? (int) $beds : $beds);
    $ba = $baths === null ? '—' : (string) (fmod($baths, 1.0) === 0.0 ? (int) $baths : $baths);

    return strtoupper("{$b} BEDROOM/{$ba} BATHROOM");
}

/** @return array{0: string, 1: string} street line, city/state/zip line */
function j2v_address_lines(string $address): array
{
    $address = j2v_sanitize_text(trim($address));
    $parts = array_values(array_filter(array_map('trim', explode(',', $address)), static fn ($p) => $p !== ''));

    if (count($parts) >= 3) {
        $street = $parts[0];
        $cityStateZip = $parts[1] . ', ' . implode(', ', array_slice($parts, 2));

        return [strtoupper($street), strtoupper($cityStateZip)];
    }
    if (count($parts) === 2) {
        return [strtoupper($parts[0]), strtoupper($parts[1])];
    }

    return [strtoupper($address), ''];
}

function j2v_listing_from_row(array $row): array
{
    $images = json_decode((string) ($row['images_json'] ?? '[]'), true);
    if (!is_array($images)) {
        $images = [];
    }
    if ($images === [] && !empty($row['img_src'])) {
        $images = [(string) $row['img_src']];
    }

    $hd = [];
    $seen = [];
    foreach ($images as $url) {
        if (!is_string($url) || trim($url) === '') {
            continue;
        }
        $url = j2v_hd_photo_url(trim($url));
        if (isset($seen[$url])) {
            continue;
        }
        $seen[$url] = true;
        $hd[] = $url;
    }
    if ($hd === []) {
        $hd[] = j2v_hd_photo_url(J2V_FALLBACK_IMAGE);
    }

    return [
        'listing_id' => (string) ($row['zpid'] ?? $row['id'] ?? ''),
        'zpid' => (string) ($row['zpid'] ?? ''),
        'address' => (string) ($row['address'] ?? ''),
        'list_price' => $row['list_price'] !== null ? (float) $row['list_price'] : null,
        'beds' => $row['beds'] !== null ? (float) $row['beds'] : null,
        'baths' => $row['baths'] !== null ? (float) $row['baths'] : null,
        'image_urls' => $hd,
    ];
}

function j2v_overlay_text(array $listing): string
{
    [$street, $cityLine] = j2v_address_lines((string) $listing['address']);
    $address = $cityLine !== '' ? $street . ', ' . $cityLine : $street;

    return implode("\n", [
        j2v_bed_bath_label($listing['beds'] ?? null, $listing['baths'] ?? null),
        'LIST PRICE ' . j2v_money($listing['list_price'] ?? null),
        $address,
        'GO TO YHOME.PRO',
    ]);
}

function generateVideoPrompt(array $listing): array
{
    $overlay = j2v_overlay_text($listing);
    $imageCount = count($listing['image_urls']);
    $duration = $imageCount * J2V_SCENE_SEC;

    return [
        'listing_id' => $listing['listing_id'],
        'address' => $listing['address'],
        'overlay_text' => $overlay,
        'image_urls' => $listing['image_urls'],
        'scene_duration' => J2V_SCENE_SEC,
        'total_duration' => $duration,
    ];
}

/** @param list<array<string, mixed>> $listings */
function generateAllVideoPrompts(array $listings): array
{
    $out = [];
    foreach ($listings as $row) {
        if (!is_array($row)) {
            continue;
        }
        $out[] = generateVideoPrompt(j2v_listing_from_row($row));
    }

    return $out;
}

function j2v_wait_for_render(Movie $movie): array
{
    $delay = 5;
    $maxLoops = 360;
    $lastKey = '';

    for ($i = 0; $i < $maxLoops; $i++) {
        $response = $movie->getStatus();
        if (!$response || empty($response['success']) || empty($response['movie'])) {
            throw new RuntimeException('Invalid J2V status response');
        }

        $status = $response['movie'];
        $key = ($status['status'] ?? '') . '|' . ($status['message'] ?? '');
        if ($key !== $lastKey) {
            $lastKey = $key;
            $movie->printStatus($status, $response['remaining_quota'] ?? []);
        }

        if (($status['status'] ?? '') === 'done') {
            return $response;
        }
        if (($status['status'] ?? '') === 'error') {
            throw new RuntimeException((string) ($status['message'] ?? 'J2V render error'));
        }

        sleep($delay);
    }

    throw new RuntimeException('J2V render timed out after 30 minutes');
}

function j2v_text_settings(): array
{
    return [
        'color' => '#FFFFFF',
        'font-size' => '5.5vw',
        'font-weight' => '900',
        'font-family' => 'Anton',
        'text-align' => 'center',
        'vertical-position' => 'bottom',
        'horizontal-position' => 'center',
        'line-height' => '1.0',
        'text-transform' => 'uppercase',
        'margin' => '0 0 8vh 0',
        'text-shadow' => '3px 3px 0 #000, -3px 3px 0 #000, 3px -3px 0 #000, -3px -3px 0 #000, 0 4px 10px rgba(0,0,0,0.9)',
    ];
}

function j2v_render_prompt(array $prompt, bool $draft = false): ?string
{
    $images = $prompt['image_urls'] ?? [];
    if ($images === []) {
        $images = [j2v_hd_photo_url(J2V_FALLBACK_IMAGE)];
    }

    $dur = (int) ($prompt['scene_duration'] ?? J2V_SCENE_SEC);
    $overlay = (string) ($prompt['overlay_text'] ?? '');
    $textSettings = j2v_text_settings();

    $movie = new Movie();
    $movie->setAPIKey(J2V_API_KEY);
    $movie->width = 1080;
    $movie->height = 1920;
    $movie->quality = 'high';
    $movie->draft = $draft;

    foreach ($images as $i => $url) {
        $scene = new Scene();
        $scene->duration = $dur;
        $scene->background_color = '#000000';
        if ($i > 0) {
            $scene->transition = ['style' => 'fade', 'duration' => 0.5];
        }

        $scene->addElement([
            'type' => 'image',
            'src' => (string) $url,
            'duration' => $dur,
            'resize' => 'contain',
            'cache' => false,
        ]);
        $scene->addElement([
            'type' => 'text',
            'style' => '001',
            'text' => $overlay,
            'duration' => $dur,
            'width' => 1000,
            'cache' => false,
            'settings' => $textSettings,
        ]);
        $movie->addScene($scene);
    }

    $render = $movie->render();
    $mode = $draft ? ' [draft]' : '';
    echo 'J2V project: ' . ($render['project'] ?? '?') . $mode . " — {$prompt['address']}\n";
    $totalDur = count($images) * $dur;
    echo count($images) . ' image(s), ' . $totalDur . "s total\n";

    try {
        $result = j2v_wait_for_render($movie);
    } catch (Throwable $e) {
        echo 'J2V failed: ' . $e->getMessage() . "\n";

        return null;
    }

    $url = $result['movie']['url'] ?? null;

    return is_string($url) && $url !== '' ? $url : null;
}

function j2v_mark_done(string $zpid, string $fileUrl): bool
{
    $pdo = db_pdo_connect();
    if ($pdo === null) {
        echo "DB update failed: no connection\n";

        return false;
    }
    $type = 'mp4';
    if (preg_match('/\.([a-z0-9]{2,5})$/i', parse_url($fileUrl, PHP_URL_PATH) ?? '', $m)) {
        $type = strtolower($m[1]);
    }
    $pdo->prepare(
        'UPDATE zillow_sale_listings SET sent_to_j2v = 1, sent_to_j2v_at = NOW(), j2v_file_url = ?, j2v_file_type = ? WHERE zpid = ?'
    )->execute([$fileUrl, $type, $zpid]);

    return true;
}

// --- run ---
$jsonOnly = in_array('--json', $argv ?? [], true);
$draftMode = in_array('--draft', $argv ?? [], true);
$zpidArg = null;
foreach ($argv ?? [] as $arg) {
    if (str_starts_with((string) $arg, '--zpid=')) {
        $zpidArg = substr((string) $arg, 7);
    }
}
$pdo = db_pdo_connect();
if ($pdo === null) {
    fwrite(STDERR, "Need db.credentials.php\n");
    exit(1);
}

if ($zpidArg !== null && $zpidArg !== '') {
    $stmt = $pdo->prepare('SELECT * FROM zillow_sale_listings WHERE zpid = ? LIMIT 1');
    $stmt->execute([$zpidArg]);
} else {
    $stmt = $pdo->prepare(
        'SELECT * FROM zillow_sale_listings WHERE sent_to_j2v = 0 ORDER BY created_at DESC LIMIT 1'
    );
    $stmt->execute();
}
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

if ($rows === []) {
    if ($zpidArg !== null && $zpidArg !== '') {
        echo "No listing found for zpid={$zpidArg}\n";
    } else {
        echo "No pending listings (sent_to_j2v = 0). Run cron/rapid.php first, or use --zpid=... to re-render.\n";
    }
    exit(0);
}

$prompts = generateAllVideoPrompts($rows);

if ($jsonOnly) {
    echo json_encode($prompts, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n";
    exit(0);
}

foreach ($prompts as $prompt) {
    $url = j2v_render_prompt($prompt, $draftMode);
    if ($url === null) {
        echo "No video URL for {$prompt['listing_id']}\n";
        continue;
    }
    if ($draftMode) {
        echo "Draft preview (not saved to DB): {$url}\n\n";
        continue;
    }
    if (!j2v_mark_done((string) $prompt['listing_id'], $url)) {
        echo "Video rendered but DB not updated. Save URL manually:\n{$url}\n\n";
        continue;
    }
    echo "Saved: {$url}\n\n";
}

echo 'Done. Processed ' . count($prompts) . " listing(s).\n";
