<?php

declare(strict_types=1);

/**
 * Quick links to user + admin pages (local / staging helper).
 */

$userPages = [
    [
        'title' => 'Homepage',
        'path' => '/',
        'note' => 'Marketing landing — text us about any home',
    ],
    [
        'title' => 'Houses',
        'path' => '/houses.php',
        'note' => 'Browse Arcadia listings',
    ],
    [
        'title' => 'House detail',
        'path' => '/house.php?id=2548',
        'note' => 'Buyer page for one listing (SMS CTA)',
    ],
    [
        'title' => 'House leads',
        'path' => '/lead.php?id=2548',
        'note' => 'SMS inbound leads for one listing',
    ],
];

$adminPages = [
    [
        'title' => 'SMS test',
        'path' => '/sms/test.php',
        'note' => 'Fake SMS chat — localhost only',
    ],
    [
        'title' => 'Open houses',
        'path' => '/sms/open-house.php',
        'note' => 'Edit open-house text per listing',
    ],
];

function pages_h(?string $s): string
{
    return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Page index — yHome.ai</title>
    <meta name="robots" content="noindex,nofollow">
    <link rel="stylesheet" href="/style.css">
    <style>
        .pages-page {
            min-height: 100vh;
            padding-bottom: 64px;
            background:
                radial-gradient(ellipse 110% 80% at 0% 0%, rgba(46, 157, 120, 0.08), transparent 52%),
                linear-gradient(180deg, #ffffff 0%, #f7fbf8 55%, #edf6f1 100%);
        }
        .pages-page .container { padding-top: 24px; max-width: 820px; }
        .pages-page h1 {
            margin: 8px 0 8px;
            font-size: clamp(1.6rem, 3.2vw, 2rem);
            letter-spacing: -0.035em;
            font-weight: 800;
        }
        .pages-page .lead {
            margin: 0 0 28px;
            color: var(--muted);
            line-height: 1.5;
        }
        .pages-section {
            margin: 0 0 28px;
        }
        .pages-section h2 {
            margin: 0 0 12px;
            font-size: 1.05rem;
            letter-spacing: 0.04em;
            text-transform: uppercase;
            color: var(--accent-dark);
            font-weight: 800;
        }
        .pages-list {
            list-style: none;
            margin: 0;
            padding: 0;
            background: #fff;
            border: 1px solid var(--border);
            border-radius: 16px;
            overflow: hidden;
        }
        .pages-list li {
            border-bottom: 1px solid var(--border);
        }
        .pages-list li:last-child { border-bottom: none; }
        .pages-list a {
            display: block;
            padding: 14px 16px;
            text-decoration: none;
            color: inherit;
            transition: background 0.12s ease;
        }
        .pages-list a:hover { background: #f7fbf8; }
        .pages-list__title {
            margin: 0 0 2px;
            font-weight: 800;
            letter-spacing: -0.02em;
            color: var(--text);
        }
        .pages-list a:hover .pages-list__title { color: var(--accent-dark); }
        .pages-list__path {
            margin: 0 0 4px;
            font-size: 0.86rem;
            color: var(--accent-dark);
            font-family: ui-monospace, SFMono-Regular, Menlo, monospace;
        }
        .pages-list__note {
            margin: 0;
            font-size: 0.9rem;
            color: var(--muted);
            line-height: 1.4;
        }
        .pages-foot {
            margin-top: 8px;
            font-size: 0.86rem;
            color: var(--muted);
        }
    </style>
</head>
<body>
<main class="pages-page">
    <div class="container">
        <header class="site-header">
            <a class="site-logo" href="/">yHome.ai</a>
            <div class="site-header-actions">
                <a href="/" class="button button-nav-cta">Home</a>
            </div>
        </header>

        <h1>Page index</h1>
        <p class="lead">Click any link to open a user or admin page. Helper for local testing — not linked from the public homepage.</p>

        <section class="pages-section">
            <h2>User pages</h2>
            <ul class="pages-list">
                <?php foreach ($userPages as $p): ?>
                    <li>
                        <a href="<?= pages_h($p['path']) ?>">
                            <p class="pages-list__title"><?= pages_h($p['title']) ?></p>
                            <p class="pages-list__path"><?= pages_h($p['path']) ?></p>
                            <p class="pages-list__note"><?= pages_h($p['note']) ?></p>
                        </a>
                    </li>
                <?php endforeach; ?>
            </ul>
        </section>

        <section class="pages-section">
            <h2>Admin pages</h2>
            <ul class="pages-list">
                <?php foreach ($adminPages as $p): ?>
                    <li>
                        <a href="<?= pages_h($p['path']) ?>">
                            <p class="pages-list__title"><?= pages_h($p['title']) ?></p>
                            <p class="pages-list__path"><?= pages_h($p['path']) ?></p>
                            <p class="pages-list__note"><?= pages_h($p['note']) ?></p>
                        </a>
                    </li>
                <?php endforeach; ?>
            </ul>
        </section>

        <p class="pages-foot">Bookmark <code>/pages.php</code> while building. APIs under <code>/api/</code> are not listed here.</p>
    </div>
</main>
</body>
</html>
