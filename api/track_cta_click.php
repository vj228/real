<?php

declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'method_not_allowed']);

    exit;
}

require_once dirname(__DIR__) . '/pdo_connect.php';
require_once dirname(__DIR__) . '/helpers/marketing_client_ip.php';
require_once dirname(__DIR__) . '/helpers/marketing_resolve_visit_id.php';

function cta_click_read_json(): ?array
{
    $raw = file_get_contents('php://input');
    if ($raw === false || trim($raw) === '') {
        return null;
    }
    $decoded = json_decode($raw, true);

    return is_array($decoded) ? $decoded : null;
}

function cta_click_string($v, int $max): string
{
    if ($v === null || $v === '') {
        return '';
    }
    $s = trim((string) $v);

    return strlen($s) > $max ? substr($s, 0, $max) : $s;
}

$payload = cta_click_read_json();
if ($payload === null) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'invalid_json']);

    exit;
}

$ctaId = cta_click_string($payload['cta_id'] ?? '', 64);
if ($ctaId === '' || !preg_match('/^[a-zA-Z0-9_-]{1,64}$/', $ctaId)) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'invalid_cta_id']);

    exit;
}

$pagePath = cta_click_string($payload['page_path'] ?? '', 2048);
if ($pagePath === '') {
    $parts = explode('?', (string) ($_SERVER['REQUEST_URI'] ?? '/'), 2);
    $pagePath = substr($parts[0], 0, 2048) ?: '/';
}

$httpHost = isset($_SERVER['HTTP_HOST']) ? substr((string) $_SERVER['HTTP_HOST'], 0, 255) : '';

$referrer = isset($payload['referrer']) ? cta_click_string((string) $payload['referrer'], 4096) : '';
if ($referrer === '' && isset($_SERVER['HTTP_REFERER'])) {
    $referrer = cta_click_string((string) $_SERVER['HTTP_REFERER'], 4096);
}

$referrerStored = $referrer !== '' ? $referrer : null;

$pdo = db_pdo_connect();
if ($pdo === null) {
    http_response_code(503);
    echo json_encode(['ok' => false, 'error' => 'database_unavailable']);

    exit;
}

$marketingVisitId = marketing_resolve_visit_id_from_payload($pdo, $payload);
$ip = marketing_client_ip();

$sql = 'INSERT INTO marketing_cta_clicks (
    cta_id, page_path, http_host, ip_address, marketing_visit_id, referrer
) VALUES (
    :cta_id, :page_path, :http_host, :ip, :visit_id, :referrer
)';

try {
    $stmt = $pdo->prepare($sql);
    $stmt->execute([
        ':cta_id' => $ctaId,
        ':page_path' => $pagePath,
        ':http_host' => $httpHost,
        ':ip' => $ip,
        ':visit_id' => $marketingVisitId,
        ':referrer' => $referrerStored,
    ]);
} catch (Throwable $e) {
    error_log('track_cta_click: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'insert_failed']);

    exit;
}

echo json_encode(['ok' => true, 'id' => (int) $pdo->lastInsertId()]);
