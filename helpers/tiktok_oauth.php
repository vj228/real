<?php

declare(strict_types=1);

$credentialsPath = dirname(__DIR__) . '/cron/tiktok_credentials.inc.php';
if (!is_readable($credentialsPath)) {
    throw new RuntimeException(
        'Missing cron/tiktok_credentials.inc.php — upload it next to your other cron scripts.'
    );
}
require_once $credentialsPath;

const TIKTOK_AUTH_URL = 'https://www.tiktok.com/v2/auth/authorize/';
const TIKTOK_TOKEN_URL = 'https://open.tiktokapis.com/v2/oauth/token/';
const TIKTOK_OAUTH_SCOPES = 'user.info.basic,video.upload,video.publish';
const TIKTOK_PKCE_TTL_SECONDS = 600;

function tiktok_oauth_pkce_dir(): string
{
    $candidates = [
        dirname(__DIR__) . '/cron/.tiktok_oauth_pkce',
        rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR) . '/yhome_tiktok_pkce',
    ];

    foreach ($candidates as $dir) {
        if (is_dir($dir)) {
            return $dir;
        }
        if (@mkdir($dir, 0755, true) || is_dir($dir)) {
            return $dir;
        }
    }

    throw new RuntimeException(
        'Cannot create PKCE storage directory. Make cron/ writable or check temp directory permissions.'
    );
}

function tiktok_oauth_pkce_path(string $state): string
{
    $safe = preg_replace('/[^a-f0-9]/', '', strtolower($state));

    return tiktok_oauth_pkce_dir() . '/' . $safe . '.json';
}

function tiktok_oauth_pkce_verifier(): string
{
    return rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
}

function tiktok_oauth_pkce_challenge(string $verifier): string
{
    return rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=');
}

function tiktok_oauth_build_authorize_url(string $state, string $challenge): string
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
 * @return array{authorize_url: string, state: string}
 */
function tiktok_oauth_begin(): array
{
    $verifier = tiktok_oauth_pkce_verifier();
    $challenge = tiktok_oauth_pkce_challenge($verifier);
    $state = bin2hex(random_bytes(16));

    $pkcePath = tiktok_oauth_pkce_path($state);
    $written = @file_put_contents($pkcePath, json_encode([
        'code_verifier' => $verifier,
        'state' => $state,
        'created_at' => time(),
    ]));
    if ($written === false) {
        throw new RuntimeException('Cannot write PKCE file: ' . $pkcePath);
    }

    return [
        'authorize_url' => tiktok_oauth_build_authorize_url($state, $challenge),
        'state' => $state,
    ];
}

function tiktok_oauth_load_verifier(string $state): ?string
{
    $path = tiktok_oauth_pkce_path($state);
    if (!is_readable($path)) {
        return null;
    }

    /** @var array<string, mixed>|null $stored */
    $stored = json_decode((string) file_get_contents($path), true);
    if (!is_array($stored)) {
        return null;
    }

    $createdAt = (int) ($stored['created_at'] ?? 0);
    if ($createdAt > 0 && (time() - $createdAt) > TIKTOK_PKCE_TTL_SECONDS) {
        @unlink($path);

        return null;
    }

    $verifier = trim((string) ($stored['code_verifier'] ?? ''));

    return $verifier !== '' ? $verifier : null;
}

function tiktok_oauth_clear_state(string $state): void
{
    @unlink(tiktok_oauth_pkce_path($state));
}

/**
 * @return array<string, mixed>|null
 */
function tiktok_oauth_exchange_code(string $code, string $codeVerifier): ?array
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

function tiktok_tokens_path(): string
{
    return dirname(__DIR__) . '/cron/.tiktok_tokens.json';
}

/**
 * @return array<string, mixed>|null
 */
function tiktok_tokens_load(): ?array
{
    $path = tiktok_tokens_path();
    if (!is_readable($path)) {
        return null;
    }

    /** @var array<string, mixed>|null $data */
    $data = json_decode((string) file_get_contents($path), true);

    return is_array($data) ? $data : null;
}

/**
 * @param array<string, mixed> $tokens
 */
function tiktok_tokens_save(array $tokens): void
{
    $tokens['updated_at'] = time();
    $dir = dirname(tiktok_tokens_path());
    if (!is_dir($dir)) {
        mkdir($dir, 0700, true);
    }

    $path = tiktok_tokens_path();
    $dir = dirname($path);
    if (!is_dir($dir)) {
        @mkdir($dir, 0755, true);
    }
    $written = @file_put_contents(
        $path,
        json_encode($tokens, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES),
        LOCK_EX
    );
    if ($written === false) {
        throw new RuntimeException('Cannot write tokens file: ' . $path . ' — make cron/ writable.');
    }
}

/**
 * @param array<string, mixed> $response TikTok token endpoint JSON
 * @param array<string, mixed>|null $previous
 * @return array<string, mixed>
 */
function tiktok_tokens_from_oauth_response(array $response, ?array $previous = null): array
{
    $expiresIn = (int) ($response['expires_in'] ?? 86400);
    $refreshExpiresIn = (int) ($response['refresh_expires_in'] ?? 31536000);
    $now = time();

    $refreshToken = trim((string) ($response['refresh_token'] ?? ''));
    if ($refreshToken === '' && $previous !== null) {
        $refreshToken = trim((string) ($previous['refresh_token'] ?? ''));
    }

    return [
        'access_token' => trim((string) ($response['access_token'] ?? '')),
        'refresh_token' => $refreshToken,
        'expires_at' => $now + max(60, $expiresIn),
        'refresh_expires_at' => $now + max(86400, $refreshExpiresIn),
        'open_id' => (string) ($response['open_id'] ?? ($previous['open_id'] ?? '')),
        'scope' => (string) ($response['scope'] ?? ($previous['scope'] ?? '')),
        'updated_at' => $now,
    ];
}

/**
 * @return array<string, mixed>|null
 */
function tiktok_oauth_refresh(string $refreshToken): ?array
{
    $post = http_build_query([
        'client_key' => TIKTOK_CLIENT_KEY,
        'client_secret' => TIKTOK_CLIENT_SECRET,
        'grant_type' => 'refresh_token',
        'refresh_token' => $refreshToken,
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

/**
 * Returns a valid access token, refreshing automatically when expired.
 *
 * @throws RuntimeException
 */
function tiktok_ensure_access_token(): string
{
    $tokens = tiktok_tokens_load();
    if ($tokens === null) {
        throw new RuntimeException(
            'TikTok not connected. Authorize once: https://yhome.pro/tiktok_oauth_start.php'
        );
    }

    $accessToken = trim((string) ($tokens['access_token'] ?? ''));
    $refreshToken = trim((string) ($tokens['refresh_token'] ?? ''));
    $expiresAt = (int) ($tokens['expires_at'] ?? 0);
    $refreshExpiresAt = (int) ($tokens['refresh_expires_at'] ?? 0);

    if ($refreshExpiresAt > 0 && time() >= $refreshExpiresAt) {
        throw new RuntimeException(
            'TikTok refresh token expired. Re-authorize: https://yhome.pro/tiktok_oauth_start.php'
        );
    }

    $refreshBuffer = 300;
    if ($accessToken !== '' && $expiresAt > (time() + $refreshBuffer)) {
        return $accessToken;
    }

    if ($refreshToken === '') {
        throw new RuntimeException(
            'No refresh token saved. Re-authorize: https://yhome.pro/tiktok_oauth_start.php'
        );
    }

    $response = tiktok_oauth_refresh($refreshToken);
    if ($response === null) {
        throw new RuntimeException('TikTok token refresh failed (no response).');
    }

    if (!empty($response['error'])) {
        throw new RuntimeException(
            'TikTok token refresh failed: ' . (string) $response['error']
            . ' — re-authorize at https://yhome.pro/tiktok_oauth_start.php'
        );
    }

    $newTokens = tiktok_tokens_from_oauth_response($response, $tokens);
    if ($newTokens['access_token'] === '') {
        throw new RuntimeException('TikTok refresh returned no access_token.');
    }

    tiktok_tokens_save($newTokens);

    return (string) $newTokens['access_token'];
}

/**
 * Save tokens after initial OAuth (web or CLI).
 *
 * @param array<string, mixed> $response
 */
function tiktok_oauth_store_tokens(array $response): void
{
    $tokens = tiktok_tokens_from_oauth_response($response, tiktok_tokens_load());
    if ($tokens['access_token'] === '') {
        throw new RuntimeException('OAuth response missing access_token.');
    }
    if ($tokens['refresh_token'] === '') {
        throw new RuntimeException('OAuth response missing refresh_token — enable offline access / refresh in your TikTok app.');
    }

    tiktok_tokens_save($tokens);
}
