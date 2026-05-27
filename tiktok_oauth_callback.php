<?php
declare(strict_types=1);
/**
 * TikTok Login Kit redirect target. Register this exact URL in the developer portal.
 */
$code = isset($_GET['code']) ? trim((string) $_GET['code']) : '';
$error = isset($_GET['error']) ? trim((string) $_GET['error']) : '';
$errorDescription = isset($_GET['error_description']) ? trim((string) $_GET['error_description']) : '';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>TikTok authorization — yHome</title>
    <link rel="stylesheet" href="/style.css">
</head>
<body>
<main class="legal-page">
    <section class="section legal-page__section">
        <div class="container legal-page__container">
            <header class="legal-page__header">
                <a class="site-logo" href="/">yHome</a>
            </header>
            <article class="legal-doc">
                <h1>TikTok authorization</h1>
                <?php if ($error !== ''): ?>
                    <p>Authorization failed: <strong><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></strong></p>
                    <?php if ($errorDescription !== ''): ?>
                        <p><?= htmlspecialchars($errorDescription, ENT_QUOTES, 'UTF-8') ?></p>
                    <?php endif; ?>
                <?php elseif ($code === ''): ?>
                    <p>No authorization code received. Try again from <code>php cron/tiktok_oauth_token.php</code>.</p>
                <?php else: ?>
                    <p>Copy this code, then on your server run:</p>
                    <pre style="overflow-x:auto;padding:12px;background:#f3f4f6;border-radius:8px;">php cron/tiktok_oauth_token.php <?= htmlspecialchars($code, ENT_QUOTES, 'UTF-8') ?></pre>
                    <p><strong>Code:</strong></p>
                    <p style="word-break:break-all;font-family:monospace;"><?= htmlspecialchars($code, ENT_QUOTES, 'UTF-8') ?></p>
                    <p class="legal-doc__meta">The code is short-lived. Run the command within a few minutes.</p>
                <?php endif; ?>
                <p><a href="/">← Back to yHome</a></p>
            </article>
        </div>
    </section>
</main>
</body>
</html>
