<?php
/**
 * Get a TikTok user access token (not shown in the developer dashboard).
 *
 * 1. Register TIKTOK_REDIRECT_URI in TikTok Developer Portal → your app → Login Kit.
 * 2. Run:  php cron/tiktok_oauth_token.php
 * 3. Open the printed URL in a browser and log in with your TikTok account.
 * 4. After redirect, copy the "code" from the callback page (or URL bar).
 * 5. Run:  php cron/tiktok_oauth_token.php PASTE_CODE_HERE
 * 6. Copy access_token into cron/tiktok_credentials.inc.php → TIKTOK_ACCESS_TOKEN
 *
 * Tokens expire in ~24 hours; use refresh_token (printed on exchange) to renew.
 */

declare(strict_types=1);

require __DIR__ . '/tiktok_credentials.inc.php';

const TIKTOK_AUTH_URL = 'https://www.tiktok.com/v2/auth/authorize/';
const TIKTOK_TOKEN_URL = 'https://open.tiktokapis.com/v2/oauth/token/';
const TIKTOK_OAUTH_SCOPES = 'user.info.basic,video.upload';
const TIKTOK_PKCE_STORE = __DIR__ . '/.tiktok_oauth_pkce.json';

function oauth_out(string $msg): void
{
    echo $msg . PHP_EOL;
}

function oauth_pkce_verifier(): string
{
    return rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
}

function oauth_pkce_challenge(string $verifier): string
{
    return rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=');
}

function oauth_build_authorize_url(string $state, string $challenge): string
{
    $params = [
        'client_key' => TIKTOK_CLIENT_KEY,
        'response_type' => 'code',
        'scope' => TIKTOK_OAUTH_SCOPES,
        'redirect_uri' => TIKTOK_REDIRECT_URI,
        'state' => $state,
        'code_challenge' => $challenge,
        'code_challenge_method' => 'S256',
    ];

    return TIKTOK_AUTH_URL . '?' . http_build_query($params);
}

/**
 * @return array<string, mixed>|null
 */
function oauth_exchange_code(string $code, string $codeVerifier): ?array
{
    $post = http_build_query([
        'client_key' => TIKTOK_CLIENT_KEY,
        'client_secret' => TIKTOK_CLIENT_SECRET,
        'code' => $code,
        'grant_type' => 'authorization_code',
        'redirect_uri' => TIKTOK_REDIRECT_URI,
        'code_verifier' => $codeVerifier,
    ]);

    $ch = curl_init(TIKTOK_TOKEN_URL);
    if ($ch === false) {
        return null;
    }

    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => ['Content-Type: application/x-www-form-urlencoded'],
        CURLOPT_POSTFIELDS => $post,
        CURLOPT_TIMEOUT => 60,
    ]);

    $raw = curl_exec($ch);
    curl_close($ch);

    if ($raw === false) {
        return null;
    }

    $decoded = json_decode($raw, true);

    return is_array($decoded) ? $decoded : null;
}

$code = isset($argv[1]) ? trim((string) $argv[1]) : '';

if ($code === '') {
    $verifier = oauth_pkce_verifier();
    $challenge = oauth_pkce_challenge($verifier);
    $state = bin2hex(random_bytes(16));

    file_put_contents(TIKTOK_PKCE_STORE, json_encode([
        'code_verifier' => $verifier,
        'state' => $state,
        'created_at' => time(),
    ], JSON_THROW_ON_ERROR));

    oauth_out('TikTok does not show access_token in the app dashboard.');
    oauth_out('You get it once by authorizing your account (steps below).');
    oauth_out('');
    oauth_out('1. Ensure this redirect URI is registered in Login Kit:');
    oauth_out('   ' . TIKTOK_REDIRECT_URI);
    oauth_out('');
    oauth_out('2. Open this URL in your browser and approve access:');
    oauth_out('');
    oauth_out(oauth_build_authorize_url($state, $challenge));
    oauth_out('');
    oauth_out('3. After redirect, run:');
    oauth_out('   php cron/tiktok_oauth_token.php YOUR_CODE');
    exit(0);
}

if (!is_readable(TIKTOK_PKCE_STORE)) {
    oauth_out('Missing PKCE state. Run first: php cron/tiktok_oauth_token.php');
    exit(1);
}

/** @var array<string, mixed> $stored */
$stored = json_decode((string) file_get_contents(TIKTOK_PKCE_STORE), true, 512, JSON_THROW_ON_ERROR);
$verifier = (string) ($stored['code_verifier'] ?? '');

if ($verifier === '') {
    oauth_out('Invalid PKCE store. Run: php cron/tiktok_oauth_token.php');
    exit(1);
}

$response = oauth_exchange_code($code, $verifier);
@unlink(TIKTOK_PKCE_STORE);

if ($response === null) {
    oauth_out('Token exchange failed (no JSON).');
    exit(1);
}

if (!empty($response['error'])) {
    oauth_out('Error: ' . (string) $response['error']);
    oauth_out('Description: ' . (string) ($response['error_description'] ?? ''));
    exit(1);
}

$accessToken = (string) ($response['access_token'] ?? '');
$refreshToken = (string) ($response['refresh_token'] ?? '');
$expiresIn = (string) ($response['expires_in'] ?? '');
$scope = (string) ($response['scope'] ?? '');

if ($accessToken === '') {
    oauth_out('No access_token in response:');
    oauth_out(json_encode($response, JSON_PRETTY_PRINT));
    exit(1);
}

oauth_out('Success. Paste this into cron/tiktok_credentials.inc.php:');
oauth_out('');
oauth_out('TIKTOK_ACCESS_TOKEN = ' . var_export($accessToken, true) . ';');
oauth_out('');
oauth_out('access_token (expires in ' . $expiresIn . ' seconds):');
oauth_out($accessToken);
if ($refreshToken !== '') {
    oauth_out('');
    oauth_out('refresh_token (save securely, valid ~365 days):');
    oauth_out($refreshToken);
}
if ($scope !== '') {
    oauth_out('');
    oauth_out('scopes: ' . $scope);
}

exit(0);
