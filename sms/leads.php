<?php

declare(strict_types=1);

/**
 * Admin lead report: SMS buyers, questions, and cannot-answer flags.
 * Protect with ?key= from app_secrets.leads_report_key (not linked from the homepage).
 */

require_once dirname(__DIR__) . '/pdo_connect.php';

function leads_h(?string $s): string
{
    return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
}

function leads_phone(?string $phone): string
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

$expected = (string) $pdo->query("SELECT value FROM app_secrets WHERE name = 'leads_report_key' LIMIT 1")->fetchColumn();
$key = (string) ($_GET['key'] ?? '');
if ($expected === '' || !hash_equals($expected, $key)) {
    http_response_code(404);
    echo 'Not found.';
    exit;
}

$filter = strtolower(trim((string) ($_GET['filter'] ?? 'all')));
if (!in_array($filter, ['all', 'needs', 'by_home'], true)) {
    $filter = 'all';
}

$stats = $pdo->query(
    "SELECT
        (SELECT COUNT(DISTINCT buyer_phone) FROM sms_leads WHERE direction = 'in' AND buyer_phone NOT LIKE '+1555%') AS buyers,
        (SELECT COUNT(*) FROM sms_leads WHERE direction = 'in' AND buyer_phone NOT LIKE '+1555%') AS questions,
        (SELECT COUNT(*) FROM sms_leads WHERE direction = 'in' AND cannot_answer = 1 AND buyer_phone NOT LIKE '+1555%') AS needs_info,
        (SELECT COUNT(DISTINCT listing_id) FROM sms_leads WHERE listing_id > 0 AND buyer_phone NOT LIKE '+1555%') AS homes"
)->fetch(PDO::FETCH_ASSOC) ?: [];

// Test phone +1555… is the local SMS tester — hide by default, show with ?test=1
$includeTest = isset($_GET['test']);
$phoneSql = $includeTest ? '1=1' : "l.buyer_phone NOT LIKE '+1555%'";

$sql = "SELECT l.id, l.listing_id, l.buyer_phone, l.direction, l.message, l.cannot_answer, l.created_at,
               z.address, z.listing_agent_name, z.listing_agent_email, z.listing_agent_phone, z.listing_broker_name
        FROM sms_leads l
        LEFT JOIN zillow_sale_listings z ON z.id = l.listing_id
        WHERE $phoneSql";
if ($filter === 'needs') {
    $sql .= ' AND l.direction = \'in\' AND l.cannot_answer = 1';
}
$sql .= ' ORDER BY l.id DESC LIMIT 500';
$rows = $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC) ?: [];

// Group inbound questions by listing for the "by home" sales view.
$byHome = [];
if ($filter === 'by_home' || $filter === 'all') {
    $homeRows = $pdo->query(
        "SELECT l.listing_id,
                MAX(z.address) AS address,
                MAX(z.listing_agent_name) AS agent_name,
                MAX(z.listing_agent_email) AS agent_email,
                MAX(z.listing_agent_phone) AS agent_phone,
                MAX(z.listing_broker_name) AS broker,
                COUNT(DISTINCT l.buyer_phone) AS buyers,
                SUM(l.direction = 'in') AS questions,
                SUM(l.direction = 'in' AND l.cannot_answer = 1) AS needs_info,
                MAX(l.created_at) AS last_at
         FROM sms_leads l
         LEFT JOIN zillow_sale_listings z ON z.id = l.listing_id
         WHERE l.listing_id > 0 AND $phoneSql
         GROUP BY l.listing_id
         ORDER BY last_at DESC"
    )->fetchAll(PDO::FETCH_ASSOC) ?: [];
    $byHome = $homeRows;
}

$keyQ = 'key=' . rawurlencode($key) . ($includeTest ? '&test=1' : '');
$baseQ = 'key=' . rawurlencode($key);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>SMS leads — yHome</title>
    <meta name="robots" content="noindex,nofollow">
    <link rel="stylesheet" href="/style.css">
    <style>
        .leads-page { min-height: 100vh; padding-bottom: 64px; background: linear-gradient(180deg, #fff 0%, #f7fbf8 100%); }
        .leads-page .container { padding-top: 24px; max-width: 1100px; }
        .leads-page h1 { margin: 8px 0 8px; font-size: clamp(1.6rem, 3vw, 2rem); letter-spacing: -0.03em; }
        .leads-lead { color: var(--muted); margin: 0 0 20px; max-width: 40rem; }
        .leads-stats { display: grid; grid-template-columns: repeat(auto-fit, minmax(140px, 1fr)); gap: 12px; margin-bottom: 20px; }
        .leads-stat { background: #fff; border: 1px solid var(--border); border-radius: 12px; padding: 14px 16px; }
        .leads-stat b { display: block; font-size: 1.6rem; letter-spacing: -0.03em; }
        .leads-stat span { color: var(--muted); font-size: 0.9rem; }
        .leads-filters { display: flex; flex-wrap: wrap; gap: 8px; margin-bottom: 16px; }
        .leads-filters a { text-decoration: none; padding: 8px 12px; border-radius: 999px; border: 1px solid var(--border); background: #fff; color: inherit; font-weight: 600; font-size: 0.92rem; }
        .leads-filters a.is-active { background: #ebf8f1; border-color: #b7e4cd; color: var(--accent-dark); }
        .leads-card { background: #fff; border: 1px solid var(--border); border-radius: 14px; overflow: hidden; margin-bottom: 18px; }
        .leads-card h2 { margin: 0; padding: 14px 16px; font-size: 1.05rem; border-bottom: 1px solid var(--border); }
        .leads-table { width: 100%; border-collapse: collapse; font-size: 0.92rem; }
        .leads-table th, .leads-table td { text-align: left; padding: 10px 12px; border-bottom: 1px solid #eef2f0; vertical-align: top; }
        .leads-table th { color: var(--muted); font-weight: 600; font-size: 0.8rem; text-transform: uppercase; letter-spacing: 0.04em; }
        .leads-table tr.is-need { background: #fff8f3; }
        .leads-badge { display: inline-block; padding: 2px 8px; border-radius: 999px; font-size: 0.75rem; font-weight: 700; background: #ffe8d6; color: #9a3412; }
        .leads-meta { color: var(--muted); font-size: 0.82rem; margin-top: 2px; }
        .leads-empty { padding: 24px 16px; color: var(--muted); }
        .leads-msg { white-space: pre-wrap; line-height: 1.35; }
        .leads-dir-in { font-weight: 700; color: var(--accent-dark); }
        .leads-dir-out { color: var(--muted); }
    </style>
</head>
<body>
<main class="leads-page">
    <div class="container">
        <header class="site-header">
            <a class="site-logo" href="/">yHome.ai</a>
            <div class="site-header-actions">
                <a href="/houses.php" class="button button-nav-cta">Houses</a>
            </div>
        </header>

        <h1>SMS lead report</h1>
        <p class="leads-lead">Buyer texts about listings. Use this to improve answers and later show agents what leads you already have.</p>

        <div class="leads-stats">
            <div class="leads-stat"><b><?= (int) ($stats['buyers'] ?? 0) ?></b><span>Buyers</span></div>
            <div class="leads-stat"><b><?= (int) ($stats['questions'] ?? 0) ?></b><span>Questions</span></div>
            <div class="leads-stat"><b><?= (int) ($stats['needs_info'] ?? 0) ?></b><span>Need info</span></div>
            <div class="leads-stat"><b><?= (int) ($stats['homes'] ?? 0) ?></b><span>Homes</span></div>
        </div>

        <div class="leads-filters">
            <a class="<?= $filter === 'all' ? 'is-active' : '' ?>" href="?<?= leads_h($keyQ) ?>&filter=all">All messages</a>
            <a class="<?= $filter === 'needs' ? 'is-active' : '' ?>" href="?<?= leads_h($keyQ) ?>&filter=needs">Need info</a>
            <a class="<?= $filter === 'by_home' ? 'is-active' : '' ?>" href="?<?= leads_h($keyQ) ?>&filter=by_home">By home</a>
            <?php if ($includeTest): ?>
                <a href="?<?= leads_h($baseQ) ?>&filter=<?= leads_h($filter) ?>">Hide test chats</a>
            <?php else: ?>
                <a href="?<?= leads_h($baseQ) ?>&filter=<?= leads_h($filter) ?>&test=1">Show test chats</a>
            <?php endif; ?>
        </div>

        <?php if ($filter === 'by_home'): ?>
            <div class="leads-card">
                <h2>Leads by home</h2>
                <?php if ($byHome === []): ?>
                    <p class="leads-empty">No listing leads yet. When buyers text about a home, they show up here.</p>
                <?php else: ?>
                    <table class="leads-table">
                        <thead>
                            <tr>
                                <th>Home</th>
                                <th>Agent</th>
                                <th>Buyers</th>
                                <th>Questions</th>
                                <th>Need info</th>
                                <th>Last activity</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($byHome as $h): ?>
                                <?php $lid = (int) ($h['listing_id'] ?? 0); ?>
                                <tr>
                                    <td>
                                        <a href="/house.php?id=<?= $lid ?>"><?= leads_h((string) ($h['address'] ?? ('Listing #' . $lid))) ?></a>
                                        <div class="leads-meta">#<?= $lid ?></div>
                                    </td>
                                    <td>
                                        <?= leads_h(trim((string) ($h['agent_name'] ?? '')) ?: '—') ?>
                                        <div class="leads-meta"><?= leads_h(trim((string) ($h['agent_email'] ?? '')) ?: trim((string) ($h['agent_phone'] ?? '')) ?: '') ?></div>
                                    </td>
                                    <td><?= (int) ($h['buyers'] ?? 0) ?></td>
                                    <td><?= (int) ($h['questions'] ?? 0) ?></td>
                                    <td><?= (int) ($h['needs_info'] ?? 0) ?></td>
                                    <td><?= leads_h($h['last_at'] ? date('M j, g:ia', strtotime((string) $h['last_at'])) : '—') ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php endif; ?>
            </div>
        <?php else: ?>
            <div class="leads-card">
                <h2><?= $filter === 'needs' ? 'Questions that need info' : 'Recent messages' ?></h2>
                <?php if ($rows === []): ?>
                    <p class="leads-empty">No messages yet. Text the bot or use the local SMS test page.</p>
                <?php else: ?>
                    <table class="leads-table">
                        <thead>
                            <tr>
                                <th>When</th>
                                <th>Buyer</th>
                                <th>Home</th>
                                <th>Message</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($rows as $r): ?>
                                <?php
                                $need = (int) ($r['cannot_answer'] ?? 0) === 1;
                                $dir = (string) ($r['direction'] ?? 'in');
                                $lid = (int) ($r['listing_id'] ?? 0);
                                $addr = trim((string) ($r['address'] ?? ''));
                                ?>
                                <tr class="<?= $need ? 'is-need' : '' ?>">
                                    <td>
                                        <?= leads_h(date('M j, g:ia', strtotime((string) $r['created_at']))) ?>
                                        <div class="leads-meta <?= $dir === 'in' ? 'leads-dir-in' : 'leads-dir-out' ?>"><?= $dir === 'in' ? 'Buyer' : 'Bot' ?></div>
                                    </td>
                                    <td><?= leads_h(leads_phone($r['buyer_phone'] ?? null)) ?></td>
                                    <td>
                                        <?php if ($lid > 0): ?>
                                            <a href="/house.php?id=<?= $lid ?>"><?= leads_h($addr !== '' ? $addr : ('#' . $lid)) ?></a>
                                        <?php else: ?>
                                            —
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php if ($need): ?><span class="leads-badge">need info</span><?php endif; ?>
                                        <div class="leads-msg"><?= leads_h((string) ($r['message'] ?? '')) ?></div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php endif; ?>
            </div>
        <?php endif; ?>
    </div>
</main>
</body>
</html>
