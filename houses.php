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
    <style>
        body { margin: 0; font-family: system-ui, sans-serif; background: #f5f7fb; color: #111; }
        .wrap { max-width: 1100px; margin: 0 auto; padding: 24px 16px 48px; }
        h1 { margin: 0 0 8px; font-size: 1.6rem; }
        .sub { color: #666; margin: 0 0 24px; }
        .grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(240px, 1fr)); gap: 16px; }
        .card { background: #fff; border-radius: 12px; overflow: hidden; box-shadow: 0 2px 10px rgba(0,0,0,.06); }
        .card img { width: 100%; height: 160px; object-fit: cover; display: block; background: #ddd; }
        .card .body { padding: 12px 14px 16px; }
        .price { font-weight: 700; font-size: 1.1rem; margin: 0 0 4px; }
        .addr { margin: 0 0 6px; font-size: .95rem; }
        .meta { margin: 0; color: #666; font-size: .85rem; }
        .badge {
            display: inline-block; margin-top: 8px; padding: 3px 8px;
            border-radius: 999px; background: #ebf8f1; color: #176948;
            font-size: .75rem; font-weight: 700;
        }
        a { color: inherit; text-decoration: none; }
        a:hover .addr { text-decoration: underline; }
    </style>
</head>
<body>
<div class="wrap">
    <h1>Houses</h1>
    <p class="sub"><?= count($rows) ?> listings (Arcadia + Alhambra)</p>
    <div class="grid">
        <?php foreach ($rows as $r): ?>
            <?php
            $href = '/house.php?id=' . (int) $r['id'];
            $img = !empty($r['img_src']) ? (string) $r['img_src'] : '';
            $beds = $r['beds'] !== null ? (string) (float) $r['beds'] : '—';
            $baths = $r['baths'] !== null ? (string) (float) $r['baths'] : '—';
            $sqft = $r['sqft'] !== null ? number_format((int) $r['sqft']) : '—';
            $hasEstimate = !empty($r['analysis_id']);
            ?>
            <a class="card" href="<?= h($href) ?>">
                <?php if ($img !== ''): ?>
                    <img src="<?= h($img) ?>" alt="">
                <?php else: ?>
                    <img alt="" src="data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='400' height='240'%3E%3Crect fill='%23ddd' width='100%25' height='100%25'/%3E%3C/svg%3E">
                <?php endif; ?>
                <div class="body">
                    <p class="price"><?= h(money($r['list_price'])) ?></p>
                    <p class="addr"><?= h((string) $r['address']) ?></p>
                    <p class="meta"><?= h($beds) ?> bed · <?= h($baths) ?> bath · <?= h($sqft) ?> sqft · <?= h((string) $r['search_query']) ?></p>
                    <?php if ($hasEstimate): ?>
                        <span class="badge">Estimate ready</span>
                    <?php endif; ?>
                </div>
            </a>
        <?php endforeach; ?>
    </div>
</div>
</body>
</html>
