<?php

declare(strict_types=1);

/**
 * Push local yhome_ai/{job_id}/selected frames to the public host after analysis.
 */

/** @return array{public_base_url?:string,sync_key?:string} */
function yai_site_config(): array
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

function yai_public_base_url(): string
{
    $cfg = yai_site_config();
    $base = trim((string) ($cfg['public_base_url'] ?? 'https://yhome.pro'));

    return rtrim($base, '/');
}

function yai_sync_key(): string
{
    $cfg = yai_site_config();

    return trim((string) ($cfg['sync_key'] ?? ''));
}

function yai_job_id_ok(string $jobId): bool
{
    return (bool) preg_match('/^[A-Za-z0-9_-]{8,120}$/', $jobId);
}

/**
 * True when this request is not already on the public host (e.g. local → yhome.pro).
 */
function yai_should_push_frames_to_public(): bool
{
    $publicHost = parse_url(yai_public_base_url(), PHP_URL_HOST);
    if (!is_string($publicHost) || $publicHost === '') {
        return false;
    }
    $here = (string) ($_SERVER['HTTP_HOST'] ?? '');
    $here = preg_replace('/:\d+$/', '', $here) ?: '';
    if ($here === '' || $here === 'localhost' || $here === '127.0.0.1' || $here === '::1') {
        return true;
    }

    return strcasecmp($publicHost, $here) !== 0;
}

/**
 * Upload selected/*.jpg (and optional job/analysis json) to public_base_url.
 *
 * @return array{ok:bool,error?:string,uploaded?:int,skipped?:bool,http?:int,response?:mixed}
 */
function yai_push_job_frames_to_public(string $jobId, string $workRoot): array
{
    if (!yai_job_id_ok($jobId)) {
        return ['ok' => false, 'error' => 'Invalid job id'];
    }
    $key = yai_sync_key();
    if ($key === '') {
        return ['ok' => false, 'error' => 'sync_key not configured in site credentials'];
    }
    if (!yai_should_push_frames_to_public()) {
        return ['ok' => true, 'skipped' => true, 'uploaded' => 0];
    }
    if (!class_exists('CURLFile')) {
        return ['ok' => false, 'error' => 'CURLFile unavailable'];
    }

    $selectedDir = rtrim($workRoot, '/') . '/' . $jobId . '/selected';
    if (!is_dir($selectedDir)) {
        return ['ok' => false, 'error' => 'No selected frames directory'];
    }
    $files = glob($selectedDir . '/t*.jpg') ?: [];
    sort($files, SORT_STRING);
    if ($files === []) {
        return ['ok' => false, 'error' => 'No selected frame files to upload'];
    }

    $valid = [];
    foreach ($files as $path) {
        $base = basename($path);
        if (preg_match('/^t\d{5}\.jpg$/', $base)) {
            $valid[] = ['path' => $path, 'name' => $base];
        }
    }
    if ($valid === []) {
        return ['ok' => false, 'error' => 'No valid frame filenames'];
    }

    // PHP default max_file_uploads is 20 — send in batches.
    $batchSize = 15;
    $batches = array_chunk($valid, $batchSize);
    $url = yai_public_base_url() . '/api/sync_job_frames.php';
    $totalUploaded = 0;
    $lastHttp = 0;
    $lastDecoded = null;

    $jobDir = rtrim($workRoot, '/') . '/' . $jobId;
    $meta = [];
    foreach (['job.json', 'analysis.json'] as $metaName) {
        $metaPath = $jobDir . '/' . $metaName;
        if (is_readable($metaPath) && filesize($metaPath) < 2_000_000) {
            $meta['meta_' . pathinfo($metaName, PATHINFO_FILENAME)] = (string) file_get_contents($metaPath);
        }
    }

    foreach ($batches as $batchIndex => $batch) {
        $post = [
            'key' => $key,
            'job_id' => $jobId,
        ];
        if ($batchIndex === 0) {
            foreach ($meta as $k => $v) {
                $post[$k] = $v;
            }
        }
        foreach ($batch as $i => $row) {
            $post['frames[' . $i . ']'] = new CURLFile($row['path'], 'image/jpeg', $row['name']);
            $post['names[' . $i . ']'] = $row['name'];
        }

        $ch = curl_init($url);
        if ($ch === false) {
            return ['ok' => false, 'error' => 'curl_init failed', 'uploaded' => $totalUploaded];
        }
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $post,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 180,
            CURLOPT_CONNECTTIMEOUT => 20,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_HTTPHEADER => ['Accept: application/json'],
        ]);
        $raw = curl_exec($ch);
        $errno = curl_errno($ch);
        $err = curl_error($ch);
        $http = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $lastHttp = $http;

        if ($errno !== 0) {
            return ['ok' => false, 'error' => 'Upload curl error: ' . $err, 'http' => $http, 'uploaded' => $totalUploaded];
        }
        $decoded = json_decode((string) $raw, true);
        $lastDecoded = $decoded;
        if ($http < 200 || $http >= 300) {
            $msg = is_array($decoded) ? (string) ($decoded['error'] ?? $raw) : (string) $raw;

            return ['ok' => false, 'error' => 'Upload HTTP ' . $http . ': ' . $msg, 'http' => $http, 'uploaded' => $totalUploaded, 'response' => $decoded];
        }
        if (!is_array($decoded) || empty($decoded['ok'])) {
            $msg = is_array($decoded) ? (string) ($decoded['error'] ?? 'bad response') : 'non-JSON response';

            return ['ok' => false, 'error' => $msg, 'http' => $http, 'uploaded' => $totalUploaded, 'response' => $decoded];
        }
        $totalUploaded += (int) ($decoded['uploaded'] ?? count($batch));
    }

    return [
        'ok' => true,
        'uploaded' => $totalUploaded,
        'http' => $lastHttp,
        'response' => $lastDecoded,
    ];
}
