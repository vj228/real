<?php

declare(strict_types=1);

/**
 * Access: enter your email (any email — creates account if new).
 */

require_once __DIR__ . '/pdo_connect.php';
require_once __DIR__ . '/helpers/agent_referral.php';

agent_ref_ensure_session();

function dash_h(?string $s): string
{
    return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
}

function dash_money($n): string
{
    return '$' . number_format((float) $n, 2);
}

$pdo = db_pdo_connect();
$error = null;
$flash = null;
$agent = null;

if (isset($_GET['logout'])) {
    unset($_SESSION['agent_dashboard_code'], $_SESSION['agent_dashboard_email']);
    header('Location: /agent-dashboard.php');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = trim((string) ($_POST['action'] ?? 'login'));

    if ($action === 'claim_listing') {
        $claimId = (int) ($_POST['listing_id'] ?? 0);
        $sessionEmail = agent_ref_normalize_email((string) ($_SESSION['agent_dashboard_email'] ?? ''));
        $sessionCode = agent_ref_normalize((string) ($_SESSION['agent_dashboard_code'] ?? ''));
        $found = null;
        if ($pdo instanceof PDO && $sessionEmail !== null) {
            $found = agent_ref_find_agent_by_email($pdo, $sessionEmail);
        } elseif ($pdo instanceof PDO && $sessionCode !== null) {
            $found = agent_ref_find_agent($pdo, $sessionCode);
        }
        if ($found === null || !$pdo instanceof PDO) {
            $error = 'Sign in with your email before claiming.';
        } elseif ($claimId <= 0) {
            $error = 'Invalid listing.';
        } else {
            $cStmt = $pdo->prepare(
                'SELECT id, listing_agent_email, listing_agent_claimed, listing_agent_claim_requested_at
                 FROM zillow_sale_listings WHERE id = ? AND is_active = 1 LIMIT 1'
            );
            $cStmt->execute([$claimId]);
            $claimRow = $cStmt->fetch(PDO::FETCH_ASSOC);
            if (!is_array($claimRow)) {
                $error = 'Listing not found or inactive.';
            } elseif ((int) ($claimRow['listing_agent_claimed'] ?? 0) === 1) {
                $flash = 'This listing is already claimed and unlocked.';
                header('Location: /agent-dashboard.php?claim_listing=' . $claimId);
                exit;
            } else {
                $upd = $pdo->prepare(
                    'UPDATE zillow_sale_listings
                     SET listing_agent_claim_agent_id = ?, listing_agent_claim_requested_at = NOW()
                     WHERE id = ?'
                );
                $upd->execute([(int) $found['id'], $claimId]);
                header('Location: /agent-dashboard.php?claim_listing=' . $claimId . '&claimed=1');
                exit;
            }
        }
    } else {
        $postedEmail = agent_ref_normalize_email((string) ($_POST['email'] ?? ''));
        if ($postedEmail === null) {
            $error = 'Enter a valid email address.';
        } elseif (!$pdo instanceof PDO) {
            $error = 'Database unavailable.';
        } else {
            $found = agent_ref_login_by_email($pdo, $postedEmail);
            if ($found === null) {
                $error = 'Could not sign in with that email.';
            } else {
                $_SESSION['agent_dashboard_code'] = $found['referral_code'];
                $_SESSION['agent_dashboard_email'] = $found['email'];
                $redirectClaim = (int) ($_POST['claim_listing'] ?? 0);
                if ($redirectClaim > 0) {
                    header('Location: /agent-dashboard.php?claim_listing=' . $redirectClaim);
                } else {
                    header('Location: /agent-dashboard.php');
                }
                exit;
            }
        }
    }
}

$sessionEmail = agent_ref_normalize_email(
    (string) ($_GET['email'] ?? $_SESSION['agent_dashboard_email'] ?? '')
);
$sessionCode = agent_ref_normalize((string) ($_SESSION['agent_dashboard_code'] ?? ''));
if ($pdo instanceof PDO) {
    if ($sessionEmail !== null) {
        $agent = agent_ref_find_agent_by_email($pdo, $sessionEmail);
        if ($agent === null) {
            $agent = agent_ref_login_by_email($pdo, $sessionEmail);
        }
    } elseif ($sessionCode !== null) {
        // Back-compat for old sessions / ?code=
        $agent = agent_ref_find_agent($pdo, $sessionCode);
    }
    if ($agent !== null) {
        $_SESSION['agent_dashboard_code'] = $agent['referral_code'];
        $_SESSION['agent_dashboard_email'] = $agent['email'];
    } elseif ($sessionEmail !== null && !isset($_POST['email'])) {
        $error = 'Could not sign in with that email.';
    }
}

$claimListingId = isset($_GET['claim_listing']) ? (int) $_GET['claim_listing'] : 0;
$claimListingAddress = null;
$claimListingAgentName = null;
$claimStatus = null; // none | pending | approved
$claimRequestedAt = null;
$claimRequesterCode = null;
if ($claimListingId > 0 && $pdo instanceof PDO) {
    $cStmt = $pdo->prepare(
        'SELECT l.address, l.listing_agent_name, l.listing_agent_claimed,
                l.listing_agent_claim_agent_id, l.listing_agent_claim_requested_at,
                a.referral_code AS claim_referral_code
         FROM zillow_sale_listings l
         LEFT JOIN agents a ON a.id = l.listing_agent_claim_agent_id
         WHERE l.id = ? AND l.is_active = 1 LIMIT 1'
    );
    $cStmt->execute([$claimListingId]);
    $claimRow = $cStmt->fetch(PDO::FETCH_ASSOC);
    if (is_array($claimRow)) {
        $claimListingAddress = trim((string) ($claimRow['address'] ?? ''));
        $claimListingAgentName = trim((string) ($claimRow['listing_agent_name'] ?? ''));
        $claimRequestedAt = !empty($claimRow['listing_agent_claim_requested_at'])
            ? (string) $claimRow['listing_agent_claim_requested_at']
            : null;
        $claimRequesterCode = trim((string) ($claimRow['claim_referral_code'] ?? ''));
        if ((int) ($claimRow['listing_agent_claimed'] ?? 0) === 1) {
            $claimStatus = 'approved';
        } elseif ($claimRequestedAt !== null) {
            $claimStatus = 'pending';
        } else {
            $claimStatus = 'none';
        }
    } else {
        $claimListingId = 0;
    }
}
if (isset($_GET['claimed']) && (string) $_GET['claimed'] === '1') {
    $flash = 'Claim request submitted. yHome will verify and unlock contact on the public listing page.';
}

$stats = [
    'link_visits' => 0,
    'walkthroughs' => 0,
    'inquiries' => 0,
    'pending_earnings' => 0.0,
    'approved_earnings' => 0.0,
    'paid_earnings' => 0.0,
];
$activity = [];
$myClaims = [];
$sampleListingId = 1;
$sampleAddress = 'example property';
$referralLink = '';

if ($agent !== null && $pdo instanceof PDO) {
    $agentId = (int) $agent['id'];

    $c = $pdo->prepare('SELECT COUNT(*) FROM agent_referrals WHERE agent_id = ?');
    $c->execute([$agentId]);
    $stats['link_visits'] = (int) $c->fetchColumn();

    $c = $pdo->prepare(
        "SELECT COUNT(*) FROM agent_referrals
         WHERE agent_id = ?
           AND status IN ('walkthrough_uploaded','renovation_inquiry','qualified','paid')"
    );
    $c->execute([$agentId]);
    $stats['walkthroughs'] = (int) $c->fetchColumn();

    $c = $pdo->prepare(
        "SELECT COUNT(*) FROM agent_referrals
         WHERE agent_id = ?
           AND status IN ('renovation_inquiry','qualified','paid')"
    );
    $c->execute([$agentId]);
    $stats['inquiries'] = (int) $c->fetchColumn();

    $c = $pdo->prepare(
        "SELECT COALESCE(SUM(earnings_amount),0) FROM agent_referrals
         WHERE agent_id = ? AND status IN ('visited','walkthrough_uploaded','renovation_inquiry')"
    );
    $c->execute([$agentId]);
    $stats['pending_earnings'] = (float) $c->fetchColumn();

    $c = $pdo->prepare(
        "SELECT COALESCE(SUM(earnings_amount),0) FROM agent_referrals
         WHERE agent_id = ? AND status = 'qualified'"
    );
    $c->execute([$agentId]);
    $stats['approved_earnings'] = (float) $c->fetchColumn();

    $c = $pdo->prepare(
        "SELECT COALESCE(SUM(earnings_amount),0) FROM agent_referrals
         WHERE agent_id = ? AND status = 'paid'"
    );
    $c->execute([$agentId]);
    $stats['paid_earnings'] = (float) $c->fetchColumn();

    $list = $pdo->query(
        'SELECT id, address FROM zillow_sale_listings WHERE is_active = 1 ORDER BY id ASC LIMIT 1'
    );
    $sample = $list ? $list->fetch(PDO::FETCH_ASSOC) : null;
    if ($sample) {
        $sampleListingId = (int) $sample['id'];
        $sampleAddress = (string) $sample['address'];
    }

    $referralLink = agent_ref_public_base_url()
        . '/house.php?id=' . $sampleListingId
        . '&ref=' . rawurlencode($agent['referral_code']);

    $aStmt = $pdo->prepare(
        'SELECT r.created_at, r.updated_at, r.status, r.earnings_amount, r.listing_id,
                l.address AS property_address
         FROM agent_referrals r
         LEFT JOIN zillow_sale_listings l ON l.id = r.listing_id
         WHERE r.agent_id = ?
         ORDER BY r.updated_at DESC, r.id DESC
         LIMIT 100'
    );
    $aStmt->execute([$agentId]);
    $activity = $aStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

    $myClaims = [];
    try {
        $claimStmt = $pdo->prepare(
            'SELECT id, address, listing_agent_name, listing_agent_phone, listing_agent_email,
                    listing_broker_name, listing_agent_claimed, listing_agent_claim_requested_at
             FROM zillow_sale_listings
             WHERE listing_agent_claim_agent_id = ?
             ORDER BY listing_agent_claim_requested_at DESC, id DESC
             LIMIT 100'
        );
        $claimStmt->execute([$agentId]);
        $myClaims = $claimStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) {
        $myClaims = [];
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Agent Partner — yHome.ai</title>
    <meta name="description" content="yHome agent partner dashboard — track referral link visits and buyer renovation activity.">
    <link rel="stylesheet" href="/style.css">
    <style>
        .agent-page {
            min-height: 100vh;
            padding-bottom: 64px;
            background:
                radial-gradient(ellipse 110% 80% at 0% 0%, rgba(46, 157, 120, 0.08), transparent 52%),
                linear-gradient(180deg, #ffffff 0%, #f7fbf8 55%, #edf6f1 100%);
        }
        .agent-page .container { padding-top: 24px; }
        .agent-hero h1 {
            margin: 8px 0 8px;
            font-size: clamp(1.7rem, 3.5vw, 2.3rem);
            letter-spacing: -0.035em;
            font-weight: 800;
        }
        .agent-hero p {
            margin: 0 0 8px;
            color: var(--muted);
            max-width: 40rem;
            line-height: 1.55;
        }
        .agent-hero__note {
            margin: 12px 0 0;
            max-width: 40rem;
            padding: 14px 16px;
            background: #ebf8f1;
            border: 1px solid #b7e4cd;
            border-radius: 14px;
            color: var(--accent-dark);
            font-size: 0.95rem;
            line-height: 1.45;
        }
        .agent-login {
            margin-top: 28px;
            max-width: 420px;
            padding: 22px;
            background: #fff;
            border: 1px solid var(--border);
            border-radius: 16px;
        }
        .agent-login label {
            display: block;
            font-weight: 700;
            margin-bottom: 8px;
        }
        .agent-login input {
            width: 100%;
            padding: 12px 14px;
            border: 1px solid var(--border);
            border-radius: 12px;
            font: inherit;
            margin-bottom: 12px;
        }
        .agent-error {
            color: #b42318;
            font-weight: 600;
            margin: 0 0 12px;
        }
        .agent-meta {
            margin: 6px 0 24px;
            color: var(--muted);
            font-size: 0.95rem;
        }
        .agent-meta strong { color: var(--text); }
        .agent-stats {
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 12px;
            margin: 20px 0 28px;
        }
        @media (min-width: 800px) {
            .agent-stats { grid-template-columns: repeat(3, minmax(0, 1fr)); }
        }
        .agent-stat {
            background: #fff;
            border: 1px solid var(--border);
            border-radius: 14px;
            padding: 16px;
        }
        .agent-stat span {
            display: block;
            color: var(--muted);
            font-size: 0.82rem;
            font-weight: 700;
            letter-spacing: 0.02em;
            text-transform: uppercase;
            margin-bottom: 6px;
        }
        .agent-stat strong {
            font-size: 1.45rem;
            letter-spacing: -0.03em;
            font-variant-numeric: tabular-nums;
        }
        .agent-section {
            margin: 0 0 28px;
            padding: 22px;
            background: #fff;
            border: 1px solid var(--border);
            border-radius: 16px;
        }
        .agent-section h2 {
            margin: 0 0 8px;
            font-size: 1.2rem;
            letter-spacing: -0.02em;
        }
        .agent-section > p {
            margin: 0 0 14px;
            color: var(--muted);
            line-height: 1.5;
        }
        .agent-link-row {
            display: flex;
            flex-wrap: wrap;
            gap: 10px;
            align-items: stretch;
        }
        .agent-link-row input {
            flex: 1 1 240px;
            min-width: 0;
            padding: 12px 14px;
            border: 1px solid var(--border);
            border-radius: 12px;
            font: inherit;
            background: #f8faf9;
        }
        .agent-link-row .button {
            border-radius: 999px;
            padding: 12px 18px;
            font-weight: 800;
        }
        .agent-link-hint {
            margin: 10px 0 0;
            font-size: 0.88rem;
            color: var(--muted);
        }
        .agent-table-wrap { overflow-x: auto; }
        table.agent-table {
            width: 100%;
            border-collapse: collapse;
            font-size: 0.92rem;
        }
        .agent-table th,
        .agent-table td {
            text-align: left;
            padding: 12px 10px;
            border-bottom: 1px solid var(--border);
            vertical-align: top;
        }
        .agent-table th {
            color: var(--muted);
            font-size: 0.78rem;
            text-transform: uppercase;
            letter-spacing: 0.04em;
        }
        .agent-table a {
            color: var(--accent-dark);
            font-weight: 700;
            text-decoration: none;
        }
        .agent-table a:hover { text-decoration: underline; }
        .agent-empty {
            margin: 0;
            color: var(--muted);
            padding: 8px 0;
        }
        .agent-muted {
            color: var(--muted);
            font-size: 0.9rem;
        }
        .agent-badge {
            display: inline-block;
            padding: 3px 8px;
            border-radius: 999px;
            background: #ebf8f1;
            color: #176948;
            font-size: 0.75rem;
            font-weight: 700;
            white-space: nowrap;
        }
        .agent-copy-ok {
            display: none;
            margin: 8px 0 0;
            color: var(--accent-dark);
            font-weight: 700;
            font-size: 0.9rem;
        }
        .agent-copy-ok.show { display: block; }
    </style>
</head>
<body>
<main class="agent-page">
    <div class="container">
        <header class="site-header">
            <a class="site-logo" href="/">yHome.ai</a>
            <div class="site-header-actions">
                <?php if ($agent !== null): ?>
                    <a href="/agent-dashboard.php?logout=1" class="button button-nav-cta">Sign out</a>
                <?php else: ?>
                    <a href="/" class="button button-nav-cta">Home</a>
                <?php endif; ?>
            </div>
        </header>

        <div class="agent-hero">
            <h1>Agent Partner</h1>
            <p>Share yhome property analyses with your buyers and track the renovation referrals you generate.</p>
            <p class="agent-hero__note">Local renovation partners are currently being onboarded. Eligible referral rewards will appear here as partner programs become available.</p>
            <?php if ($flash): ?>
                <p class="agent-hero__note"><?= dash_h($flash) ?></p>
            <?php endif; ?>
            <?php if ($claimListingId > 0): ?>
                <div class="agent-hero__note">
                    <strong>Listing #<?= (int) $claimListingId ?></strong>
                    <?php if ($claimListingAddress): ?>
                        — <?= dash_h($claimListingAddress) ?>
                    <?php endif; ?>
                    <?php if ($claimListingAgentName): ?>
                        <br>Listed as <?= dash_h($claimListingAgentName) ?>
                    <?php endif; ?>
                    <br>
                    <?php if ($claimStatus === 'approved'): ?>
                        Status: <strong>approved</strong> — phone &amp; email are unlocked on the public page.
                    <?php elseif ($claimStatus === 'pending'): ?>
                        Status: <strong>pending verification</strong>
                        <?php if ($claimRequesterCode !== ''): ?>
                            (requested by <?= dash_h($claimRequesterCode) ?><?= $claimRequestedAt ? ' at ' . dash_h($claimRequestedAt) : '' ?>)
                        <?php endif; ?>
                        . yHome will unlock contact after verifying you’re the listing agent.
                    <?php elseif ($agent !== null): ?>
                        Confirm you are the listing agent for this property, then submit a claim request.
                        <form method="post" action="/agent-dashboard.php?claim_listing=<?= (int) $claimListingId ?>" style="margin-top:12px;">
                            <input type="hidden" name="action" value="claim_listing">
                            <input type="hidden" name="listing_id" value="<?= (int) $claimListingId ?>">
                            <button type="submit" class="button button-primary">Submit claim request</button>
                        </form>
                    <?php else: ?>
                        Sign in with your email below, then submit a claim request.
                    <?php endif; ?>
                    <br><a href="/house.php?id=<?= (int) $claimListingId ?>">View listing</a>
                </div>
            <?php endif; ?>
        </div>

        <?php if ($agent === null): ?>
            <form class="agent-login" method="post" action="/agent-dashboard.php">
                <?php if ($error): ?>
                    <p class="agent-error"><?= dash_h($error) ?></p>
                <?php endif; ?>
                <label for="email">Enter your email</label>
                <input id="email" name="email" type="email" required autocomplete="email"
                       placeholder="e.g. you@email.com"
                       value="<?= dash_h((string) ($_POST['email'] ?? $_GET['email'] ?? $_SESSION['agent_dashboard_email'] ?? '')) ?>">
                <?php if ($claimListingId > 0): ?>
                    <input type="hidden" name="claim_listing" value="<?= (int) $claimListingId ?>">
                <?php endif; ?>
                <button type="submit" class="button button-primary">Open dashboard</button>
            </form>
        <?php else: ?>
            <p class="agent-meta">
                Signed in as <strong><?= dash_h($agent['email'] !== '' ? $agent['email'] : $agent['referral_code']) ?></strong>
                <?php if ($agent['name'] !== ''): ?>
                    · <?= dash_h($agent['name']) ?>
                <?php endif; ?>
                <?php if ($agent['email'] !== '' && $agent['referral_code'] !== ''): ?>
                    <span class="agent-muted"> · ref <?= dash_h($agent['referral_code']) ?></span>
                <?php endif; ?>
            </p>

            <div class="agent-stats">
                <div class="agent-stat"><span>Link Visits</span><strong><?= (int) $stats['link_visits'] ?></strong></div>
                <div class="agent-stat"><span>Buyer Walkthroughs</span><strong><?= (int) $stats['walkthroughs'] ?></strong></div>
                <div class="agent-stat"><span>Renovation Inquiries</span><strong><?= (int) $stats['inquiries'] ?></strong></div>
                <div class="agent-stat"><span>Pending Earnings</span><strong><?= dash_h(dash_money($stats['pending_earnings'])) ?></strong></div>
                <div class="agent-stat"><span>Approved Earnings</span><strong><?= dash_h(dash_money($stats['approved_earnings'])) ?></strong></div>
                <div class="agent-stat"><span>Paid Earnings</span><strong><?= dash_h(dash_money($stats['paid_earnings'])) ?></strong></div>
            </div>

            <section class="agent-section">
                <h2>Listing claims</h2>
                <?php if ($myClaims === []): ?>
                    <p class="agent-empty">No listing claims yet. On a house page, click <strong>Claim this listing</strong>, then submit a claim request here. Your login email does not need to match the scraped listing email.</p>
                <?php else: ?>
                    <div class="agent-table-wrap">
                        <table class="agent-table">
                            <thead>
                                <tr>
                                    <th>Requested</th>
                                    <th>Property</th>
                                    <th>Listing agent</th>
                                    <th>Broker</th>
                                    <th>Listing email (scraped)</th>
                                    <th>Your claim email</th>
                                    <th>Status</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($myClaims as $claim): ?>
                                    <?php
                                    $cid = (int) ($claim['id'] ?? 0);
                                    $approved = (int) ($claim['listing_agent_claimed'] ?? 0) === 1;
                                    $reqAt = (string) ($claim['listing_agent_claim_requested_at'] ?? '');
                                    $reqLabel = $reqAt !== '' ? date('M j, Y g:ia', strtotime($reqAt)) : '—';
                                    $cPhone = trim((string) ($claim['listing_agent_phone'] ?? ''));
                                    $listingEmail = trim((string) ($claim['listing_agent_email'] ?? ''));
                                    $claimEmail = trim((string) ($agent['email'] ?? ''));
                                    ?>
                                    <tr>
                                        <td><?= dash_h($reqLabel) ?></td>
                                        <td>
                                            <?php if ($cid > 0): ?>
                                                <a href="/house.php?id=<?= $cid ?>">
                                                    <?= dash_h(trim((string) ($claim['address'] ?? '')) ?: ('Listing #' . $cid)) ?>
                                                </a>
                                            <?php else: ?>
                                                —
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <?= dash_h(trim((string) ($claim['listing_agent_name'] ?? '')) ?: '—') ?>
                                            <?php if ($cPhone !== ''): ?>
                                                <div class="agent-muted"><?= $approved ? dash_h($cPhone) : 'Phone locked' ?></div>
                                            <?php endif; ?>
                                        </td>
                                        <td><?= dash_h(trim((string) ($claim['listing_broker_name'] ?? '')) ?: '—') ?></td>
                                        <td><?= dash_h($listingEmail !== '' ? $listingEmail : '—') ?></td>
                                        <td><?= dash_h($claimEmail !== '' ? $claimEmail : '—') ?></td>
                                        <td>
                                            <?php if ($approved): ?>
                                                <span class="agent-badge">Approved · unlocked</span>
                                            <?php else: ?>
                                                <span class="agent-badge">Pending verification</span>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </section>

            <section class="agent-section">
                <h2>Your Referral Link</h2>
                <p>Share a property page with your code attached. Example uses listing #<?= (int) $sampleListingId ?>.</p>
                <div class="agent-link-row">
                    <input id="referral-link" type="text" readonly value="<?= dash_h($referralLink) ?>">
                    <button type="button" class="button button-primary" id="copy-link">Copy Link</button>
                </div>
                <p class="agent-copy-ok" id="copy-ok">Copied</p>
                <p class="agent-link-hint">
                    Pattern: <code>/house.php?id=PROPERTY_ID&amp;ref=<?= dash_h($agent['referral_code']) ?></code>
                    · Sample property: <?= dash_h($sampleAddress) ?>
                </p>
            </section>

            <section class="agent-section">
                <h2>Referral activity</h2>
                <?php if ($activity === []): ?>
                    <p class="agent-empty">No referral activity yet. Share your link to start tracking visits.</p>
                <?php else: ?>
                    <div class="agent-table-wrap">
                        <table class="agent-table">
                            <thead>
                                <tr>
                                    <th>Date</th>
                                    <th>Property</th>
                                    <th>Buyer activity</th>
                                    <th>Status</th>
                                    <th>Earnings</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($activity as $row): ?>
                                    <?php
                                    $listingId = (int) ($row['listing_id'] ?? 0);
                                    $addr = trim((string) ($row['property_address'] ?? ''));
                                    $status = (string) ($row['status'] ?? 'visited');
                                    $when = (string) ($row['updated_at'] ?? $row['created_at'] ?? '');
                                    $whenLabel = $when !== '' ? date('M j, Y g:ia', strtotime($when)) : '—';
                                    ?>
                                    <tr>
                                        <td><?= dash_h($whenLabel) ?></td>
                                        <td>
                                            <?php if ($listingId > 0): ?>
                                                <a href="/house.php?id=<?= $listingId ?>&amp;ref=<?= dash_h($agent['referral_code']) ?>">
                                                    <?= dash_h($addr !== '' ? $addr : ('Listing #' . $listingId)) ?>
                                                </a>
                                            <?php else: ?>
                                                —
                                            <?php endif; ?>
                                        </td>
                                        <td><?= dash_h(agent_ref_activity_label($status)) ?></td>
                                        <td><span class="agent-badge"><?= dash_h(agent_ref_status_label($status)) ?></span></td>
                                        <td><?= dash_h(dash_money($row['earnings_amount'] ?? 0)) ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </section>

            <section class="agent-section">
                <h2>Renovation Partners</h2>
                <p>Local renovation partners coming soon.</p>
            </section>
        <?php endif; ?>
    </div>
</main>
<?php if ($agent !== null): ?>
<script>
(function () {
    const btn = document.getElementById('copy-link');
    const input = document.getElementById('referral-link');
    const ok = document.getElementById('copy-ok');
    if (!btn || !input) return;
    btn.addEventListener('click', async function () {
        const text = input.value;
        try {
            if (navigator.clipboard && navigator.clipboard.writeText) {
                await navigator.clipboard.writeText(text);
            } else {
                input.select();
                document.execCommand('copy');
            }
            if (ok) {
                ok.classList.add('show');
                setTimeout(function () { ok.classList.remove('show'); }, 1600);
            }
        } catch (e) {
            input.select();
        }
    });
})();
</script>
<?php endif; ?>
</body>
</html>
