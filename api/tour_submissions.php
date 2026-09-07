<?php

declare(strict_types=1);

/**
 * Admin: list / update house tour submissions for a listing.
 *
 * GET  ?listing_id=1[&status=pending]
 * POST JSON { id, action: "mark_processed"|"mark_failed"|"mark_pending", job_id?, error? }
 */

header('Content-Type: application/json; charset=utf-8');

function tq_out(array $payload, int $code = 200): void
{
    http_response_code($code);
    echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

function tq_fail(string $message, int $code = 400): void
{
    tq_out(['ok' => false, 'error' => $message], $code);
}

require_once dirname(__DIR__) . '/pdo_connect.php';

$pdo = db_pdo_connect();
if (!$pdo instanceof PDO) {
    tq_fail('Database unavailable', 500);
}

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $listingId = isset($_GET['listing_id']) ? (int) $_GET['listing_id'] : 0;
    if ($listingId <= 0) {
        tq_fail('Missing listing_id');
    }
    $status = isset($_GET['status']) ? trim((string) $_GET['status']) : '';
    $sql = 'SELECT id, listing_id, contact_email, source, youtube_url, original_filename,
                   stored_path, status, job_id, error_message, created_at, processed_at
            FROM house_tour_submissions
            WHERE listing_id = ?';
    $params = [$listingId];
    if ($status !== '' && in_array($status, ['pending', 'processing', 'processed', 'failed'], true)) {
        $sql .= ' AND status = ?';
        $params[] = $status;
    }
    $sql .= ' ORDER BY FIELD(status, \'pending\', \'processing\', \'failed\', \'processed\'), created_at DESC, id DESC';
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $rows = [];
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $rows[] = [
            'id' => (int) $row['id'],
            'listing_id' => (int) $row['listing_id'],
            'contact_email' => (string) $row['contact_email'],
            'source' => (string) $row['source'],
            'youtube_url' => $row['youtube_url'] !== null ? (string) $row['youtube_url'] : null,
            'original_filename' => $row['original_filename'] !== null ? (string) $row['original_filename'] : null,
            'stored_path' => $row['stored_path'] !== null ? (string) $row['stored_path'] : null,
            'status' => (string) $row['status'],
            'job_id' => $row['job_id'] !== null ? (string) $row['job_id'] : null,
            'error_message' => $row['error_message'] !== null ? (string) $row['error_message'] : null,
            'created_at' => (string) $row['created_at'],
            'processed_at' => $row['processed_at'] !== null ? (string) $row['processed_at'] : null,
        ];
    }
    tq_out(['ok' => true, 'listing_id' => $listingId, 'submissions' => $rows]);
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    tq_fail('GET or POST required', 405);
}

$raw = file_get_contents('php://input');
$body = is_string($raw) ? json_decode($raw, true) : null;
if (!is_array($body)) {
    tq_fail('Invalid JSON body');
}
$id = (int) ($body['id'] ?? 0);
$action = trim((string) ($body['action'] ?? ''));
if ($id <= 0 || $action === '') {
    tq_fail('Missing id or action');
}

$statusMap = [
    'mark_pending' => 'pending',
    'mark_processing' => 'processing',
    'mark_processed' => 'processed',
    'mark_failed' => 'failed',
];
if (!isset($statusMap[$action])) {
    tq_fail('Unknown action');
}
$status = $statusMap[$action];
$jobId = isset($body['job_id']) ? trim((string) $body['job_id']) : null;
$error = isset($body['error']) ? trim((string) $body['error']) : null;

try {
    $stmt = $pdo->prepare(
        'UPDATE house_tour_submissions
         SET status = :status,
             job_id = COALESCE(:job_id, job_id),
             error_message = :error_message,
             processed_at = CASE WHEN :status2 IN (\'processed\', \'failed\') THEN NOW() ELSE processed_at END
         WHERE id = :id'
    );
    $stmt->execute([
        ':status' => $status,
        ':status2' => $status,
        ':job_id' => ($jobId !== null && $jobId !== '') ? $jobId : null,
        ':error_message' => ($error !== null && $error !== '') ? substr($error, 0, 512) : null,
        ':id' => $id,
    ]);
} catch (Throwable $e) {
    tq_fail('Update failed: ' . $e->getMessage(), 500);
}

tq_out(['ok' => true, 'id' => $id, 'status' => $status]);
