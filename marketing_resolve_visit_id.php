<?php

declare(strict_types=1);

/** Return marketing_page_visits.id when payload visit_id matches a row; otherwise null. */
function marketing_resolve_visit_id_from_payload(PDO $pdo, array $payload): ?int
{
    if (!array_key_exists('visit_id', $payload) || $payload['visit_id'] === null || $payload['visit_id'] === '') {
        return null;
    }
    if (!is_numeric($payload['visit_id'])) {
        return null;
    }
    $v = (int) $payload['visit_id'];
    if ($v <= 0) {
        return null;
    }
    $chk = $pdo->prepare('SELECT 1 FROM marketing_page_visits WHERE id = :id LIMIT 1');
    $chk->execute([':id' => $v]);

    return $chk->fetchColumn() !== false ? $v : null;
}
