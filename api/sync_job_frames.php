<?php

declare(strict_types=1);

/**
 * Receive selected tour frames from a local analysis host and write them under yhome_ai/.
 * POST multipart: key, job_id, frames[n], names[n], optional meta_job / meta_analysis JSON text.
 */

header('Content-Type: application/json; charset=utf-8');
set_time_limit(0);
ini_set('display_errors', '0');

const YAI_SYNC_ROOT = __DIR__ . '/../yhome_ai';

require_once dirname(__DIR__) . '/config/job_frames_sync.php';

function sync_out(array $payload, int $code = 200): void
{
    http_response_code($code);
    echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    sync_out(['ok' => false, 'error' => 'POST required'], 405);
}

$expected = yai_sync_key();
$key = (string) ($_POST['key'] ?? '');
if ($expected === '' || $key === '' || !hash_equals($expected, $key)) {
    sync_out(['ok' => false, 'error' => 'Unauthorized'], 403);
}

$jobId = trim((string) ($_POST['job_id'] ?? ''));
if (!yai_job_id_ok($jobId)) {
    sync_out(['ok' => false, 'error' => 'Invalid job_id'], 400);
}

$jobDir = YAI_SYNC_ROOT . '/' . $jobId;
$selectedDir = $jobDir . '/selected';
if (!is_dir($selectedDir) && !mkdir($selectedDir, 0755, true) && !is_dir($selectedDir)) {
    sync_out(['ok' => false, 'error' => 'Failed to create job directory'], 500);
}

$names = $_POST['names'] ?? [];
if (!is_array($names)) {
    $names = [];
}

$uploaded = 0;
$errors = [];

if (!isset($_FILES['frames']) || !is_array($_FILES['frames']['error'] ?? null)) {
    sync_out(['ok' => false, 'error' => 'No frames uploaded'], 400);
}

$errorsList = $_FILES['frames']['error'];
$tmpList = $_FILES['frames']['tmp_name'];
$nameList = $_FILES['frames']['name'];
if (!is_array($errorsList)) {
    $errorsList = [$errorsList];
    $tmpList = [$tmpList];
    $nameList = [$nameList];
    $names = [$names[0] ?? ''];
}

foreach ($errorsList as $i => $err) {
    $err = (int) $err;
    if ($err !== UPLOAD_ERR_OK) {
        $errors[] = 'frame ' . $i . ' upload error ' . $err;
        continue;
    }
    $tmp = (string) ($tmpList[$i] ?? '');
    if ($tmp === '' || !is_uploaded_file($tmp)) {
        $errors[] = 'frame ' . $i . ' missing temp file';
        continue;
    }
    $base = (string) ($names[$i] ?? '');
    if ($base === '') {
        $base = basename((string) ($nameList[$i] ?? ''));
    }
    $base = basename($base);
    if (!preg_match('/^t\d{5}\.jpg$/', $base)) {
        $errors[] = 'frame ' . $i . ' bad name: ' . $base;
        continue;
    }
    $dest = $selectedDir . '/' . $base;
    if (!move_uploaded_file($tmp, $dest)) {
        $errors[] = 'frame ' . $i . ' move failed';
        continue;
    }
    @chmod($dest, 0644);
    $uploaded++;
}

foreach (['job' => 'job.json', 'analysis' => 'analysis.json'] as $field => $fileName) {
    $raw = $_POST['meta_' . $field] ?? null;
    if (!is_string($raw) || $raw === '') {
        continue;
    }
    if (strlen($raw) > 2_000_000) {
        $errors[] = $fileName . ' too large';
        continue;
    }
    $decoded = json_decode($raw, true);
    if (!is_array($decoded)) {
        $errors[] = $fileName . ' not valid JSON';
        continue;
    }
    $ok = file_put_contents(
        $jobDir . '/' . $fileName,
        json_encode($decoded, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
    );
    if ($ok === false) {
        $errors[] = $fileName . ' write failed';
    }
}

if ($uploaded === 0) {
    sync_out([
        'ok' => false,
        'error' => 'No frames saved',
        'details' => $errors,
    ], 422);
}

sync_out([
    'ok' => true,
    'job_id' => $jobId,
    'uploaded' => $uploaded,
    'errors' => $errors,
]);
