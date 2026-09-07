<?php

declare(strict_types=1);

/**
 * Load a Zillow listing + latest AI analysis by listing id.
 * GET /api/listing_analysis.php?id=1
 */

header('Content-Type: application/json; charset=utf-8');

const YAI_WORK_ROOT = __DIR__ . '/../yhome_ai';
const YAI_PUBLIC_BASE = '/yhome_ai';

function la_out(array $payload, int $code = 200): void
{
    http_response_code($code);
    echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

require_once dirname(__DIR__) . '/pdo_connect.php';

$id = isset($_GET['id']) ? (int) $_GET['id'] : 0;
if ($id <= 0) {
    la_out(['ok' => false, 'error' => 'Missing or invalid listing id'], 400);
}

$pdo = db_pdo_connect();
if (!$pdo instanceof PDO) {
    la_out(['ok' => false, 'error' => 'DB unavailable: ' . (db_pdo_last_error() ?? '')], 500);
}

$stmt = $pdo->prepare(
    'SELECT id, address, detail_url, list_price, zestimate, price_vs_zestimate_pct,
            price_per_sqft, beds, baths, sqft, days_on_zillow, img_src, images_json, zpid, search_query
     FROM zillow_sale_listings WHERE id = ? LIMIT 1'
);
$stmt->execute([$id]);
$listing = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$listing) {
    la_out(['ok' => false, 'error' => 'Listing not found: ' . $id], 404);
}

$listingOut = [
    'id' => (int) $listing['id'],
    'address' => (string) $listing['address'],
    'detail_url' => $listing['detail_url'] !== null ? (string) $listing['detail_url'] : null,
    'list_price' => $listing['list_price'] !== null ? (float) $listing['list_price'] : null,
    'zestimate' => $listing['zestimate'] !== null ? (float) $listing['zestimate'] : null,
    'price_vs_zestimate_pct' => $listing['price_vs_zestimate_pct'] !== null
        ? (float) $listing['price_vs_zestimate_pct'] : null,
    'price_per_sqft' => $listing['price_per_sqft'] !== null ? (float) $listing['price_per_sqft'] : null,
    'beds' => $listing['beds'] !== null ? (float) $listing['beds'] : null,
    'baths' => $listing['baths'] !== null ? (float) $listing['baths'] : null,
    'sqft' => $listing['sqft'] !== null ? (int) $listing['sqft'] : null,
    'days_on_zillow' => $listing['days_on_zillow'] !== null ? (int) $listing['days_on_zillow'] : null,
    'img_src' => $listing['img_src'] !== null ? (string) $listing['img_src'] : null,
    'zpid' => $listing['zpid'] !== null ? (string) $listing['zpid'] : null,
    'search_query' => $listing['search_query'] !== null ? (string) $listing['search_query'] : null,
];

$aStmt = $pdo->prepare(
    'SELECT id, listing_id, job_id, youtube_url, video_id, video_title, model,
            images_used, total_low, total_high, rooms_json, gemini_raw_json, analyzed_at
     FROM ai_analyses
     WHERE listing_id = ?
     ORDER BY analyzed_at DESC, id DESC
     LIMIT 1'
);
$aStmt->execute([$id]);
$analysisRow = $aStmt->fetch(PDO::FETCH_ASSOC);

$analysis = null;
$jobId = null;
$frames = [];
if ($analysisRow) {
    $jobId = (string) $analysisRow['job_id'];
    $payload = json_decode((string) $analysisRow['rooms_json'], true);
    if (!is_array($payload)) {
        $payload = [];
    }
    // v2 envelope {rooms, overall_score, ...} or legacy flat room list
    $isV2 = isset($payload['rooms']) && is_array($payload['rooms']);
    if ($isV2) {
        $rooms = $payload['rooms'];
        $overallScore = isset($payload['overall_score']) ? (int) $payload['overall_score'] : null;
        $overallLabel = (string) ($payload['overall_label'] ?? '');
        $requiredLow = (int) ($payload['required_low'] ?? 0);
        $requiredHigh = (int) ($payload['required_high'] ?? 0);
        $recommendedLow = (int) ($payload['recommended_low'] ?? 0);
        $recommendedHigh = (int) ($payload['recommended_high'] ?? 0);
        $optionalLow = (int) ($payload['optional_low'] ?? 0);
        $optionalHigh = (int) ($payload['optional_high'] ?? 0);
        $disclaimer = (string) ($payload['disclaimer'] ?? '');
    } else {
        $rooms = is_array($payload) ? $payload : [];
        $overallScore = null;
        $overallLabel = '';
        $requiredLow = null;
        $requiredHigh = null;
        $recommendedLow = null;
        $recommendedHigh = null;
        $optionalLow = null;
        $optionalHigh = null;
        $disclaimer = '';
    }
    $geminiRaw = json_decode((string) ($analysisRow['gemini_raw_json'] ?? ''), true);
    $analysis = [
        'analysis_db_id' => (int) $analysisRow['id'],
        'listing_id' => (int) $analysisRow['listing_id'],
        'job_id' => $jobId,
        'youtube_url' => $analysisRow['youtube_url'],
        'video_id' => $analysisRow['video_id'],
        'video_title' => $analysisRow['video_title'],
        'model' => $analysisRow['model'],
        'images_used' => (int) ($analysisRow['images_used'] ?? 0),
        'overall_score' => $overallScore,
        'overall_label' => $overallLabel,
        'required_low' => $requiredLow,
        'required_high' => $requiredHigh,
        'recommended_low' => $recommendedLow,
        'recommended_high' => $recommendedHigh,
        'optional_low' => $optionalLow,
        'optional_high' => $optionalHigh,
        'total_low' => (int) $analysisRow['total_low'],
        'total_high' => (int) $analysisRow['total_high'],
        'disclaimer' => $disclaimer !== '' ? $disclaimer
            : 'AI estimate based on visible conditions in the provided images. Hidden plumbing, electrical, structural, HVAC, roofing, moisture, mold, foundation and other concealed conditions are not included. Actual contractor pricing may vary.',
        'rooms' => $rooms,
        'analyzed_at' => $analysisRow['analyzed_at'],
        'gemini' => is_array($geminiRaw) ? $geminiRaw : null,
        'source' => 'database',
    ];

    // Prefer frames from analysis images; also load full job gallery if folder exists
    $selectedDir = YAI_WORK_ROOT . '/' . $jobId . '/selected';
    if (is_dir($selectedDir)) {
        $files = glob($selectedDir . '/t*.jpg') ?: [];
        natcasesort($files);
        foreach (array_values($files) as $path) {
            $base = basename($path);
            if (!preg_match('/^t(\d+)\.jpg$/', $base, $m)) {
                continue;
            }
            $frames[] = [
                'time_sec' => (int) $m[1],
                'url' => YAI_PUBLIC_BASE . '/' . rawurlencode($jobId) . '/selected/' . rawurlencode($base),
            ];
        }
    }
}

la_out([
    'ok' => true,
    'listing_id' => $id,
    'listing' => $listingOut,
    'job_id' => $jobId,
    'frames' => $frames,
    'analysis' => $analysis,
]);
