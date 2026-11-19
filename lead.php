<?php

declare(strict_types=1);

/**
 * Simple test lead page for a house (no login).
 * Usage: /lead.php?id=2548  — same id as house.php
 * Omit id to list leads for every home (testing only).
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
$listing = null;
if ($id > 0) {
    $stmt = $pdo->prepare(
        'SELECT id, address, list_price, beds, baths, sqft, listing_agent_name, listing_agent_email, listing_broker_name
         FROM zillow_sale_listings WHERE id = ? LIMIT 1'
    );
    $stmt->execute([$id]);
    $listing = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    if ($listing === null) {
        http_response_code(404);
        echo 'Listing not found.';
        exit;
    }
}

if ($id > 0) {
    $stmt = $pdo->prepare(
        "SELECT id, listing_id, buyer_phone, direction, message, cannot_answer, created_at
         FROM sms_leads
         WHERE listing_id = ?
         ORDER BY id DESC
         LIMIT 500"
    );
    $stmt->execute([$id]);
} else {
    $stmt = $pdo->query(
        "SELECT l.id, l.listing_id, l.buyer_phone, l.direction, l.message, l.cannot_answer, l.created_at, z.address
         FROM sms_leads l
         LEFT JOIN zillow_sale_listings z ON z.id = l.listing_id
         ORDER BY l.id DESC
         LIMIT 500"
    );
}
$rows = $stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [];

$inCount = 0;
$needCount = 0;
foreach ($rows as $r) {
    if (($r['direction'] ?? '') === 'in') {
        $inCount++;
        if ((int) ($r['cannot_answer'] ?? 0) === 1) {
            $needCount++;
        }
    }
}

$titleAddr = $listing ? (string) $listing['address'] : 'All homes';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Leads — <?= lead_h($titleAddr) ?> — yHome</title>
    <meta name="robots" content="noindex,nofollow">
    <link rel="stylesheet" href="/style.css">
    <style>
        .lead-page {
            min-height: 100vh;
            padding-bottom: 64px;
            background: linear-gradient(180deg, #fff 0%, #f7fbf8 100%);
        }
        .lead-page .container { padding-top: 24px; max-width: 960px; }
        .lead-page h1 {
            margin: 8px 0 6px;
            font-size: clamp(1.5rem, 3vw, 2rem);
            letter-spacing: -0.03em;
        }
        .lead-sub { margin: 0 0 8px; color: var(--muted); }
        .lead-note {
            margin: 0 0 18px;
            padding: 10px 14px;
            background: #fff8e8;
            border: 1px solid #f5d78e;
            border-radius: 12px;
            font-size: 0.9rem;
            color: #7a5b00;
        }
        .lead-stats { display: flex; flex-wrap: wrap; gap: 10px; margin-bottom: 16px; }
        .lead-stat {
            background: #fff;
            border: 1px solid var(--border);
            border-radius: 12px;
            padding: 10px 14px;
            min-width: 110px;
        }
        .lead-stat b { display: block; font-size: 1.35rem; }
        .lead-stat span { color: var(--muted); font-size: 0.85rem; }
        .lead-card {
            background: #fff;
            border: 1px solid var(--border);
            border-radius: 14px;
            overflow: hidden;
        }
        .lead-table { width: 100%; border-collapse: collapse; font-size: 0.92rem; }
        .lead-table th, .lead-table td {
            text-align: left;
            padding: 10px 12px;
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
            margin-right: 6px;
            padding: 2px 8px;
            border-radius: 999px;
            font-size: 0.72rem;
            font-weight: 700;
            background: #ffe8d6;
            color: #9a3412;
        }
        .lead-dir { font-size: 0.8rem; color: var(--muted); margin-top: 2px; }
        .lead-dir.in { color: var(--accent-dark); font-weight: 700; }
        .lead-empty { padding: 24px 16px; color: var(--muted); }
        .lead-msg { white-space: pre-wrap; line-height: 1.35; }
        .lead-links { margin: 0 0 16px; display: flex; flex-wrap: wrap; gap: 12px; }
        .lead-links a { font-weight: 700; color: var(--accent-dark); }
    </style>
</head>
<body>
<main class="lead-page">
    <div class="container">
        <header class="site-header">
            <a class="site-logo" href="/">yHome.ai</a>
            <div class="site-header-actions">
                <?php if ($id > 0): ?>
                    <a href="/house.php?id=<?= $id ?>" class="button button-nav-cta">House page</a>
                <?php else: ?>
                    <a href="/houses.php" class="button button-nav-cta">All houses</a>
                <?php endif; ?>
            </div>
        </header>

        <h1>Leads<?= $listing ? ' — ' . lead_h((string) $listing['address']) : '' ?></h1>
        <?php if ($listing): ?>
            <p class="lead-sub">
                Listing #<?= (int) $listing['id'] ?>
                <?php if (!empty($listing['listing_agent_name'])): ?>
                    · <?= lead_h((string) $listing['listing_agent_name']) ?>
                <?php endif; ?>
            </p>
        <?php else: ?>
            <p class="lead-sub">All SMS leads (testing). Add <code>?id=2548</code> to filter one house.</p>
        <?php endif; ?>

        <p class="lead-note">Testing page — no login. Don’t share publicly; it shows buyer phone numbers.</p>

        <div class="lead-links">
            <?php if ($id > 0): ?>
                <a href="/lead.php">See all homes’ leads</a>
                <a href="/house.php?id=<?= $id ?>">Open house.php?id=<?= $id ?></a>
            <?php else: ?>
                <a href="/houses.php">Pick a house</a>
            <?php endif; ?>
            <a href="/sms/test.php">SMS test chat</a>
        </div>

        <div class="lead-stats">
            <div class="lead-stat"><b><?= $inCount ?></b><span>Buyer messages</span></div>
            <div class="lead-stat"><b><?= $needCount ?></b><span>Need info</span></div>
            <div class="lead-stat"><b><?= count($rows) ?></b><span>All rows (in+out)</span></div>
        </div>

        <div class="lead-card">
            <?php if ($rows === []): ?>
                <p class="lead-empty">No leads yet. Use the SMS test page or text the listing number, then refresh.</p>
            <?php else: ?>
                <table class="lead-table">
                    <thead>
                        <tr>
                            <th>When</th>
                            <?php if ($id <= 0): ?><th>Home</th><?php endif; ?>
                            <th>Buyer</th>
                            <th>Message</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($rows as $r): ?>
                            <?php
                            $need = (int) ($r['cannot_answer'] ?? 0) === 1;
                            $dir = (string) ($r['direction'] ?? 'in');
                            $lid = (int) ($r['listing_id'] ?? 0);
                            $when = (string) ($r['created_at'] ?? '');
                            ?>
                            <tr class="<?= $need && $dir === 'in' ? 'is-need' : '' ?>">
                                <td>
                                    <?= lead_h($when !== '' ? date('M j, g:ia', strtotime($when)) : '—') ?>
                                    <div class="lead-dir <?= $dir === 'in' ? 'in' : '' ?>"><?= $dir === 'in' ? 'Buyer' : 'Bot' ?></div>
                                </td>
                                <?php if ($id <= 0): ?>
                                    <td>
                                        <?php if ($lid > 0): ?>
                                            <a href="/lead.php?id=<?= $lid ?>"><?= lead_h(trim((string) ($r['address'] ?? '')) ?: ('#' . $lid)) ?></a>
                                        <?php else: ?>
                                            —
                                        <?php endif; ?>
                                    </td>
                                <?php endif; ?>
                                <td><?= lead_h(lead_phone($r['buyer_phone'] ?? null)) ?></td>
                                <td>
                                    <?php if ($need && $dir === 'in'): ?><span class="lead-badge">need info</span><?php endif; ?>
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
