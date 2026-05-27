<?php

declare(strict_types=1);

/**
 * TikTok Login Kit redirect URI (https://yhome.pro/tiktok_auth.php).
 */

$helperPath = __DIR__ . '/helpers/tiktok_oauth.php';
$credentialsPath = __DIR__ . '/cron/tiktok_credentials.inc.php';
if (!is_readable($credentialsPath) || !is_readable($helperPath)) {
    http_response_code(500);
    header('Content-Type: text/plain; charset=UTF-8');
    echo 'Missing cron/tiktok_credentials.inc.php or helpers/tiktok_oauth.php on server.';
    exit;
}
try {
    require_once $helperPath;
} catch (Throwable $e) {
    http_response_code(500);
    header('Content-Type: text/plain; charset=UTF-8');
    echo 'TikTok setup error: ' . $e->getMessage();
    exit;
}

$code = isset($_GET['code']) ? trim((string) $_GET['code']) : '';
$state = isset($_GET['state']) ? trim((string) $_GET['state']) : '';
$error = isset($_GET['error']) ? trim((string) $_GET['error']) : '';
$errorDescription = isset($_GET['error_description']) ? trim((string) $_GET['error_description']) : '';

$accessToken = '';
$refreshToken = '';
$expiresIn = '';
$scope = '';
$exchangeError = '';
$savedAutomatically = false;

if ($error === '' && $code !== '' && $state !== '') {
    $verifier = tiktok_oauth_load_verifier($state);
    if ($verifier === null) {
        $exchangeError = 'Session expired or PKCE state not found. Start again from the link below.';
    } else {
        $response = tiktok_oauth_exchange_code($code, $verifier);
        tiktok_oauth_clear_state($state);

        if ($response === null) {
            $exchangeError = 'Token exchange failed (no response from TikTok).';
        } elseif (!empty($response['error'])) {
            $exchangeError = (string) $response['error'] . ': ' . (string) ($response['error_description'] ?? '');
        } else {
            $accessToken = (string) ($response['access_token'] ?? '');
            $refreshToken = (string) ($response['refresh_token'] ?? '');
            $expiresIn = (string) ($response['expires_in'] ?? '');
            $scope = (string) ($response['scope'] ?? '');
            if ($accessToken === '') {
                $exchangeError = 'No access_token in TikTok response.';
            } else {
                try {
                    tiktok_oauth_store_tokens($response);
                    $savedAutomatically = true;
                } catch (RuntimeException $e) {
                    $exchangeError = $e->getMessage();
                    $accessToken = '';
                }
            }
        }
    }
}

$startUrl = '/tiktok_oauth_start.php';
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
                    <p><a class="button button-primary" href="<?= htmlspecialchars($startUrl, ENT_QUOTES, 'UTF-8') ?>">Try again</a></p>

                <?php elseif ($accessToken !== '' && $savedAutomatically): ?>
                    <?php
                    $scopeList = array_filter(array_map('trim', explode(',', $scope)));
                    $hasPublish = in_array('video.publish', $scopeList, true);
                    ?>
                    <p><strong>Connected.</strong> Tokens were saved to <code>cron/.tiktok_tokens.json</code> on the server.</p>
                    <?php if ($scope !== ''): ?>
                        <p class="legal-doc__meta">Scopes granted: <?= htmlspecialchars($scope, ENT_QUOTES, 'UTF-8') ?></p>
                    <?php endif; ?>
                    <?php if (!$hasPublish): ?>
                        <p><strong style="color:#b45309;">Missing <code>video.publish</code></strong> — auto-publish will not work yet.</p>
                        <ol>
                            <li>In <a href="https://developers.tiktok.com/" target="_blank" rel="noopener">TikTok Developer Portal</a> → yHome → add <strong>Content Posting API</strong>.</li>
                            <li>Revoke yHome in TikTok → Settings → Security → Manage app access.</li>
                            <li><a href="/tiktok_oauth_start.php?force=1">Authorize again (force)</a></li>
                        </ol>
                    <?php else: ?>
                        <p>Auto-publish is enabled. Run:</p>
                        <pre style="overflow-x:auto;padding:12px;background:#f3f4f6;border-radius:8px;">php cron/upload_to_tiktok.php</pre>
                    <?php endif; ?>

                <?php elseif ($accessToken !== ''): ?>
                    <p><strong>Success</strong> but tokens could not be saved on the server. Ensure <code>cron/</code> is writable, then try again.</p>

                <?php elseif ($exchangeError !== ''): ?>
                    <p><?= htmlspecialchars($exchangeError, ENT_QUOTES, 'UTF-8') ?></p>
                    <p><a class="button button-primary" href="<?= htmlspecialchars($startUrl, ENT_QUOTES, 'UTF-8') ?>">Start authorization</a></p>

                <?php else: ?>
                    <p>Start here to connect TikTok (do not open this page directly after login — use the button below):</p>
                    <p><a class="button button-primary" href="<?= htmlspecialchars($startUrl, ENT_QUOTES, 'UTF-8') ?>">Connect TikTok account</a></p>
                <?php endif; ?>

                <p style="margin-top:24px;"><a href="/">← Back to yHome</a></p>
            </article>
        </div>
    </section>
</main>
</body>
</html>
