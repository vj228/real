<?php
/**
 * Zillow listings → J2V vertical videos (1080×1920). DB: zillow_sale_listings.
 *
 *   php cron/send_to_j2v.php                    # render 1 pending listing (sent_to_j2v = 0)
 *   php cron/send_to_j2v.php --zpid=20883641    # re-render one listing (ignores sent_to_j2v)
 *   php cron/send_to_j2v.php --json             # print prompts only, no render
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
const J2V_HOLD_SEC = 1; // pause after text animation finishes
const J2V_TEXT_COLOR = '#ff0000';
const J2V_TEXT_STYLE = '001'; // 008 only has 3 bands and strips \n inside a band (breaks 4+ lines)

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

function j2v_city_from_address(string $address): string
{
    $parts = array_map('trim', explode(',', $address));

    return $parts[1] ?? ($parts[0] ?? 'this area');
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

    return [
        'listing_id' => (string) ($row['zpid'] ?? $row['id'] ?? ''),
        'zpid' => (string) ($row['zpid'] ?? ''),
        'address' => (string) ($row['address'] ?? ''),
        'list_price' => $row['list_price'] !== null ? (float) $row['list_price'] : null,
        'zestimate' => $row['zestimate'] !== null ? (float) $row['zestimate'] : null,
        'price_vs_zestimate_pct' => $row['price_vs_zestimate_pct'] !== null ? (float) $row['price_vs_zestimate_pct'] : null,
        'price_per_sqft' => $row['price_per_sqft'] !== null ? (float) $row['price_per_sqft'] : null,
        'beds' => $row['beds'] !== null ? (float) $row['beds'] : null,
        'baths' => $row['baths'] !== null ? (float) $row['baths'] : null,
        'sqft' => $row['sqft'] !== null ? (int) $row['sqft'] : null,
        'days_on_zillow' => $row['days_on_zillow'] !== null ? (int) $row['days_on_zillow'] : null,
        'img_src' => !empty($row['img_src']) ? (string) $row['img_src'] : null,
        'image_urls' => $images,
    ];
}

function generateVideoPrompt(array $listing): array
{
    $city = j2v_city_from_address($listing['address']);
    $pct = $listing['price_vs_zestimate_pct'];
    $ppsf = $listing['price_per_sqft'];
    $days = $listing['days_on_zillow'];

    if ($pct === null) {
        $angle = 'Beautiful home, but the monthly cost matters more than the listing price.';
    } elseif ($pct > 10) {
        $angle = 'This home may be overpriced.';
    } elseif ($pct >= 3) {
        $angle = 'Looks close to value, but still check the real cost.';
    } elseif ($pct >= -3) {
        $angle = 'Price looks reasonable, but can you actually afford it?';
    } else {
        $angle = 'Price looks reasonable, but can you actually afford it?';
    }

    $hook = 'Before you make an offer on this home, check the real cost.';

    $fresh = ($days !== null && $days <= 5) ? 'Fresh listing in ' . $city : 'Listing in ' . $city;
    $specs = trim(sprintf(
        '%s beds • %s baths • %s sqft',
        $listing['beds'] ?? '—',
        $listing['baths'] ?? '—',
        $listing['sqft'] ?? '—'
    ));

    $overlays = [
        $hook,
        $fresh,
        $specs,
        'List price: ' . j2v_money($listing['list_price']),
    ];

    if ($listing['zestimate'] !== null) {
        $pctLabel = $pct === null ? '' : sprintf('Listed price is %s%% above Zestimate', ltrim((string) round($pct, 1), '+-'));
        $overlays[] = ' Zestimate: ' . j2v_money($listing['zestimate']);
        if ($pctLabel !== '') {
            $overlays[] = $pctLabel;
        }
    }

    if ($ppsf !== null) {
        $line = '$' . number_format($ppsf, 0) . '/sqft';
        $overlays[] = $line;
        if ($ppsf > 900) {
            $overlays[] = 'High cost per sqft — check carefully';
        }
    }

    $overlays[] = 'Zillow shows the home.';
    $overlays[] = 'yhome.pro shows the real monthly cost + risk.';
    $overlays[] = 'Before you offer, check cost & risk.';
    $overlays[] = 'yhome.pro';

    $voiceParts = [
        "This {$city} home",
        ($days !== null && $days <= 5) ? 'just hit the market' : 'is on the market',
        'and it looks beautiful.',
        $angle,
        'Before making an offer, the list price alone is not enough.',
        'You need the real monthly cost, taxes, insurance, and whether this home puts too much pressure on your budget.',
        'Zillow shows the listing.',
        'yhome.pro helps you decide.',
        'Check cost and risk before you offer at yhome.pro.',
    ];
    $voiceover = implode(' ', $voiceParts);

    $cta = 'Check Cost & Risk Before You Offer';

    $priceLines = 'List price: ' . j2v_money($listing['list_price']);
    if ($listing['zestimate'] !== null) {
        $priceLines .= "\n" . 'Zestimate: ' . j2v_money($listing['zestimate']);
    }
    if ($pct !== null) {
        $priceLines .= "\n" . sprintf('Listed price is %s%% above Zestimate', ltrim((string) round($pct, 1), '+-'));
    }

    $v2j_prompt = "Hook: {$hook}\nAngle: {$angle}\n\nScenes:\n"
        . "1. Intro (address): {$fresh} / {$specs}\n"
        . "2. List price + Zestimate\n"
        . "3. Zillow vs yhome.pro\n"
        . "4. CTA (address): {$cta}\nyhome.pro\n\nVoiceover:\n{$voiceover}";

    $img = $listing['image_urls'];
    $pick = static fn (int $i) => j2v_hd_photo_url($img[$i] ?? ($listing['img_src'] ?? J2V_FALLBACK_IMAGE));

    $scenes = [
        [
            'url' => $pick(0),
            'show_address' => true,
            'base_duration' => 8,
            'hold_sec' => 0,
            'lines' => $hook . "\n" . $fresh . "\n" . $specs,
        ],
        [
            'url' => $pick(1),
            'show_address' => false,
            'base_duration' => 5,
            'lines' => $priceLines,
        ],
        [
            'url' => $pick(2),
            'show_address' => false,
            'base_duration' => 5,
            'lines' => "Zillow shows the home.\nyhome.pro shows the real monthly cost + risk.",
        ],
        [
            'url' => $pick(0),
            'show_address' => true,
            'base_duration' => 5,
            'lines' => $cta . "\n" . 'Go To -> yhome.pro',
        ],
    ];

    return [
        'listing_id' => $listing['listing_id'],
        'address' => $listing['address'],
        'hook' => $hook,
        'angle' => $angle,
        'overlays' => $overlays,
        'voiceover' => $voiceover,
        'cta' => $cta,
        'v2j_prompt' => $v2j_prompt,
        'image_urls' => $listing['image_urls'],
        'scenes' => $scenes,
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
    $maxLoops = 360; // ~30 min per video
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

function j2v_scene_text(bool $showAddress, string $address, string $lines): string
{
    $lines = j2v_sanitize_text($lines);
    if ($showAddress) {
        $addr = j2v_sanitize_text($address);

        return $lines !== '' ? $addr . "\n" . $lines : $addr;
    }

    return $lines;
}

function j2v_render_prompt(array $prompt): ?string
{
    $movie = new Movie();
    $movie->setAPIKey(J2V_API_KEY);
    $movie->width = 1080;
    $movie->height = 1920;
    $movie->quality = 'high';
    $movie->draft = false;

    foreach ($prompt['scenes'] as $sceneData) {
        $base = (int) ($sceneData['base_duration'] ?? $sceneData['duration'] ?? 5);
        $hold = (int) ($sceneData['hold_sec'] ?? J2V_HOLD_SEC);
        $dur = $base + $hold;
        $lines = (string) ($sceneData['lines'] ?? '');
        if ($lines === '') {
            $lines = (string) ($prompt['hook'] ?? '');
        }
        $showAddress = !empty($sceneData['show_address']);

        $scene = new Scene();
        $scene->duration = $dur;
        $scene->addElement([
            'type' => 'image',
            'src' => j2v_hd_photo_url((string) $sceneData['url']),
            'duration' => $dur,
            'resize' => 'cover',
            'cache' => false,
        ]);
        $textSettings = array_merge(
            [
                'color' => J2V_TEXT_COLOR,
                'font-size' => '5vw',
                'font-weight' => '700',
                'line-height' => '1.45',
                'text-align' => 'center',
                'vertical-position' => 'center',
                'text-shadow' => '2px 2px 6px rgba(0,0,0,0.85)',
            ],
            (array) ($sceneData['text_settings'] ?? [])
        );
        $scene->addElement([
            'type' => 'text',
            'style' => J2V_TEXT_STYLE,
            'text' => j2v_scene_text($showAddress, (string) $prompt['address'], $lines),
            'duration' => $dur,
            'width' => 1000,
            'cache' => false,
            'settings' => $textSettings,
        ]);
        $movie->addScene($scene);
    }

    $render = $movie->render();
    echo 'J2V project: ' . ($render['project'] ?? '?') . " — {$prompt['address']}\n";

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
        'SELECT * FROM zillow_sale_listings WHERE sent_to_j2v = 0 ORDER BY created_at ASC LIMIT 1'
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
    $url = j2v_render_prompt($prompt);
    if ($url === null) {
        echo "No video URL for {$prompt['listing_id']}\n";
        continue;
    }
    if (!j2v_mark_done((string) $prompt['listing_id'], $url)) {
        echo "Video rendered but DB not updated. Save URL manually:\n{$url}\n\n";
        continue;
    }
    echo "Saved: {$url}\n\n";
}

echo 'Done. Processed ' . count($prompts) . " listing(s).\n";
