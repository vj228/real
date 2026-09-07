<?php

declare(strict_types=1);

/**
 * Load a saved yHome AI job by id.
 * GET /api/yhome_ai_job.php?id=RqpTrFto_LA_20260731010344_84e759
 */

header('Content-Type: application/json; charset=utf-8');

const YT_WORK_ROOT = __DIR__ . '/../yhome_ai';
const YT_PUBLIC_BASE = '/yhome_ai';

function job_out(array $payload, int $code = 200): void
{
    http_response_code($code);
    echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

$id = isset($_GET['id']) ? trim((string) $_GET['id']) : '';
if ($id === '' || !preg_match('/^[A-Za-z0-9._-]{4,80}$/', $id)) {
    job_out(['ok' => false, 'error' => 'Missing or invalid id'], 400);
}

$workDir = YT_WORK_ROOT . '/' . $id;
$selectedDir = $workDir . '/selected';
if (!is_dir($selectedDir)) {
    job_out(['ok' => false, 'error' => 'Job not found: ' . $id], 404);
}

$metaPath = $workDir . '/meta.json';
$analysisPath = $workDir . '/analysis.json';
$analysis = null;
if (is_readable($analysisPath)) {
    $tmp = json_decode((string) file_get_contents($analysisPath), true);
    if (is_array($tmp) && !empty($tmp['rooms'])) {
        $analysis = $tmp;
    }
}

if (is_readable($metaPath)) {
    $meta = json_decode((string) file_get_contents($metaPath), true);
    if (is_array($meta) && !empty($meta['frames'])) {
        $meta['ok'] = true;
        $meta['job_id'] = $id;
        if ($analysis !== null) {
            $meta['analysis'] = $analysis;
        }
        job_out($meta);
    }
}

$files = glob($selectedDir . '/t*.jpg') ?: [];
natcasesort($files);
$files = array_values($files);
$frames = [];
foreach ($files as $path) {
    $base = basename($path);
    if (!preg_match('/^t(\d+)\.jpg$/', $base, $m)) {
        continue;
    }
    $frames[] = [
        'time_sec' => (int) $m[1],
        'url' => YT_PUBLIC_BASE . '/' . rawurlencode($id) . '/selected/' . rawurlencode($base),
    ];
}

if ($frames === []) {
    job_out(['ok' => false, 'error' => 'No frames found for job: ' . $id], 404);
}

$title = $id;
$videoId = '';
$infoPath = $workDir . '/video.info.json';
if (is_readable($infoPath)) {
    $info = json_decode((string) file_get_contents($infoPath), true);
    if (is_array($info)) {
        if (!empty($info['title'])) {
            $title = (string) $info['title'];
        }
        if (!empty($info['id'])) {
            $videoId = (string) $info['id'];
        }
    }
}

$duration = 0;
if ($frames !== []) {
    $duration = (int) end($frames)['time_sec'] + 2;
}

job_out([
    'ok' => true,
    'job_id' => $id,
    'video_id' => $videoId,
    'title' => $title,
    'duration_sec' => $duration,
    'selected_count' => count($frames),
    'frames' => $frames,
    'analysis' => $analysis,
]);
