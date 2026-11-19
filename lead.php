<?php

declare(strict_types=1);

/**
 * Agent lead page for one house. Same id as house.php: /lead.php?id=2548
 */

require_once __DIR__ . '/pdo_connect.php';

function lead_h(?string $s): string
{
    return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
}

function lead_phone(?string $phone): string
{
    $phone = trim((string) $phone);
    if (preg_match('/^\+1(\d{10})$/', $phone, $m)) {
        return '(' . substr($m[1], 0, 3) . ') ' . substr($m[1], 3, 3) . '-' . substr($m[1], 6);
    }
    return $phone !== '' ? $phone : '—';
}

$pdo = db_pdo_connect();
if ($pdo === null) {
    http_response_code(500);
    echo 'Database connection failed.';
    exit;
}

$id = isset($_GET['id']) ? (int) $_GET['id'] : 0;
if ($id <= 0) {
    http_response_code(400);
    echo 'Add a listing id, for example /lead.php?id=2548';
    exit;
}

$stmt = $pdo->prepare(
    'SELECT id, address, listing_agent_name, listing_broker_name
     FROM zillow_sale_listings WHERE id = ? LIMIT 1'
);
$stmt->execute([$id]);
$listing = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
if ($listing === null) {
    http_response_code(404);
    echo 'Listing not found.';
    exit;
}

$stmt = $pdo->prepare(
    "SELECT id, buyer_phone, message, cannot_answer, created_at
     FROM sms_leads
     WHERE listing_id = ? AND direction = 'in'
     ORDER BY id DESC
     LIMIT 500"
);
$stmt->execute([$id]);
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

$needCount = 0;
foreach ($rows as $r) {
    if ((int) ($r['cannot_answer'] ?? 0) === 1) {
        $needCount++;
    }
}

$address = (string) ($listing['address'] ?? '');
$agentName = trim((string) ($listing['listing_agent_name'] ?? ''));
$broker = trim((string) ($listing['listing_broker_name'] ?? ''));
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Buyer leads — <?= lead_h($address) ?> — yHome</title>
    <meta name="robots" content="noindex,nofollow">
    <link rel="stylesheet" href="/style.css">
    <style>
        .lead-page {
            min-height: 100vh;
            padding-bottom: 64px;
            background: linear-gradient(180deg, #fff 0%, #f7fbf8 100%);
        }
        .lead-page .container { padding-top: 24px; max-width: 900px; }
        .lead-page h1 {
            margin: 8px 0 8px;
            font-size: clamp(1.45rem, 3vw, 1.9rem);
            letter-spacing: -0.03em;
            line-height: 1.2;
        }
        .lead-sub { margin: 0 0 20px; color: var(--muted); line-height: 1.45; }
        .lead-stats { display: flex; flex-wrap: wrap; gap: 10px; margin-bottom: 18px; }
        .lead-stat {
            background: #fff;
            border: 1px solid var(--border);
            border-radius: 12px;
            padding: 12px 16px;
            min-width: 130px;
        }
        .lead-stat b { display: block; font-size: 1.4rem; letter-spacing: -0.02em; }
        .lead-stat span { color: var(--muted); font-size: 0.88rem; }
        .lead-card {
            background: #fff;
            border: 1px solid var(--border);
            border-radius: 14px;
            overflow: hidden;
        }
        .lead-table { width: 100%; border-collapse: collapse; font-size: 0.95rem; }
        .lead-table th, .lead-table td {
            text-align: left;
            padding: 12px 14px;
            border-bottom: 1px solid #eef2f0;
            vertical-align: top;
        }
        .lead-table th {
            color: var(--muted);
            font-size: 0.78rem;
            text-transform: uppercase;
            letter-spacing: 0.04em;
        }
        .lead-table tr.is-need { background: #fff8f3; }
        .lead-badge {
            display: inline-block;
            margin-bottom: 4px;
            padding: 2px 8px;
            border-radius: 999px;
            font-size: 0.72rem;
            font-weight: 700;
            background: #ffe8d6;
            color: #9a3412;
        }
        .lead-empty { padding: 28px 18px; color: var(--muted); line-height: 1.45; }
        .lead-msg { white-space: pre-wrap; line-height: 1.4; }
        .lead-phone a { color: var(--accent-dark); font-weight: 700; text-decoration: none; }
        .lead-phone a:hover { text-decoration: underline; }
    </style>
</head>
<body>
<main class="lead-page">
    <div class="container">
        <header class="site-header">
            <a class="site-logo" href="/">yHome.ai</a>
            <div class="site-header-actions">
                <a href="/house.php?id=<?= $id ?>" class="button button-nav-cta">View listing</a>
            </div>
        </header>

        <h1>Buyer leads</h1>
        <p class="lead-sub">
            <?= lead_h($address) ?>
            <?php if ($agentName !== ''): ?>
                <br>Listed by <?= lead_h($agentName) ?><?= $broker !== '' ? ' · ' . lead_h($broker) : '' ?>
            <?php endif; ?>
        </p>

        <div class="lead-stats">
            <div class="lead-stat"><b><?= count($rows) ?></b><span>Buyer questions</span></div>
            <div class="lead-stat"><b><?= $needCount ?></b><span>Need your help</span></div>
        </div>

        <div class="lead-card">
            <?php if ($rows === []): ?>
                <p class="lead-empty">No buyer questions yet for this home. When someone texts about this listing, their phone number and question will appear here.</p>
            <?php else: ?>
                <table class="lead-table">
                    <thead>
                        <tr>
                            <th>Date</th>
                            <th>Phone</th>
                            <th>Question</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($rows as $r): ?>
                            <?php
                            $need = (int) ($r['cannot_answer'] ?? 0) === 1;
                            $phone = trim((string) ($r['buyer_phone'] ?? ''));
                            $phoneLabel = lead_phone($phone);
                            $when = (string) ($r['created_at'] ?? '');
                            ?>
                            <tr class="<?= $need ? 'is-need' : '' ?>">
                                <td><?= lead_h($when !== '' ? date('M j, Y g:ia', strtotime($when)) : '—') ?></td>
                                <td class="lead-phone">
                                    <?php if ($phone !== ''): ?>
                                        <a href="tel:<?= lead_h(preg_replace('/[^\d+]/', '', $phone) ?: $phone) ?>"><?= lead_h($phoneLabel) ?></a>
                                    <?php else: ?>
                                        —
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php if ($need): ?><span class="lead-badge">Needs your reply</span><br><?php endif; ?>
                                    <div class="lead-msg"><?= lead_h((string) ($r['message'] ?? '')) ?></div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>
    </div>
</main>
</body>
</html>
