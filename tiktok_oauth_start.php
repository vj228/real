<?php

declare(strict_types=1);

/**
 * Start TikTok OAuth in the browser (redirects to TikTok login).
 * Register https://yhome.pro/tiktok_auth.php in Login Kit first.
 */

require_once __DIR__ . '/helpers/tiktok_oauth.php';

$begin = tiktok_oauth_begin();

header('Location: ' . $begin['authorize_url'], true, 302);
exit;
