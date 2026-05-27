<?php

declare(strict_types=1);

/**
 * Start TikTok OAuth in the browser (redirects to TikTok login).
 */

function tiktok_oauth_start_fail(string $message): void
{
    http_response_code(500);
    header('Content-Type: text/html; charset=UTF-8');
    echo '<!DOCTYPE html><html><head><meta charset="UTF-8"><title>TikTok setup error</title></head><body style="font-family:sans-serif;max-width:640px;margin:40px auto;padding:0 20px;">';
    echo '<h1>TikTok setup error</h1><p>' . htmlspecialchars($message, ENT_QUOTES, 'UTF-8') . '</p>';
    echo '<p>Upload these files to the site root:</p><ul>';
    echo '<li><code>helpers/tiktok_oauth.php</code></li>';
    echo '<li><code>cron/tiktok_credentials.inc.php</code></li>';
    echo '<li><code>tiktok_auth.php</code></li>';
    echo '</ul><p>Ensure <code>cron/</code> is writable by PHP.</p>';
    echo '<p><a href="/">Back to yHome</a></p></body></html>';
    exit;
}

$helperPath = __DIR__ . '/helpers/tiktok_oauth.php';
$credentialsPath = __DIR__ . '/cron/tiktok_credentials.inc.php';

if (!is_readable($credentialsPath)) {
    tiktok_oauth_start_fail('Missing file: cron/tiktok_credentials.inc.php');
}

if (!is_readable($helperPath)) {
    tiktok_oauth_start_fail('Missing file: helpers/tiktok_oauth.php');
}

try {
    require_once $helperPath;

    if (isset($_GET['force']) && (string) $_GET['force'] === '1') {
        tiktok_oauth_clear_tokens();
    }

    $begin = tiktok_oauth_begin();
} catch (Throwable $e) {
    tiktok_oauth_start_fail($e->getMessage());
}

header('Location: ' . $begin['authorize_url'], true, 302);
exit;
