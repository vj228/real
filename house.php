<?php

declare(strict_types=1);

/**
 * Public listing landing: house info + renovation estimate + video CTA.
 * Admin generation stays on analysis.php.
 */

require_once __DIR__ . '/pdo_connect.php';
require_once __DIR__ . '/config/renovation_pricing.php';

function house_h(?string $s): string
{
    return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
}

function house_money($n): string
{
    if ($n === null || $n === '') {
        return '—';
    }

    return '$' . number_format((float) $n, 0);
}

function house_score_tone(int $score): string
{
    if ($score >= 80) {
        return 'green';
    }
    if ($score >= 60) {
        return 'amber';
    }
    if ($score >= 40) {
        return 'orange';
    }

    return 'red';
}

/** @return string|null 11-char YouTube id */
function house_youtube_id(?string $url): ?string
{
    $url = trim((string) $url);
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

$id = isset($_GET['id']) ? (int) $_GET['id'] : 0;
if ($id <= 0) {
    http_response_code(400);
    echo 'Missing listing id. Use /house.php?id=1';
    exit;
}

$pdo = db_pdo_connect();
if (!$pdo instanceof PDO) {
    http_response_code(500);
    echo 'Database unavailable.';
    exit;
}

$stmt = $pdo->prepare(
    'SELECT id, address, detail_url, list_price, zestimate, price_vs_zestimate_pct,
            price_per_sqft, beds, baths, sqft, days_on_zillow, img_src, zpid, search_query
     FROM zillow_sale_listings WHERE id = ? LIMIT 1'
);
$stmt->execute([$id]);
$listing = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$listing) {
    http_response_code(404);
    echo 'Listing not found.';
    exit;
}

$aStmt = $pdo->prepare(
    'SELECT id, job_id, youtube_url, video_title, model, images_used,
            total_low, total_high, rooms_json, analyzed_at
     FROM ai_analyses
     WHERE listing_id = ?
     ORDER BY analyzed_at ASC, id ASC'
);
$aStmt->execute([$id]);
$analysisRows = $aStmt->fetchAll(PDO::FETCH_ASSOC);

$tourVideos = [];
foreach ($analysisRows as $i => $row) {
    $num = $i + 1;
    $jobId = trim((string) ($row['job_id'] ?? ''));
    $ytId = house_youtube_id(isset($row['youtube_url']) ? (string) $row['youtube_url'] : null);
    $localVideoUrl = null;
    if ($jobId !== '' && preg_match('/^[A-Za-z0-9_-]+$/', $jobId)) {
        foreach (['mp4', 'mov', 'webm', 'mkv', 'm4v', 'avi'] as $ext) {
            $candidate = __DIR__ . '/yhome_ai/' . $jobId . '/source.' . $ext;
            if (is_readable($candidate)) {
                $localVideoUrl = '/yhome_ai/' . rawurlencode($jobId) . '/source.' . $ext;
                break;
            }
        }
    }
    if ($ytId === null && $localVideoUrl === null) {
        // Still list the slot so numbering stays stable even if media is missing.
        $tourVideos[] = [
            'number' => $num,
            'analysis_id' => (int) $row['id'],
            'youtube_id' => null,
            'local_url' => null,
            'analyzed_at' => $row['analyzed_at'],
        ];
        continue;
    }
    $tourVideos[] = [
        'number' => $num,
        'analysis_id' => (int) $row['id'],
        'youtube_id' => $ytId,
        'local_url' => $localVideoUrl,
        'analyzed_at' => $row['analyzed_at'],
    ];
}

$analysisRow = $analysisRows !== [] ? $analysisRows[count($analysisRows) - 1] : false;
$estimateVideoNumber = $tourVideos !== [] ? (int) $tourVideos[count($tourVideos) - 1]['number'] : 1;

$analysis = null;
if ($analysisRow) {
    $payload = json_decode((string) $analysisRow['rooms_json'], true);
    if (!is_array($payload)) {
        $payload = [];
    }
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
        $rooms = $payload;
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
    if ($disclaimer === '') {
        $disclaimer = 'AI estimate based on visible conditions in the provided images. Hidden plumbing, electrical, structural, HVAC, roofing, moisture, mold, foundation and other concealed conditions are not included. Actual contractor pricing may vary.';
    }
    $analysis = [
        'rooms' => $rooms,
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
        'disclaimer' => $disclaimer,
        'job_id' => (string) $analysisRow['job_id'],
        'youtube_url' => $analysisRow['youtube_url'],
        'video_title' => $analysisRow['video_title'],
        'analyzed_at' => $analysisRow['analyzed_at'],
        'video_number' => $estimateVideoNumber,
    ];
}

$address = (string) $listing['address'];
$img = $listing['img_src'] !== null ? (string) $listing['img_src'] : '';
$beds = $listing['beds'] !== null ? rtrim(rtrim(number_format((float) $listing['beds'], 1), '0'), '.') : null;
$baths = $listing['baths'] !== null ? rtrim(rtrim(number_format((float) $listing['baths'], 1), '0'), '.') : null;
$sqft = $listing['sqft'] !== null ? number_format((int) $listing['sqft']) : null;
$facts = array_filter([
    $beds !== null ? $beds . ' bed' : null,
    $baths !== null ? $baths . ' bath' : null,
    $sqft !== null ? $sqft . ' sqft' : null,
]);
$hasEstimate = is_array($analysis) && !empty($analysis['rooms']);
$pageTitle = $address . ' — Renovation estimate | yHome';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= house_h($pageTitle) ?></title>
    <meta name="description" content="See renovation condition and cost estimates for <?= house_h($address) ?>. Share a house-tour video to get a visible-condition report from yHome.">
    <link rel="stylesheet" href="/style.css">
    <style>
        .house-page { padding-bottom: 96px; }
        .house-hero {
            padding: 0 0 36px;
            background:
                radial-gradient(ellipse 110% 80% at 0% 0%, rgba(46, 157, 120, 0.12), transparent 52%),
                radial-gradient(ellipse 80% 60% at 100% 10%, rgba(200, 167, 107, 0.1), transparent 48%),
                linear-gradient(180deg, #ffffff 0%, #f7fbf8 70%, #edf6f1 100%);
        }
        .house-convert {
            display: grid;
            grid-template-columns: minmax(0, 1fr);
            gap: 32px;
            align-items: start;
            margin-top: 8px;
            max-width: none;
        }
        @media (min-width: 920px) {
            .house-convert {
                grid-template-columns: minmax(0, 1.1fr) minmax(0, 0.9fr);
                gap: 40px;
                align-items: start;
            }
        }
        .house-convert__copy {
            min-width: 0;
            display: flex;
            flex-direction: column;
            justify-content: flex-start;
        }
        .house-convert__kicker {
            margin: 0 0 10px;
            font-size: 0.82rem;
            font-weight: 800;
            letter-spacing: 0.06em;
            text-transform: uppercase;
            color: var(--accent-dark);
        }
        .house-convert__title {
            margin: 0 0 12px;
            font-size: clamp(1.85rem, 4.2vw, 2.65rem);
            line-height: 1.12;
            letter-spacing: -0.04em;
            font-weight: 800;
            max-width: none;
        }
        .house-convert__sub {
            margin: 0 0 20px;
            font-size: clamp(1.02rem, 2vw, 1.15rem);
            color: var(--muted);
            line-height: 1.5;
            max-width: none;
        }
        .house-convert__sub strong { color: var(--text); font-weight: 700; }
        .upload-cta {
            display: flex;
            flex-wrap: wrap;
            gap: 12px;
            align-items: center;
            margin: 4px 0 0;
        }
        .upload-cta .button {
            border-radius: 999px;
            padding: 20px 36px;
            font-size: 1.15rem;
            font-weight: 800;
            min-height: 60px;
            box-shadow: 0 16px 32px rgba(46, 157, 120, 0.3);
        }
        .upload-cta__hint {
            margin: 0;
            color: var(--muted);
            font-size: 0.9rem;
            font-weight: 600;
        }
        .house-status {
            display: none;
            margin-top: 12px;
            padding: 12px 14px;
            border-radius: 12px;
            font-size: 0.92rem;
            white-space: pre-wrap;
        }
        .house-status.show { display: block; }
        .house-status.loading {
            background: #ebf8f1;
            color: var(--accent-dark);
            border: 1px solid #c6e9d8;
        }
        .house-status.error {
            background: #fff1f0;
            color: #b42318;
            border: 1px solid #f3c4c0;
        }
        .house-status.ok {
            background: #ebf8f1;
            color: #176948;
            border: 1px solid #b7e4cd;
        }
        .upload-modal {
            display: none;
            position: fixed;
            inset: 0;
            z-index: 1200;
            align-items: center;
            justify-content: center;
            padding: 20px;
        }
        .upload-modal.is-open { display: flex; }
        .upload-modal__backdrop {
            position: absolute;
            inset: 0;
            background: rgba(15, 23, 42, 0.48);
            backdrop-filter: blur(6px);
        }
        .upload-modal__dialog {
            position: relative;
            z-index: 1;
            width: min(560px, 100%);
            max-height: min(92vh, 760px);
            overflow: auto;
            background: #fff;
            border-radius: 22px;
            box-shadow: 0 28px 80px rgba(15, 23, 42, 0.28);
            padding: 24px 24px 20px;
        }
        .upload-modal__head {
            display: flex;
            align-items: flex-start;
            justify-content: space-between;
            gap: 12px;
            margin-bottom: 18px;
        }
        .upload-modal__head h2 {
            margin: 0;
            font-size: 1.45rem;
            letter-spacing: -0.03em;
            font-weight: 800;
        }
        .upload-modal__close {
            border: none;
            background: transparent;
            color: #111827;
            font-size: 1.5rem;
            line-height: 1;
            padding: 4px 8px;
            cursor: pointer;
            border-radius: 8px;
        }
        .upload-modal__close:hover { background: #f3f4f6; }
        .upload-tabs {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 6px;
            padding: 6px;
            background: #f3f4f6;
            border-radius: 14px;
            margin-bottom: 18px;
        }
        .upload-tab {
            border: none;
            background: transparent;
            border-radius: 10px;
            padding: 12px 10px;
            font-size: 0.95rem;
            font-weight: 700;
            color: #4b5563;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
        }
        .upload-tab.is-active {
            background: #fff;
            color: #111827;
            box-shadow: 0 1px 3px rgba(15, 23, 42, 0.08);
        }
        .upload-tab svg { width: 18px; height: 18px; flex: 0 0 auto; }
        .upload-pane { display: none; }
        .upload-pane.is-active { display: block; }
        .upload-drop {
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            gap: 8px;
            min-height: 220px;
            padding: 28px 20px;
            border: 1.5px dashed #d1d5db;
            border-radius: 16px;
            background: #fafafa;
            text-align: center;
            cursor: pointer;
            transition: border-color .15s ease, background .15s ease;
        }
        .upload-drop:hover,
        .upload-drop.is-dragover {
            border-color: var(--accent);
            background: #f7fbf8;
        }
        .upload-drop__icon {
            width: 36px;
            height: 36px;
            color: #9ca3af;
            margin-bottom: 4px;
        }
        .upload-drop__main {
            margin: 0;
            font-size: 1.02rem;
            color: #111827;
        }
        .upload-drop__main strong { font-weight: 800; }
        .upload-drop__meta {
            margin: 0;
            font-size: 0.88rem;
            color: #6b7280;
        }
        .upload-drop__hint {
            margin: 10px 0 0;
            font-size: 0.86rem;
            color: #6b7280;
            max-width: 34ch;
            line-height: 1.4;
        }
        .upload-drop__name {
            margin: 8px 0 0;
            font-size: 0.92rem;
            font-weight: 700;
            color: var(--accent-dark);
            display: none;
        }
        .upload-drop__name.show { display: block; }
        .upload-drop input[type="file"] {
            position: absolute;
            width: 1px;
            height: 1px;
            opacity: 0;
            overflow: hidden;
        }
        .upload-yt label {
            display: block;
            margin: 0 0 8px;
            font-size: 0.9rem;
            font-weight: 700;
            color: #111827;
        }
        .upload-yt input[type="url"] {
            width: 100%;
            padding: 14px 16px;
            border: 1.5px solid #d1d5db;
            border-radius: 12px;
            font-size: 1rem;
            background: #fff;
        }
        .upload-yt input:focus {
            outline: 2px solid rgba(46, 157, 120, 0.35);
            border-color: var(--accent);
        }
        .upload-yt__hint {
            margin: 10px 0 0;
            font-size: 0.86rem;
            color: #6b7280;
            line-height: 1.4;
        }
        .upload-email {
            margin-top: 16px;
        }
        .upload-email label {
            display: block;
            margin: 0 0 8px;
            font-size: 0.9rem;
            font-weight: 700;
            color: #111827;
        }
        .upload-email input[type="email"] {
            width: 100%;
            padding: 14px 16px;
            border: 1.5px solid #d1d5db;
            border-radius: 12px;
            font-size: 1rem;
            background: #fff;
        }
        .upload-email input:focus {
            outline: 2px solid rgba(46, 157, 120, 0.35);
            border-color: var(--accent);
        }
        .upload-email__hint {
            margin: 8px 0 0;
            font-size: 0.86rem;
            color: #6b7280;
            line-height: 1.4;
        }
        .upload-modal__footer {
            margin-top: 18px;
            display: flex;
            flex-direction: column;
            gap: 12px;
        }
        .upload-modal__footer .button {
            width: 100%;
            border-radius: 999px;
            padding: 14px 18px;
            box-shadow: 0 12px 24px rgba(46, 157, 120, 0.22);
        }
        .upload-secure {
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            margin: 0;
            color: #9ca3af;
            font-size: 0.82rem;
            font-weight: 600;
        }
        .upload-secure svg { width: 14px; height: 14px; }
        .body-modal-open { overflow: hidden; }
        .house-outcomes {
            margin: 18px 0 0;
            padding: 0;
            list-style: none;
            display: grid;
            gap: 10px;
        }
        .house-outcomes li {
            display: grid;
            grid-template-columns: 22px minmax(0, 1fr);
            gap: 10px;
            align-items: start;
            font-size: 0.98rem;
            font-weight: 600;
            color: var(--text);
            line-height: 1.35;
        }
        .house-outcomes__mark {
            width: 22px;
            height: 22px;
            border-radius: 50%;
            background: #f8f2e8;
            border: 1px solid rgba(200, 167, 107, 0.45);
            position: relative;
        }
        .house-outcomes__mark::after {
            content: "";
            position: absolute;
            left: 7px;
            top: 4px;
            width: 5px;
            height: 9px;
            border: solid var(--gold-check);
            border-width: 0 2.5px 2.5px 0;
            transform: rotate(45deg);
        }
        .house-proof {
            margin-top: 16px;
            padding: 12px 14px;
            border-radius: 14px;
            background: #ebf8f1;
            border: 1px solid #c6e9d8;
            color: #176948;
            font-size: 0.95rem;
            font-weight: 700;
        }
        .house-proof a {
            color: inherit;
            margin-left: 8px;
        }
        .house-convert__visual { min-width: 0; position: relative; }
        .house-photo {
            min-width: 0;
            width: 100%;
            max-width: 100%;
            border-radius: 24px;
            overflow: hidden;
            background: #d7e5dc;
            aspect-ratio: 4 / 3;
            box-shadow: var(--shadow);
            position: relative;
        }
        .house-photo img {
            display: block;
            width: 100%;
            max-width: 100%;
            height: 100%;
            object-fit: cover;
        }
        .house-photo__meta {
            position: absolute;
            left: 14px;
            right: 14px;
            bottom: 14px;
            display: flex;
            flex-wrap: wrap;
            gap: 8px;
            align-items: flex-end;
            justify-content: space-between;
        }
        .house-photo__chip {
            background: rgba(255, 255, 255, 0.94);
            backdrop-filter: blur(6px);
            border-radius: 12px;
            padding: 10px 12px;
            box-shadow: 0 10px 24px rgba(15, 23, 42, 0.12);
            max-width: 100%;
        }
        .house-photo__chip .price {
            margin: 0;
            font-size: 1.15rem;
            font-weight: 800;
            color: var(--accent-dark);
            line-height: 1.1;
        }
        .house-photo__chip .facts {
            margin: 4px 0 0;
            font-size: 0.8rem;
            color: var(--muted);
            font-weight: 600;
        }
        .house-context {
            margin-top: 8px;
            padding: 18px 0 0;
            border-top: 1px solid rgba(215, 229, 220, 0.9);
            display: flex;
            flex-wrap: wrap;
            gap: 10px 20px;
            align-items: baseline;
            justify-content: space-between;
        }
        .house-context__addr {
            margin: 0;
            font-size: 1.05rem;
            font-weight: 700;
            letter-spacing: -0.02em;
        }
        .house-context__links {
            display: flex;
            flex-wrap: wrap;
            gap: 10px 16px;
        }
        .house-context__links a {
            color: var(--accent-dark);
            font-weight: 700;
            text-decoration: none;
        }
        .house-context__links a:hover { text-decoration: underline; }
        .estimate-panel {
            background: #fff;
            border: 1px solid var(--border);
            border-radius: 20px;
            padding: 24px;
            box-shadow: var(--shadow);
        }
        .estimate-panel h2 {
            margin: 0 0 6px;
            font-size: 1.4rem;
            letter-spacing: -0.02em;
        }
        .estimate-empty { color: var(--muted); margin: 0; }
        .estimate-empty a { color: var(--accent-dark); font-weight: 700; }
        .estimate-overall {
            margin: 12px 0 16px;
            font-size: 1.35rem;
            font-weight: 800;
            letter-spacing: -0.02em;
        }
        .budget-row {
            display: flex;
            justify-content: space-between;
            gap: 12px;
            padding: 10px 0;
            border-top: 1px solid var(--border);
            font-size: 0.98rem;
        }
        .budget-row:last-child { font-weight: 800; }
        .room-block {
            border-top: 1px solid var(--border);
            padding: 18px 0 6px;
            margin-top: 8px;
        }
        .room-block:first-of-type { border-top: none; margin-top: 4px; }
        .room-head {
            display: flex;
            flex-wrap: wrap;
            gap: 8px 16px;
            align-items: baseline;
            margin-bottom: 8px;
        }
        .room-head strong {
            text-transform: capitalize;
            font-size: 1.05rem;
        }
        .room-head .score {
            margin-left: auto;
            font-weight: 800;
            font-variant-numeric: tabular-nums;
        }
        .score-bar {
            height: 8px;
            border-radius: 999px;
            background: #e8f3ee;
            overflow: hidden;
            margin: 0 0 10px;
        }
        .score-bar > span { display: block; height: 100%; border-radius: 999px; }
        .score-bar.green > span { background: #2e9d78; }
        .score-bar.amber > span { background: #d4a017; }
        .score-bar.orange > span { background: #e67e22; }
        .score-bar.red > span { background: #d64545; }
        .room-note { margin: 0 0 10px; color: var(--muted); }
        .room-imgs {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(180px, 1fr));
            gap: 12px;
            margin: 12px 0 14px;
        }
        .room-imgs img {
            width: 100%;
            aspect-ratio: 16 / 10;
            height: auto;
            object-fit: cover;
            border-radius: 14px;
            border: 1px solid var(--border);
            background: #edf6f1;
            cursor: zoom-in;
        }
        .estimate-video {
            margin: 14px 0 20px;
        }
        .estimate-video + .estimate-video {
            margin-top: 8px;
        }
        .estimate-video__title {
            margin: 0 0 10px;
            font-size: 0.95rem;
            font-weight: 700;
            color: var(--text);
        }
        .estimate-video__title span {
            color: var(--muted);
            font-weight: 600;
        }
        .estimate-video__frame {
            position: relative;
            width: 100%;
            aspect-ratio: 16 / 9;
            border-radius: 16px;
            overflow: hidden;
            background: #0f172a;
            border: 1px solid var(--border);
            box-shadow: 0 12px 28px rgba(15, 23, 42, 0.08);
        }
        .estimate-video__frame iframe,
        .estimate-video__frame video {
            position: absolute;
            inset: 0;
            width: 100%;
            height: 100%;
            border: 0;
            background: #000;
        }
        .estimate-video__link {
            display: inline-block;
            margin-top: 8px;
            color: var(--accent-dark);
            font-weight: 700;
            text-decoration: none;
            font-size: 0.9rem;
        }
        .estimate-video__link:hover { text-decoration: underline; }
        .work-section { margin-top: 8px; }
        .work-section h3 {
            margin: 0 0 6px;
            font-size: 0.78rem;
            text-transform: uppercase;
            letter-spacing: 0.05em;
            color: var(--muted);
        }
        .work-item {
            display: grid;
            grid-template-columns: 1fr auto;
            gap: 2px 12px;
            padding: 8px 0;
            border-top: 1px solid #eef4f0;
            font-size: 0.92rem;
        }
        .work-item .reason {
            grid-column: 1 / -1;
            color: var(--muted);
            font-size: 0.82rem;
        }
        .work-item .cost { font-weight: 700; white-space: nowrap; }
        .room-budget { margin: 10px 0 0; font-weight: 700; }
        .estimate-disclaimer {
            margin: 18px 0 0;
            font-size: 0.82rem;
            color: var(--muted);
            line-height: 1.45;
        }
        .house-sticky-cta {
            display: none;
            position: fixed;
            left: 0;
            right: 0;
            bottom: 0;
            z-index: 50;
            padding: 12px 16px calc(12px + env(safe-area-inset-bottom));
            background: rgba(255, 255, 255, 0.94);
            backdrop-filter: blur(10px);
            border-top: 1px solid var(--border);
            box-shadow: 0 -12px 30px rgba(15, 23, 42, 0.08);
        }
        .house-sticky-cta .button {
            width: 100%;
            border-radius: 999px;
            padding: 14px 18px;
            text-align: center;
            text-decoration: none;
        }
        @media (max-width: 719px) {
            .house-sticky-cta { display: block; }
            .house-page { padding-bottom: 110px; }
        }
        .lightbox {
            display: none;
            position: fixed;
            inset: 0;
            z-index: 1000;
            background: rgba(0, 0, 0, 0.88);
            align-items: center;
            justify-content: center;
            padding: 24px;
            cursor: zoom-out;
        }
        .lightbox.show { display: flex; }
        .lightbox img {
            max-width: min(96vw, 1200px);
            max-height: 92vh;
            object-fit: contain;
            border-radius: 8px;
            cursor: default;
        }
        .lightbox-close {
            position: absolute;
            top: 16px;
            right: 20px;
            background: transparent;
            border: none;
            color: #fff;
            font-size: 1.75rem;
            cursor: pointer;
        }
    </style>
</head>
<body>
<main class="house-page">
    <section class="house-hero">
        <div class="container" style="padding-top: 24px;">
            <header class="site-header">
                <a class="site-logo" href="/">yHome</a>
                <div class="site-header-actions">
                    <a href="/houses.php" class="button button-nav-cta">All houses</a>
                </div>
            </header>

            <div class="house-convert">
                <div class="house-convert__copy">
                    <p class="house-convert__kicker">For this home</p>
                    <h1 class="house-convert__title">
                        <?= $hasEstimate
                            ? 'Want a sharper renovation read? Share a better tour video.'
                            : 'Know renovation cost before you offer.' ?>
                    </h1>
                    <p class="house-convert__sub">
                        Upload a house-tour video or paste a YouTube link for <strong><?= house_h($address) ?></strong>.
                        We’ll score visible rooms and estimate likely work — so you don’t guess after you’re emotionally invested.
                    </p>

                    <div class="upload-cta" id="upload">
                        <button type="button" class="button button-primary" id="open-upload-modal">
                            <?= $hasEstimate ? 'Upload a better tour video' : 'Upload house-tour video' ?>
                        </button>
                        <p class="upload-cta__hint">Free · No signup · File or YouTube</p>
                    </div>
                    <div class="house-status" id="video-status" role="status" aria-live="polite"></div>

                    <ul class="house-outcomes">
                        <li><span class="house-outcomes__mark" aria-hidden="true"></span><span>Visible condition score for kitchen, living, bath & bedroom</span></li>
                        <li><span class="house-outcomes__mark" aria-hidden="true"></span><span>Required vs optional work with budget ranges</span></li>
                        <li><span class="house-outcomes__mark" aria-hidden="true"></span><span>A clearer picture before you spend time or money on this house</span></li>
                    </ul>

                    <?php if ($hasEstimate && $analysis['overall_score'] !== null): ?>
                        <p class="house-proof">
                            Current visible condition: <?= (int) $analysis['overall_score'] ?>/100
                            · Budget <?= house_h(house_money($analysis['total_low'])) ?> – <?= house_h(house_money($analysis['total_high'])) ?>
                            <a href="#estimate">See full estimate</a>
                        </p>
                    <?php endif; ?>
                </div>

                <div class="house-convert__visual">
                    <div class="house-photo">
                        <?php if ($img !== ''): ?>
                            <img src="<?= house_h($img) ?>" alt="<?= house_h($address) ?>" width="900" height="675" decoding="async">
                        <?php else: ?>
                            <img alt="" src="data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='900' height='675'%3E%3Crect fill='%23d7e5dc' width='100%25' height='100%25'/%3E%3C/svg%3E">
                        <?php endif; ?>
                        <div class="house-photo__meta">
                            <div class="house-photo__chip">
                                <p class="price"><?= house_h(house_money($listing['list_price'])) ?></p>
                                <?php if ($facts !== []): ?>
                                    <p class="facts"><?= house_h(implode(' · ', $facts)) ?></p>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                    <div class="house-context">
                        <p class="house-context__addr"><?= house_h($address) ?></p>
                        <div class="house-context__links">
                            <?php if (!empty($listing['detail_url'])): ?>
                                <a href="<?= house_h((string) $listing['detail_url']) ?>" target="_blank" rel="noopener">View on Zillow</a>
                            <?php endif; ?>
                            <a href="#estimate"><?= $hasEstimate ? 'See renovation estimate' : 'Jump to estimate' ?></a>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </section>

    <section class="section" id="estimate">
        <div class="container">
            <div class="estimate-panel">
                <h2>Renovation estimate</h2>
                <?php if (!$hasEstimate): ?>
                    <p class="estimate-empty">No estimate yet. <a href="#upload" data-open-upload>Upload a house-tour video</a> to generate one for this home.</p>
                <?php else: ?>
                    <?php
                    $rooms = $analysis['rooms'];
                    $hasScores = false;
                    foreach ($rooms as $r) {
                        if (!is_array($r)) {
                            continue;
                        }
                        if (isset($r['condition_score']) || !empty($r['recommended_work'])) {
                            $hasScores = true;
                            break;
                        }
                    }
                    ?>
                    <?php foreach ($tourVideos as $vid): ?>
                        <?php
                        $ytId = $vid['youtube_id'];
                        $localVideoUrl = $vid['local_url'];
                        if ($ytId === null && $localVideoUrl === null) {
                            continue;
                        }
                        $isEstimateSource = (int) $vid['number'] === (int) ($analysis['video_number'] ?? 0);
                        ?>
                        <div class="estimate-video">
                            <p class="estimate-video__title">
                                Video <?= (int) $vid['number'] ?>
                                <?php if ($isEstimateSource): ?>
                                    <span>· used for this estimate</span>
                                <?php endif; ?>
                            </p>
                            <div class="estimate-video__frame">
                                <?php if ($ytId !== null): ?>
                                    <iframe
                                        src="https://www.youtube-nocookie.com/embed/<?= house_h((string) $ytId) ?>"
                                        title="House tour video <?= (int) $vid['number'] ?>"
                                        allow="accelerometer; autoplay; clipboard-write; encrypted-media; gyroscope; picture-in-picture; web-share"
                                        allowfullscreen
                                        loading="lazy"
                                        referrerpolicy="strict-origin-when-cross-origin"></iframe>
                                <?php else: ?>
                                    <video controls playsinline preload="metadata"
                                           src="<?= house_h((string) $localVideoUrl) ?>"
                                           title="Uploaded house tour video <?= (int) $vid['number'] ?>"></video>
                                <?php endif; ?>
                            </div>
                            <?php if ($ytId !== null): ?>
                                <a class="estimate-video__link"
                                   href="https://www.youtube.com/watch?v=<?= house_h((string) $ytId) ?>"
                                   target="_blank" rel="noopener">Open on YouTube</a>
                            <?php endif; ?>
                        </div>
                    <?php endforeach; ?>

                    <?php if ($hasScores && $analysis['overall_score'] !== null): ?>
                        <p class="estimate-overall">
                            Overall visible condition · <?= (int) $analysis['overall_score'] ?>/100
                            <?php if ($analysis['overall_label'] !== ''): ?>
                                (<?= house_h($analysis['overall_label']) ?>)
                            <?php endif; ?>
                        </p>
                        <div class="budget-row"><span>Required repairs</span><span><?= house_h(house_money($analysis['required_low'])) ?> – <?= house_h(house_money($analysis['required_high'])) ?></span></div>
                        <div class="budget-row"><span>Recommended improvements</span><span><?= house_h(house_money($analysis['recommended_low'])) ?> – <?= house_h(house_money($analysis['recommended_high'])) ?></span></div>
                        <div class="budget-row"><span>Optional modernization</span><span><?= house_h(house_money($analysis['optional_low'])) ?> – <?= house_h(house_money($analysis['optional_high'])) ?></span></div>
                        <div class="budget-row"><span>Potential total budget</span><span><?= house_h(house_money($analysis['total_low'])) ?> – <?= house_h(house_money($analysis['total_high'])) ?></span></div>
                    <?php else: ?>
                        <p class="estimate-overall">Potential total · <?= house_h(house_money($analysis['total_low'])) ?> – <?= house_h(house_money($analysis['total_high'])) ?></p>
                    <?php endif; ?>

                    <?php foreach ($rooms as $r): ?>
                        <?php
                        if (!is_array($r)) {
                            continue;
                        }
                        $roomName = (string) ($r['room'] ?? 'Room');
                        $summary = (string) ($r['summary'] ?? $r['note'] ?? '—');
                        $images = is_array($r['images'] ?? null) ? $r['images'] : [];
                        ?>
                        <div class="room-block">
                            <?php if ($hasScores && isset($r['condition_score'])): ?>
                                <?php
                                $score = (int) $r['condition_score'];
                                $tone = house_score_tone($score);
                                $label = (string) ($r['condition_label'] ?? renovation_score_label($score));
                                ?>
                                <div class="room-head">
                                    <strong><?= house_h($roomName) ?></strong>
                                    <span class="score"><?= $score ?>/100 · <?= house_h($label) ?></span>
                                </div>
                                <div class="score-bar <?= house_h($tone) ?>" aria-hidden="true"><span style="width:<?= max(0, min(100, $score)) ?>%"></span></div>
                            <?php else: ?>
                                <div class="room-head">
                                    <strong><?= house_h($roomName) ?></strong>
                                    <?php if (!empty($r['condition'])): ?>
                                        <span class="score"><?= house_h((string) $r['condition']) ?></span>
                                    <?php endif; ?>
                                </div>
                            <?php endif; ?>
                            <p class="room-note"><?= house_h($summary) ?></p>
                            <?php if ($images !== []): ?>
                                <div class="room-imgs">
                                    <?php foreach ($images as $imgRow): ?>
                                        <?php
                                        $url = is_array($imgRow) ? (string) ($imgRow['url'] ?? '') : '';
                                        if ($url === '') {
                                            continue;
                                        }
                                        ?>
                                        <img src="<?= house_h($url) ?>" alt="<?= house_h($roomName) ?>" loading="lazy">
                                    <?php endforeach; ?>
                                </div>
                            <?php endif; ?>

                            <?php if (!empty($r['recommended_work']) && is_array($r['recommended_work'])): ?>
                                <?php
                                $groups = ['required' => [], 'recommended' => [], 'optional' => []];
                                foreach ($r['recommended_work'] as $w) {
                                    if (!is_array($w)) {
                                        continue;
                                    }
                                    $p = (string) ($w['priority'] ?? 'recommended');
                                    if (!isset($groups[$p])) {
                                        $p = 'recommended';
                                    }
                                    $groups[$p][] = $w;
                                }
                                $labels = [
                                    'required' => 'Required repairs',
                                    'recommended' => 'Recommended improvements',
                                    'optional' => 'Optional modernization',
                                ];
                                ?>
                                <?php foreach ($labels as $key => $label): ?>
                                    <?php if ($groups[$key] === []) {
                                        continue;
                                    } ?>
                                    <div class="work-section">
                                        <h3><?= house_h($label) ?></h3>
                                        <?php foreach ($groups[$key] as $w): ?>
                                            <div class="work-item">
                                                <div><strong><?= house_h((string) ($w['title'] ?? $w['code'] ?? 'Work')) ?></strong></div>
                                                <div class="cost"><?= house_h(house_money($w['estimate_low'] ?? 0)) ?> – <?= house_h(house_money($w['estimate_high'] ?? 0)) ?></div>
                                                <?php if (!empty($w['reason'])): ?>
                                                    <div class="reason"><?= house_h((string) $w['reason']) ?></div>
                                                <?php endif; ?>
                                            </div>
                                        <?php endforeach; ?>
                                    </div>
                                <?php endforeach; ?>
                            <?php endif; ?>

                            <?php if (isset($r['estimate_low']) || isset($r['estimate_high'])): ?>
                                <p class="room-budget">
                                    Estimated <?= house_h($roomName) ?> budget:
                                    <?= house_h(house_money($r['estimate_low'] ?? 0)) ?> – <?= house_h(house_money($r['estimate_high'] ?? 0)) ?>
                                </p>
                            <?php endif; ?>
                        </div>
                    <?php endforeach; ?>

                    <p class="estimate-disclaimer"><?= house_h($analysis['disclaimer']) ?></p>
                <?php endif; ?>
            </div>
        </div>
    </section>
</main>

<div class="house-sticky-cta" aria-hidden="false">
    <button type="button" class="button button-primary" data-open-upload>
        <?= $hasEstimate ? 'Upload a better tour video' : 'Upload house-tour video' ?>
    </button>
</div>

<div class="upload-modal" id="upload-modal" aria-hidden="true">
    <div class="upload-modal__backdrop" data-close-upload></div>
    <div class="upload-modal__dialog" role="dialog" aria-modal="true" aria-labelledby="upload-modal-title">
        <div class="upload-modal__head">
            <h2 id="upload-modal-title">Upload house tour</h2>
            <button type="button" class="upload-modal__close" data-close-upload aria-label="Close">&times;</button>
        </div>

        <div class="upload-tabs" role="tablist">
            <button type="button" class="upload-tab is-active" role="tab" aria-selected="true" data-tab="file">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true">
                    <path d="M12 16V7m0 0l-3.5 3.5M12 7l3.5 3.5"/>
                    <path d="M20 16.5V18a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2v-1.5"/>
                    <path d="M7 13a5 5 0 0 1 9.9-1.1A3.5 3.5 0 0 1 18.5 18H17"/>
                </svg>
                Upload video
            </button>
            <button type="button" class="upload-tab" role="tab" aria-selected="false" data-tab="youtube">
                <svg viewBox="0 0 24 24" aria-hidden="true">
                    <path fill="#FF0033" d="M23.5 6.2a3 3 0 0 0-2.1-2.1C19.5 3.5 12 3.5 12 3.5s-7.5 0-9.4.6A3 3 0 0 0 .5 6.2 31.5 31.5 0 0 0 0 12a31.5 31.5 0 0 0 .5 5.8 3 3 0 0 0 2.1 2.1c1.9.6 9.4.6 9.4.6s7.5 0 9.4-.6a3 3 0 0 0 2.1-2.1A31.5 31.5 0 0 0 24 12a31.5 31.5 0 0 0-.5-5.8z"/>
                    <path fill="#fff" d="M9.75 15.5v-7l6 3.5-6 3.5z"/>
                </svg>
                YouTube URL
            </button>
        </div>

        <form id="video-form" autocomplete="off">
            <div class="upload-pane is-active" data-pane="file">
                <label class="upload-drop" id="upload-drop" for="video-file">
                    <input type="file" id="video-file" name="video"
                           accept="video/mp4,video/quicktime,video/webm,video/x-matroska,video/x-msvideo,.mp4,.mov,.webm,.mkv,.m4v,.avi">
                    <svg class="upload-drop__icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" aria-hidden="true">
                        <path d="M12 16V7m0 0l-3.5 3.5M12 7l3.5 3.5"/>
                        <path d="M20 16.5V18a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2v-1.5"/>
                        <path d="M7 13a5 5 0 0 1 9.9-1.1A3.5 3.5 0 0 1 18.5 18H17"/>
                    </svg>
                    <p class="upload-drop__main"><strong>Browse files</strong> or drag &amp; drop your house-tour video</p>
                    <p class="upload-drop__meta">MP4, MOV, AVI, MKV, WEBM and more · Max 400 MB</p>
                    <p class="upload-drop__hint">Use a walkthrough that shows kitchen, living, baths, and bedrooms clearly.</p>
                    <p class="upload-drop__name" id="video-file-name"></p>
                </label>
            </div>

            <div class="upload-pane" data-pane="youtube">
                <div class="upload-yt">
                    <label for="video-url">YouTube house-tour link</label>
                    <input type="url" id="video-url" name="url"
                           placeholder="https://www.youtube.com/watch?v=..."
                           inputmode="url">
                    <p class="upload-yt__hint">Paste any public YouTube tour of this home. We’ll extract frames and score visible rooms.</p>
                </div>
            </div>

            <div class="upload-email">
                <label for="contact-email">Email</label>
                <input type="email" id="contact-email" name="email" required
                       placeholder="you@email.com"
                       autocomplete="email"
                       inputmode="email">
                <p class="upload-email__hint">No signup. We’ll email you when an admin finishes your renovation estimate.</p>
            </div>

            <div class="upload-modal__footer">
                <button type="submit" class="button button-primary" id="video-btn">
                    Submit tour video
                </button>
                <p class="upload-secure">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                        <rect x="5" y="11" width="14" height="10" rx="2"/>
                        <path d="M8 11V8a4 4 0 0 1 8 0v3"/>
                    </svg>
                    Secure processing. Your video is never shared.
                </p>
            </div>
        </form>
    </div>
</div>

<div class="lightbox" id="lightbox" aria-hidden="true">
    <button type="button" class="lightbox-close" id="lightbox-close" aria-label="Close">&times;</button>
    <img id="lightbox-img" alt="">
</div>

<script>
(function () {
    const listingId = <?= (int) $id ?>;
    const form = document.getElementById('video-form');
    const urlInput = document.getElementById('video-url');
    const emailInput = document.getElementById('contact-email');
    const fileInput = document.getElementById('video-file');
    const fileNameEl = document.getElementById('video-file-name');
    const drop = document.getElementById('upload-drop');
    const btn = document.getElementById('video-btn');
    const statusEl = document.getElementById('video-status');
    const modal = document.getElementById('upload-modal');
    const lightbox = document.getElementById('lightbox');
    const lightboxImg = document.getElementById('lightbox-img');
    let activeTab = 'file';

    function setStatus(kind, text) {
        statusEl.className = 'house-status show ' + kind;
        statusEl.textContent = text;
    }

    function openModal(tab) {
        modal.classList.add('is-open');
        modal.setAttribute('aria-hidden', 'false');
        document.body.classList.add('body-modal-open');
        if (tab) setTab(tab);
        setTimeout(function () {
            if (activeTab === 'youtube') urlInput.focus();
        }, 40);
    }

    function closeModal() {
        modal.classList.remove('is-open');
        modal.setAttribute('aria-hidden', 'true');
        document.body.classList.remove('body-modal-open');
    }

    function setTab(tab) {
        activeTab = tab === 'youtube' ? 'youtube' : 'file';
        document.querySelectorAll('.upload-tab').forEach(function (el) {
            const on = el.getAttribute('data-tab') === activeTab;
            el.classList.toggle('is-active', on);
            el.setAttribute('aria-selected', on ? 'true' : 'false');
        });
        document.querySelectorAll('.upload-pane').forEach(function (el) {
            el.classList.toggle('is-active', el.getAttribute('data-pane') === activeTab);
        });
        if (activeTab === 'file') {
            urlInput.value = '';
        } else {
            fileInput.value = '';
            showFileName(null);
        }
    }

    function showFileName(file) {
        if (!file) {
            fileNameEl.textContent = '';
            fileNameEl.classList.remove('show');
            return;
        }
        const mb = (file.size / (1024 * 1024)).toFixed(1);
        fileNameEl.textContent = file.name + ' (' + mb + ' MB)';
        fileNameEl.classList.add('show');
    }

    document.getElementById('open-upload-modal').addEventListener('click', function () {
        openModal('file');
    });
    document.querySelectorAll('[data-open-upload]').forEach(function (el) {
        el.addEventListener('click', function (e) {
            e.preventDefault();
            openModal('file');
        });
    });
    document.querySelectorAll('[data-close-upload]').forEach(function (el) {
        el.addEventListener('click', closeModal);
    });
    document.querySelectorAll('.upload-tab').forEach(function (el) {
        el.addEventListener('click', function () {
            setTab(el.getAttribute('data-tab'));
        });
    });

    fileInput.addEventListener('change', function () {
        const file = fileInput.files && fileInput.files[0];
        showFileName(file || null);
        if (file) {
            urlInput.value = '';
            setTab('file');
        }
    });

    ;['dragenter', 'dragover'].forEach(function (evt) {
        drop.addEventListener(evt, function (e) {
            e.preventDefault();
            e.stopPropagation();
            drop.classList.add('is-dragover');
        });
    });
    ;['dragleave', 'drop'].forEach(function (evt) {
        drop.addEventListener(evt, function (e) {
            e.preventDefault();
            e.stopPropagation();
            drop.classList.remove('is-dragover');
        });
    });
    drop.addEventListener('drop', function (e) {
        const files = e.dataTransfer && e.dataTransfer.files;
        if (!files || !files.length) return;
        try {
            const dt = new DataTransfer();
            dt.items.add(files[0]);
            fileInput.files = dt.files;
        } catch (err) {}
        showFileName(files[0]);
        urlInput.value = '';
        setTab('file');
    });

    document.addEventListener('click', function (e) {
        const t = e.target;
        if (t && t.tagName === 'IMG' && t.closest('.room-imgs')) {
            lightboxImg.src = t.src;
            lightbox.classList.add('show');
            lightbox.setAttribute('aria-hidden', 'false');
        }
    });
    function closeLightbox() {
        lightbox.classList.remove('show');
        lightbox.setAttribute('aria-hidden', 'true');
        lightboxImg.src = '';
    }
    lightbox.addEventListener('click', closeLightbox);
    document.getElementById('lightbox-close').addEventListener('click', closeLightbox);
    document.addEventListener('keydown', function (e) {
        if (e.key !== 'Escape') return;
        if (lightbox.classList.contains('show')) closeLightbox();
        else if (modal.classList.contains('is-open')) closeModal();
    });

    form.addEventListener('submit', async function (e) {
        e.preventDefault();
        const url = (urlInput.value || '').trim();
        const email = (emailInput.value || '').trim();
        const file = fileInput.files && fileInput.files[0];
        const useFile = activeTab === 'file';
        if (!email || !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email)) {
            setStatus('error', 'Enter a valid email so we can send your estimate.');
            emailInput.focus();
            return;
        }
        if (useFile && !file) {
            setStatus('error', 'Choose a video file to upload.');
            return;
        }
        if (!useFile && !url) {
            setStatus('error', 'Paste a YouTube house-tour link.');
            return;
        }
        btn.disabled = true;
        closeModal();
        setStatus('loading', useFile
            ? 'Uploading your tour…'
            : 'Submitting your YouTube tour…');
        try {
            let res;
            if (useFile) {
                const fd = new FormData();
                fd.append('video', file);
                fd.append('listing_id', String(listingId));
                fd.append('email', email);
                res = await fetch('/api/tour_submit.php', {
                    method: 'POST',
                    headers: { 'Accept': 'application/json' },
                    body: fd,
                });
            } else {
                res = await fetch('/api/tour_submit.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' },
                    body: JSON.stringify({ url: url, listing_id: listingId, email: email }),
                });
            }
            const data = await res.json().catch(function () {
                return { ok: false, error: 'Invalid server response (HTTP ' + res.status + ')' };
            });
            if (!res.ok || !data.ok) {
                throw new Error(data.error || ('Submit failed (HTTP ' + res.status + ')'));
            }
            setStatus('ok', data.message || 'Tour submitted. We’ll email you when the estimate is ready.');
            form.reset();
            showFileName(null);
            setTab('file');
        } catch (err) {
            setStatus('error', err.message || String(err));
            openModal(useFile ? 'file' : 'youtube');
        } finally {
            btn.disabled = false;
        }
    });
})();
</script>
</body>
</html>
