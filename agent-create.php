<?php

declare(strict_types=1);

/**
 * MVP admin: create an agent partner + referral code.
 * Not meant as a public signup — protect or remove later.
 */

require_once __DIR__ . '/pdo_connect.php';
require_once __DIR__ . '/helpers/agent_referral.php';

function ac_h(?string $s): string
{
    return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
}

$pdo = db_pdo_connect();
$error = null;
$successCode = null;
$nameVal = '';
$emailVal = '';
$codeVal = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $nameVal = trim((string) ($_POST['name'] ?? ''));
    $emailVal = strtolower(trim((string) ($_POST['email'] ?? '')));
    $codeVal = strtoupper(trim((string) ($_POST['referral_code'] ?? '')));

    if (!$pdo instanceof PDO) {
        $error = 'Database unavailable.';
    } elseif ($codeVal === '') {
        $error = 'Referral code is required.';
    } elseif (agent_ref_normalize($codeVal) === null) {
        $error = 'Use 3–32 characters: letters, numbers, underscore, or hyphen (e.g. AGT102).';
    } elseif ($emailVal !== '' && !filter_var($emailVal, FILTER_VALIDATE_EMAIL)) {
        $error = 'Enter a valid email, or leave it blank.';
    } elseif (strlen($nameVal) > 120) {
        $error = 'Name is too long.';
    } else {
        $code = agent_ref_normalize($codeVal);
        try {
            $exists = $pdo->prepare('SELECT id FROM agents WHERE referral_code = ? LIMIT 1');
            $exists->execute([$code]);
            if ($exists->fetch()) {
                $error = 'That referral code already exists.';
            } else {
                $ins = $pdo->prepare(
                    'INSERT INTO agents (referral_code, name, email, is_active)
                     VALUES (?, ?, ?, 1)'
                );
                $ins->execute([
                    $code,
                    $nameVal,
                    $emailVal !== '' ? $emailVal : null,
                ]);
                $successCode = $code;
                $nameVal = '';
                $emailVal = '';
                $codeVal = '';
            }
        } catch (Throwable $e) {
            $error = 'Could not create agent: ' . $e->getMessage();
        }
    }
}

$suggested = 'AGT102';
if ($pdo instanceof PDO) {
    try {
        $row = $pdo->query(
            "SELECT referral_code FROM agents
             WHERE referral_code REGEXP '^AGT[0-9]+$'
             ORDER BY CAST(SUBSTRING(referral_code, 4) AS UNSIGNED) DESC
             LIMIT 1"
        )->fetch(PDO::FETCH_ASSOC);
        if ($row && preg_match('/^AGT(\d+)$/', (string) $row['referral_code'], $m)) {
            $suggested = 'AGT' . str_pad((string) ((int) $m[1] + 1), 3, '0', STR_PAD_LEFT);
        }
    } catch (Throwable $e) {
        // keep default
    }
}
if ($codeVal === '' && $successCode === null) {
    $codeVal = $suggested;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Create agent — yHome.ai</title>
    <link rel="stylesheet" href="/style.css">
    <style>
        .ac-page {
            min-height: 100vh;
            padding-bottom: 64px;
            background:
                radial-gradient(ellipse 110% 80% at 0% 0%, rgba(46, 157, 120, 0.08), transparent 52%),
                linear-gradient(180deg, #ffffff 0%, #f7fbf8 55%, #edf6f1 100%);
        }
        .ac-page .container { padding-top: 24px; max-width: 640px; }
        .ac-page h1 {
            margin: 8px 0 8px;
            font-size: clamp(1.6rem, 3.2vw, 2rem);
            letter-spacing: -0.035em;
            font-weight: 800;
        }
        .ac-page .lead {
            margin: 0 0 20px;
            color: var(--muted);
            line-height: 1.5;
        }
        .ac-card {
            background: #fff;
            border: 1px solid var(--border);
            border-radius: 16px;
            padding: 22px;
        }
        .ac-card label {
            display: block;
            font-weight: 700;
            margin: 0 0 6px;
        }
        .ac-card .hint {
            margin: 0 0 8px;
            font-size: 0.86rem;
            color: var(--muted);
        }
        .ac-card input {
            width: 100%;
            padding: 12px 14px;
            border: 1px solid var(--border);
            border-radius: 12px;
            font: inherit;
            margin-bottom: 14px;
        }
        .ac-card .button {
            border-radius: 999px;
            padding: 12px 20px;
            font-weight: 800;
        }
        .ac-error {
            margin: 0 0 14px;
            color: #b42318;
            font-weight: 600;
        }
        .ac-ok {
            margin: 0 0 18px;
            padding: 14px 16px;
            background: #ebf8f1;
            border: 1px solid #b7e4cd;
            border-radius: 14px;
            color: var(--accent-dark);
            line-height: 1.45;
        }
        .ac-ok a {
            color: var(--accent-dark);
            font-weight: 800;
        }
        .ac-note {
            margin: 16px 0 0;
            font-size: 0.86rem;
            color: var(--muted);
        }
    </style>
</head>
<body>
<main class="ac-page">
    <div class="container">
        <header class="site-header">
            <a class="site-logo" href="/">yHome.ai</a>
            <div class="site-header-actions">
                <a href="/agent-dashboard.php" class="button button-nav-cta">Agent dashboard</a>
            </div>
        </header>

        <h1>Create agent</h1>
        <p class="lead">Add a partner and give them a referral code for property links.</p>

        <?php if ($successCode !== null): ?>
            <div class="ac-ok">
                Created <strong><?= ac_h($successCode) ?></strong>.
                <a href="/agent-dashboard.php?code=<?= ac_h($successCode) ?>">Open their dashboard</a>
            </div>
        <?php endif; ?>

        <form class="ac-card" method="post" action="/agent-create.php" autocomplete="off">
            <?php if ($error): ?>
                <p class="ac-error"><?= ac_h($error) ?></p>
            <?php endif; ?>

            <label for="referral_code">Referral code</label>
            <p class="hint">Suggested next code: <?= ac_h($suggested) ?></p>
            <input id="referral_code" name="referral_code" type="text" required
                   maxlength="32" value="<?= ac_h($codeVal) ?>" placeholder="AGT103">

            <label for="name">Name (optional)</label>
            <input id="name" name="name" type="text" maxlength="120"
                   value="<?= ac_h($nameVal) ?>" placeholder="Jane Agent">

            <label for="email">Email (optional)</label>
            <input id="email" name="email" type="email" maxlength="255"
                   value="<?= ac_h($emailVal) ?>" placeholder="jane@example.com">

            <button type="submit" class="button button-primary">Create agent</button>
            <p class="ac-note">MVP admin page — not a public signup. Protect or remove before wide release.</p>
        </form>
    </div>
</main>
</body>
</html>
