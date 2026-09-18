<?php

declare(strict_types=1);

/**
 * Admin: all listing-agent claim requests (pending + approved).
 */

require_once __DIR__ . '/pdo_connect.php';

function claims_h(?string $s): string
{
    return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
}

$pdo = db_pdo_connect();
$error = null;
$successMsg = null;
$filter = strtolower(trim((string) ($_GET['status'] ?? 'all')));
if (!in_array($filter, ['all', 'pending', 'approved'], true)) {
    $filter = 'all';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $pdo instanceof PDO) {
    $action = trim((string) ($_POST['action'] ?? ''));
    $listingId = (int) ($_POST['listing_id'] ?? 0);
    if ($listingId <= 0) {
        $error = 'Invalid listing.';
    } elseif ($action === 'approve_claim') {
        try {
            $upd = $pdo->prepare(
                'UPDATE zillow_sale_listings
                 SET listing_agent_claimed = 1
                 WHERE id = ? AND listing_agent_claim_requested_at IS NOT NULL'
            );
            $upd->execute([$listingId]);
            $successMsg = $upd->rowCount() > 0
                ? 'Approved claim for listing #' . $listingId . '.'
                : 'No pending claim found for that listing.';
        } catch (Throwable $e) {
            $error = 'Could not approve: ' . $e->getMessage();
        }
    } elseif ($action === 'unapprove_claim') {
        try {
            $upd = $pdo->prepare(
                'UPDATE zillow_sale_listings SET listing_agent_claimed = 0 WHERE id = ?'
            );
            $upd->execute([$listingId]);
            $successMsg = 'Locked contact again for listing #' . $listingId . '.';
        } catch (Throwable $e) {
            $error = 'Could not update: ' . $e->getMessage();
        }
    }
}

$rows = [];
if ($pdo instanceof PDO) {
    try {
        $sql = 'SELECT l.id, l.address, l.listing_agent_name, l.listing_agent_phone, l.listing_agent_email,
                       l.listing_broker_name, l.listing_agent_claimed,
                       l.listing_agent_claim_agent_id, l.listing_agent_claim_requested_at,
                       a.referral_code, a.name AS partner_name, a.email AS partner_email
                FROM zillow_sale_listings l
                LEFT JOIN agents a ON a.id = l.listing_agent_claim_agent_id
                WHERE l.listing_agent_claim_requested_at IS NOT NULL
                   OR l.listing_agent_claimed = 1';
        if ($filter === 'pending') {
            $sql .= ' AND l.listing_agent_claimed = 0 AND l.listing_agent_claim_requested_at IS NOT NULL';
        } elseif ($filter === 'approved') {
            $sql .= ' AND l.listing_agent_claimed = 1';
        }
        $sql .= ' ORDER BY COALESCE(l.listing_agent_claim_requested_at, l.id) DESC LIMIT 200';
        $rows = $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) {
        $error = 'Could not load claims: ' . $e->getMessage();
    }
}

$pendingCount = 0;
$approvedCount = 0;
foreach ($rows as $r) {
    if ((int) ($r['listing_agent_claimed'] ?? 0) === 1) {
        $approvedCount++;
    } else {
        $pendingCount++;
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Listing claims — yHome.ai</title>
    <link rel="stylesheet" href="/style.css">
    <style>
        .claims-page {
            min-height: 100vh;
            padding-bottom: 64px;
            background:
                radial-gradient(ellipse 110% 80% at 0% 0%, rgba(46, 157, 120, 0.08), transparent 52%),
                linear-gradient(180deg, #ffffff 0%, #f7fbf8 55%, #edf6f1 100%);
        }
        .claims-page .container { padding-top: 24px; }
        .claims-page h1 {
            margin: 8px 0 8px;
            font-size: clamp(1.7rem, 3.5vw, 2.3rem);
            letter-spacing: -0.035em;
            font-weight: 800;
        }
        .claims-page .lead {
            margin: 0 0 18px;
            color: var(--muted);
            max-width: 40rem;
            line-height: 1.55;
        }
        .claims-filters {
            display: flex;
            flex-wrap: wrap;
            gap: 8px;
            margin-bottom: 16px;
        }
        .claims-filters a {
            display: inline-flex;
            padding: 8px 14px;
            border-radius: 999px;
            border: 1px solid var(--border);
            background: #fff;
            color: var(--text);
            text-decoration: none;
            font-weight: 700;
            font-size: 0.9rem;
        }
        .claims-filters a.is-active {
            background: #111827;
            border-color: #111827;
            color: #fff;
        }
        .claims-card {
            background: #fff;
            border: 1px solid var(--border);
            border-radius: 16px;
            padding: 8px 18px 18px;
            overflow-x: auto;
        }
        .claims-table {
            width: 100%;
            border-collapse: collapse;
            font-size: 0.92rem;
        }
        .claims-table th,
        .claims-table td {
            text-align: left;
            padding: 12px 10px;
            border-bottom: 1px solid var(--border);
            vertical-align: top;
        }
        .claims-table th {
            font-size: 0.75rem;
            text-transform: uppercase;
            letter-spacing: 0.04em;
            color: var(--muted);
            white-space: nowrap;
        }
        .claims-table a {
            color: var(--accent-dark);
            font-weight: 700;
            text-decoration: none;
        }
        .claims-table a:hover { text-decoration: underline; }
        .claims-badge {
            display: inline-block;
            padding: 3px 8px;
            border-radius: 999px;
            font-size: 0.75rem;
            font-weight: 700;
            white-space: nowrap;
        }
        .claims-badge.is-approved {
            background: #ebf8f1;
            color: #176948;
        }
        .claims-badge.is-pending {
            background: #fff7e8;
            color: #9a6700;
        }
        .claims-actions {
            display: flex;
            flex-wrap: wrap;
            gap: 8px;
            margin-top: 6px;
        }
        .claims-actions .button {
            border-radius: 999px;
            padding: 8px 12px;
            font-size: 0.85rem;
            font-weight: 700;
        }
        .claims-empty {
            margin: 18px 0 8px;
            color: var(--muted);
        }
        .claims-msg {
            margin: 0 0 14px;
            padding: 12px 14px;
            border-radius: 12px;
            font-weight: 600;
        }
        .claims-msg.is-ok {
            background: #ebf8f1;
            border: 1px solid #b7e4cd;
            color: var(--accent-dark);
        }
        .claims-msg.is-err {
            background: #fef3f2;
            border: 1px solid #fecdca;
            color: #b42318;
        }
        .claims-meta {
            color: var(--muted);
            font-size: 0.86rem;
        }
    </style>
</head>
<body>
<main class="claims-page">
    <div class="container">
        <header class="site-header">
            <a class="site-logo" href="/">yHome.ai</a>
            <div class="site-header-actions">
                <a href="/agent-create.php" class="button button-nav-cta">Create agent</a>
            </div>
        </header>

        <h1>Listing claims</h1>
        <p class="lead">All addresses agents have claimed or requested. Claim login email and scraped listing email are independent — compare them when approving.</p>

        <?php if ($successMsg): ?>
            <p class="claims-msg is-ok"><?= claims_h($successMsg) ?></p>
        <?php endif; ?>
        <?php if ($error): ?>
            <p class="claims-msg is-err"><?= claims_h($error) ?></p>
        <?php endif; ?>

        <div class="claims-filters">
            <a class="<?= $filter === 'all' ? 'is-active' : '' ?>" href="/agent-claims.php?status=all">All (<?= count($rows) ?>)</a>
            <a class="<?= $filter === 'pending' ? 'is-active' : '' ?>" href="/agent-claims.php?status=pending">Pending</a>
            <a class="<?= $filter === 'approved' ? 'is-active' : '' ?>" href="/agent-claims.php?status=approved">Approved / claimed</a>
        </div>

        <div class="claims-card">
            <?php if (!$pdo instanceof PDO): ?>
                <p class="claims-empty">Database unavailable.</p>
            <?php elseif ($rows === []): ?>
                <p class="claims-empty">No claim requests yet.</p>
            <?php else: ?>
                <table class="claims-table">
                    <thead>
                        <tr>
                            <th>Requested</th>
                            <th>Address</th>
                            <th>Listing agent</th>
                            <th>Listing contact (scraped)</th>
                            <th>Claim email (login)</th>
                            <th>Status</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($rows as $row): ?>
                            <?php
                            $id = (int) ($row['id'] ?? 0);
                            $approved = (int) ($row['listing_agent_claimed'] ?? 0) === 1;
                            $reqAt = (string) ($row['listing_agent_claim_requested_at'] ?? '');
                            $reqLabel = $reqAt !== '' ? date('M j, Y g:ia', strtotime($reqAt)) : '—';
                            $phone = trim((string) ($row['listing_agent_phone'] ?? ''));
                            $email = trim((string) ($row['listing_agent_email'] ?? ''));
                            $broker = trim((string) ($row['listing_broker_name'] ?? ''));
                            $agentName = trim((string) ($row['listing_agent_name'] ?? ''));
                            ?>
                            <tr>
                                <td><?= claims_h($reqLabel) ?></td>
                                <td>
                                    <a href="/house.php?id=<?= $id ?>"><?= claims_h(trim((string) ($row['address'] ?? '')) ?: ('Listing #' . $id)) ?></a>
                                    <div class="claims-meta">#<?= $id ?></div>
                                </td>
                                <td>
                                    <?= claims_h($agentName !== '' ? $agentName : '—') ?>
                                    <?php if ($broker !== ''): ?>
                                        <div class="claims-meta"><?= claims_h($broker) ?></div>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php if ($phone !== ''): ?>
                                        <div><?= claims_h($phone) ?></div>
                                    <?php endif; ?>
                                    <?php if ($email !== ''): ?>
                                        <div><?= claims_h($email) ?></div>
                                    <?php endif; ?>
                                    <?php if ($phone === '' && $email === ''): ?>
                                        —
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php if (!empty($row['partner_email'])): ?>
                                        <strong><?= claims_h((string) $row['partner_email']) ?></strong>
                                    <?php else: ?>
                                        —
                                    <?php endif; ?>
                                    <div class="claims-meta">
                                        <?= claims_h((string) ($row['referral_code'] ?? '')) ?>
                                        <?php if (!empty($row['partner_name'])): ?>
                                            · <?= claims_h((string) $row['partner_name']) ?>
                                        <?php endif; ?>
                                    </div>
                                </td>
                                <td>
                                    <?php if ($approved): ?>
                                        <span class="claims-badge is-approved">Approved · unlocked</span>
                                    <?php else: ?>
                                        <span class="claims-badge is-pending">Pending</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <div class="claims-actions">
                                        <?php if (!$approved): ?>
                                            <form method="post" action="/agent-claims.php?status=<?= claims_h($filter) ?>">
                                                <input type="hidden" name="action" value="approve_claim">
                                                <input type="hidden" name="listing_id" value="<?= $id ?>">
                                                <button type="submit" class="button button-primary">Approve</button>
                                            </form>
                                        <?php else: ?>
                                            <form method="post" action="/agent-claims.php?status=<?= claims_h($filter) ?>">
                                                <input type="hidden" name="action" value="unapprove_claim">
                                                <input type="hidden" name="listing_id" value="<?= $id ?>">
                                                <button type="submit" class="button">Lock again</button>
                                            </form>
                                        <?php endif; ?>
                                    </div>
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
