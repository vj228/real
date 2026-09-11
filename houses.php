<?php

declare(strict_types=1);

require_once __DIR__ . '/pdo_connect.php';

$pdo = db_pdo_connect();
if ($pdo === null) {
    http_response_code(500);
    echo 'Database connection failed.';
    exit;
}

$rows = $pdo->query(
    'SELECT l.id, l.zpid, l.address, l.list_price, l.beds, l.baths, l.sqft, l.search_query, l.img_src, l.detail_url, l.created_at,
            (SELECT a.id FROM ai_analyses a WHERE a.listing_id = l.id ORDER BY a.analyzed_at DESC, a.id DESC LIMIT 1) AS analysis_id
     FROM zillow_sale_listings l
     ORDER BY l.created_at DESC'
)->fetchAll(PDO::FETCH_ASSOC);

function h(?string $s): string
{
    return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
}

function money($n): string
{
    return $n === null || $n === '' ? '—' : '$' . number_format((float) $n, 0);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Houses — yHome</title>
    <meta name="description" content="Browse homes and get an AI renovation cost estimate from a walkthrough video.">
    <link rel="stylesheet" href="/style.css">
    <style>
        .houses-page {
            padding-bottom: 64px;
            background:
                radial-gradient(ellipse 110% 80% at 0% 0%, rgba(46, 157, 120, 0.08), transparent 52%),
                linear-gradient(180deg, #ffffff 0%, #f7fbf8 55%, #edf6f1 100%);
            min-height: 100vh;
        }
        .houses-page .container {
            padding-top: 24px;
        }
        .houses-intro {
            margin: 8px 0 28px;
        }
        .houses-intro h1 {
            margin: 0 0 10px;
            font-size: clamp(1.7rem, 3.5vw, 2.2rem);
            letter-spacing: -0.035em;
            font-weight: 800;
        }
        .houses-intro__count {
            margin: 0 0 16px;
            color: var(--muted);
            font-size: 0.98rem;
        }
        .houses-intro__callout {
            margin: 0;
            max-width: 36rem;
            padding: 16px 18px;
            background: #ebf8f1;
            border: 1px solid #b7e4cd;
            border-radius: 14px;
            color: var(--accent-dark);
            font-size: clamp(1.05rem, 2.2vw, 1.18rem);
            font-weight: 700;
            line-height: 1.4;
            letter-spacing: -0.015em;
        }
        .houses-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(240px, 1fr));
            gap: 16px;
        }
        .houses-card {
            background: #fff;
            border-radius: 12px;
            overflow: hidden;
            border: 1px solid var(--border);
            box-shadow: 0 2px 10px rgba(15, 23, 42, 0.05);
            color: inherit;
            text-decoration: none;
            display: block;
            transition: transform 0.15s ease, box-shadow 0.15s ease;
        }
        .houses-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 10px 24px rgba(15, 23, 42, 0.08);
        }
        .houses-card img {
            width: 100%;
            height: 160px;
            object-fit: cover;
            display: block;
            background: #d7e5dc;
        }
        .houses-card__body {
            padding: 12px 14px 16px;
        }
        .houses-card__price {
            font-weight: 800;
            font-size: 1.1rem;
            margin: 0 0 4px;
            letter-spacing: -0.02em;
        }
        .houses-card__addr {
            margin: 0 0 6px;
            font-size: 0.95rem;
        }
        .houses-card:hover .houses-card__addr {
            text-decoration: underline;
        }
        .houses-card__meta {
            margin: 0;
            color: var(--muted);
            font-size: 0.85rem;
        }
        .houses-card__badge {
            display: inline-block;
            margin-top: 8px;
            padding: 3px 8px;
            border-radius: 999px;
            background: #ebf8f1;
            color: #176948;
            font-size: 0.75rem;
            font-weight: 700;
        }
    </style>
</head>
<body>
<main class="houses-page">
    <div class="container">
        <header class="site-header">
            <a class="site-logo" href="/">yHome</a>
            <div class="site-header-actions">
                <a href="/" class="button button-nav-cta">Home</a>
            </div>
        </header>

        <div class="houses-intro">
            <h1>Houses</h1>
            <p class="houses-intro__count"><?= count($rows) ?> listings from Arcadia</p>
            <p class="houses-intro__callout">Pick a home below → upload a walkthrough → get your renovation estimate.</p>
        </div>

        <div class="houses-grid">
            <?php foreach ($rows as $r): ?>
                <?php
                $href = '/house.php?id=' . (int) $r['id'];
                $img = !empty($r['img_src']) ? (string) $r['img_src'] : '';
                $beds = $r['beds'] !== null ? (string) (float) $r['beds'] : '—';
                $baths = $r['baths'] !== null ? (string) (float) $r['baths'] : '—';
                $sqft = $r['sqft'] !== null ? number_format((int) $r['sqft']) : '—';
                $hasEstimate = !empty($r['analysis_id']);
                ?>
                <a class="houses-card" href="<?= h($href) ?>">
                    <?php if ($img !== ''): ?>
                        <img src="<?= h($img) ?>" alt="">
                    <?php else: ?>
                        <img alt="" src="data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='400' height='240'%3E%3Crect fill='%23d7e5dc' width='100%25' height='100%25'/%3E%3C/svg%3E">
                    <?php endif; ?>
                    <div class="houses-card__body">
                        <p class="houses-card__price"><?= h(money($r['list_price'])) ?></p>
                        <p class="houses-card__addr"><?= h((string) $r['address']) ?></p>
                        <p class="houses-card__meta"><?= h($beds) ?> bed · <?= h($baths) ?> bath · <?= h($sqft) ?> sqft · <?= h((string) $r['search_query']) ?></p>
                        <?php if ($hasEstimate): ?>
                            <span class="houses-card__badge">Estimate ready</span>
                        <?php endif; ?>
                    </div>
                </a>
            <?php endforeach; ?>
        </div>
    </div>
</main>
</body>
</html>
