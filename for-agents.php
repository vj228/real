<?php

declare(strict_types=1);

/**
 * Simple agent-facing pitch page. Buyer house pages stay buyer-focused.
 */
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>For listing agents — yHome.ai</title>
    <meta name="description" content="yHome.ai helps listing agents get buyer questions and leads from their listings.">
    <link rel="stylesheet" href="/style.css">
    <style>
        .fa-page {
            min-height: 100vh;
            padding-bottom: 72px;
            background:
                radial-gradient(ellipse 100% 70% at 100% 0%, rgba(46, 157, 120, 0.08), transparent 50%),
                linear-gradient(180deg, #ffffff 0%, #f7fbf8 100%);
        }
        .fa-page .container { padding-top: 24px; max-width: 720px; }
        .fa-page h1 {
            margin: 12px 0 12px;
            font-size: clamp(1.8rem, 4vw, 2.4rem);
            letter-spacing: -0.035em;
            font-weight: 800;
            line-height: 1.15;
        }
        .fa-lead {
            margin: 0 0 28px;
            font-size: 1.1rem;
            color: var(--muted);
            line-height: 1.5;
        }
        .fa-steps { display: grid; gap: 14px; margin: 0 0 28px; }
        .fa-step {
            background: #fff;
            border: 1px solid var(--border);
            border-radius: 14px;
            padding: 16px 18px;
        }
        .fa-step strong {
            display: block;
            margin-bottom: 4px;
            font-size: 1.05rem;
        }
        .fa-step p { margin: 0; color: var(--muted); line-height: 1.45; }
        .fa-box {
            background: #ebf8f1;
            border: 1px solid #b7e4cd;
            border-radius: 14px;
            padding: 18px 20px;
            margin: 0 0 24px;
        }
        .fa-box h2 { margin: 0 0 8px; font-size: 1.15rem; }
        .fa-box p { margin: 0; color: var(--accent-dark); line-height: 1.45; }
        .fa-box code {
            display: inline-block;
            margin-top: 8px;
            padding: 8px 12px;
            background: #fff;
            border-radius: 8px;
            font-size: 0.95rem;
            font-weight: 700;
            color: var(--text);
        }
        .fa-cta { display: flex; flex-wrap: wrap; gap: 12px; align-items: center; }
        .fa-note { margin: 18px 0 0; color: var(--muted); font-size: 0.92rem; }
    </style>
</head>
<body>
<main class="fa-page">
    <div class="container">
        <header class="site-header">
            <a class="site-logo" href="/">yHome.ai</a>
            <div class="site-header-actions">
                <a href="/houses.php" class="button button-nav-cta">See listings</a>
            </div>
        </header>

        <h1>Get buyer questions as leads — without changing how you list.</h1>
        <p class="fa-lead">Buyers already visit yHome listing pages. When they text us about a home, we capture the question and phone number so you can follow up.</p>

        <div class="fa-steps">
            <div class="fa-step">
                <strong>1. Your listing is on yHome</strong>
                <p>Buyers see the home, renovation context, and a text number to ask about that address.</p>
            </div>
            <div class="fa-step">
                <strong>2. Buyers text real questions</strong>
                <p>Price, condition, open house, schools — we answer what we can and log every lead.</p>
            </div>
            <div class="fa-step">
                <strong>3. You get the leads</strong>
                <p>See which buyers asked about your listing, what they asked, and how to reach them.</p>
            </div>
        </div>

        <div class="fa-box">
            <h2>Optional: put the number on your Zillow remarks</h2>
            <p>After you see leads working, add this line to your listing so more buyers find you:</p>
            <code>Text +1 (626) 323-8836 to ask about this home (yHome.ai)</code>
        </div>

        <div class="fa-cta">
            <a class="button" href="/agent-dashboard.php">Claim your listing</a>
            <a class="button" href="/houses.php" style="background:#fff;color:var(--accent-dark);border:1px solid #b7e4cd;box-shadow:none;">Browse homes</a>
        </div>
        <p class="fa-note">$49/month when you’re ready — start by claiming your listing and reviewing leads with us.</p>
    </div>
</main>
</body>
</html>
