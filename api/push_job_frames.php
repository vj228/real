<?php

declare(strict_types=1);

/**
 * Manually push an existing local job's selected frames to production.
 * POST JSON: { "id": "RqpTrFto_LA_..." }
 */

header('Content-Type: application/json; charset=utf-8');
set_time_limit(0);
ini_set('display_errors', '0');

const YAI_ROOT = __DIR__ . '/../yhome_ai';

require_once dirname(__DIR__) . '/config/job_frames_sync.php';

function push_out(array $payload, int $code = 200): void
{
    http_response_code($code);
    echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    push_out(['ok' => false, 'error' => 'POST required'], 405);
}

$raw = file_get_contents('php://input');
$body = is_string($raw) ? json_decode($raw, true) : null;
$id = '';
if (is_array($body) && isset($body['id'])) {
    $id = trim((string) $body['id']);
} elseif (isset($_POST['id'])) {
    $id = trim((string) $_POST['id']);
}

if (!yai_job_id_ok($id)) {
    push_out(['ok' => false, 'error' => 'Missing or invalid id'], 400);
}

$push = yai_push_job_frames_to_public($id, YAI_ROOT);
if (empty($push['ok'])) {
    push_out([
        'ok' => false,
        'error' => (string) ($push['error'] ?? 'push failed'),
        'prod_frames_sync' => $push,
    ], 502);
}

push_out([
    'ok' => true,
    'job_id' => $id,
    'prod_frames_sync' => $push,
]);
