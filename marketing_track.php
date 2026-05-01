<?php
declare(strict_types=1);

/**
 * Logs each page load for marketing attribution. Fails silently (never breaks the page).
 * Requires table marketing_page_visits — see sql/marketing_page_visits.sql
 */

function marketing_normalize_ip(): ?string
{
    $raw = '';
    if (!empty($_SERVER['HTTP_CF_CONNECTING_IP'])) {
        $raw = (string) $_SERVER['HTTP_CF_CONNECTING_IP'];
    } elseif (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
        $raw = (string) $_SERVER['HTTP_X_FORWARDED_FOR'];
    } elseif (!empty($_SERVER['REMOTE_ADDR'])) {
        $raw = (string) $_SERVER['REMOTE_ADDR'];
    }
    $raw = trim(explode(',', $raw)[0]);
    return $raw !== '' ? $raw : null;
}

function marketing_referrer_host(?string $referrer): ?string
{
    if ($referrer === null || $referrer === '') {
        return null;
    }
    $parts = parse_url($referrer);
    if (!is_array($parts) || empty($parts['host'])) {
        return null;
    }
    return substr((string) $parts['host'], 0, 255);
}

function marketing_trunc_str(?string $s, int $max): ?string
{
    if ($s === null) {
        return null;
    }
    $s = trim($s);
    if ($s === '') {
        return null;
    }
    return strlen($s) > $max ? substr($s, 0, $max) : $s;
}

function marketing_ip_is_public(?string $ip): bool
{
    if ($ip === null || $ip === '') {
        return false;
    }

    return filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) !== false;
}

/**
 * Rough location from visitor IP using ip-api.com (HTTP).
 * Respect their rate limits; skipped for bots, private IPs, and CLI.
 * Cloudflare: HTTP_CF_IPCOUNTRY used as fallback (country code only).
 */
function marketing_geo_resolve(?string $ip, string $deviceCategory): array
{
    $out = [
        'city' => null,
        'region' => null,
        'country' => null,
        'country_code' => null,
    ];

    $cloudflare_country = '';
    if (!empty($_SERVER['HTTP_CF_IPCOUNTRY'])) {
        $cc = strtoupper(substr(trim((string) $_SERVER['HTTP_CF_IPCOUNTRY']), 0, 2));
        if ($cc !== '' && $cc !== 'XX') {
            $cloudflare_country = $cc;
        }
    }

    if ($deviceCategory === 'bot' || !marketing_ip_is_public($ip)) {
        $out['country_code'] = $cloudflare_country !== '' ? $cloudflare_country : null;

        return $out;
    }

    $url = 'http://ip-api.com/json/' . rawurlencode((string) $ip)
        . '?fields=status,message,country,countryCode,regionName,city';

    $body = '';
    $canFopen = filter_var(
        ini_get('allow_url_fopen'),
        FILTER_VALIDATE_BOOLEAN,
        FILTER_NULL_ON_FAILURE
    );

    if ($canFopen) {
        $ctx = stream_context_create([
            'http' => [
                'timeout' => 2,
                'ignore_errors' => true,
            ],
        ]);
        $raw = @file_get_contents($url, false, $ctx);
        $body = is_string($raw) ? $raw : '';
    }

    if ($body === '' && function_exists('curl_init')) {
        $ch = curl_init($url);
        if ($ch !== false) {
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_FOLLOWLOCATION => false,
                CURLOPT_CONNECTTIMEOUT => 2,
                CURLOPT_TIMEOUT => 2,
                CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
            ]);
            $body = curl_exec($ch);
            curl_close($ch);
            if (!is_string($body)) {
                $body = '';
            }
        }
    }

    if ($body !== '') {
        $data = json_decode($body, true);
        if (is_array($data) && (($data['status'] ?? '') === 'success')) {
            $out['country'] = marketing_trunc_str(isset($data['country']) ? (string) $data['country'] : null, 120);
            $out['country_code'] = marketing_trunc_str(
                isset($data['countryCode']) ? strtoupper((string) $data['countryCode']) : null,
                2
            );
            $out['region'] = marketing_trunc_str(isset($data['regionName']) ? (string) $data['regionName'] : null, 120);
            $out['city'] = marketing_trunc_str(isset($data['city']) ? (string) $data['city'] : null, 120);
        }
    }

    if ($out['country_code'] === null && $cloudflare_country !== '') {
        $out['country_code'] = $cloudflare_country;
    }

    return $out;
}

function marketing_device_category(?string $ua): string
{
    if ($ua === null || $ua === '') {
        return 'unknown';
    }
    $l = strtolower($ua);
    if (preg_match('/bot|crawl|spider|slurp|bingpreview|facebookexternalhit|embedly|mediapartners/i', $l)) {
        return 'bot';
    }
    if (preg_match('/tablet|ipad|playbook|silk/i', $l)) {
        return 'tablet';
    }
    if (preg_match('/mobi|iphone|android|iemobile|opera mini/i', $l)) {
        return 'mobile';
    }
    return 'desktop';
}

function marketing_try_pdo(): ?PDO
{
    $configPath = __DIR__ . '/db.credentials.php';
    if (!is_readable($configPath)) {
        return null;
    }
    /** @var array<string, mixed> $cfg */
    $cfg = require $configPath;

    $host = isset($cfg['host']) ? (string) $cfg['host'] : 'localhost';
    $port = isset($cfg['port']) ? (int) $cfg['port'] : 3306;
    $name = isset($cfg['name']) ? (string) $cfg['name'] : '';
    $user = isset($cfg['user']) ? (string) $cfg['user'] : '';
    $pass = isset($cfg['pass']) ? (string) $cfg['pass'] : '';

    if ($name === '' || $user === '') {
        return null;
    }

    // Prefer configured host first, then typical Hostinger fallbacks when host is omitted.
    $hosts = $host !== '' ? [$host] : ['localhost', '127.0.0.1', 'srv827.hstgr.io'];
    foreach ($hosts as $h) {
        $dsn = "mysql:host={$h};port={$port};dbname={$name};charset=utf8mb4";
        try {
            return new PDO($dsn, $user, $pass, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
            ]);
        } catch (PDOException $e) {
            // try next host
            continue;
        }
    }

    return null;
}

(function (): void {
    try {
        if (php_sapi_name() === 'cli') {
            return;
        }

        $pdo = marketing_try_pdo();
        if ($pdo === null) {
            return;
        }

        $method = isset($_SERVER['REQUEST_METHOD']) ? strtoupper(substr((string) $_SERVER['REQUEST_METHOD'], 0, 10)) : 'GET';
        $httpHost = isset($_SERVER['HTTP_HOST']) ? substr((string) $_SERVER['HTTP_HOST'], 0, 255) : '';
        $uri = isset($_SERVER['REQUEST_URI']) ? (string) $_SERVER['REQUEST_URI'] : '/';
        $parts = explode('?', $uri, 2);
        $path = substr($parts[0], 0, 2048);
        $qs = isset($parts[1]) ? substr($parts[1], 0, 2048) : '';

        // Skip automatic browser requests that are not marketing-relevant page views.
        if (preg_match('#(^|/)favicon\.ico$#i', $path)) {
            return;
        }

        $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
        $fullUrl = substr($scheme . '://' . $httpHost . $uri, 0, 4096);

        $referrer = isset($_SERVER['HTTP_REFERER']) ? (string) $_SERVER['HTTP_REFERER'] : null;
        if ($referrer !== null && strlen($referrer) > 65535) {
            $referrer = substr($referrer, 0, 65535);
        }
        $refHost = marketing_referrer_host($referrer);

        $ip = marketing_normalize_ip();
        $ua = isset($_SERVER['HTTP_USER_AGENT']) ? (string) $_SERVER['HTTP_USER_AGENT'] : null;
        $device = marketing_device_category($ua);
        $geo = marketing_geo_resolve($ip, $device);

        $sessionId = (session_status() === PHP_SESSION_ACTIVE && !empty(session_id()))
            ? substr(session_id(), 0, 128)
            : null;

        parse_str($qs, $params);
        $utm = static function (string $k) use ($params): ?string {
            if (!isset($params[$k])) {
                return null;
            }
            $v = (string) $params[$k];
            return $v !== '' ? substr($v, 0, 255) : null;
        };

        $sql = 'INSERT INTO marketing_page_visits (
            http_method, http_host, page_path, query_string, full_url,
            referrer, referrer_host, ip_address,
            geo_city, geo_region, geo_country, geo_country_code,
            user_agent, device_category, php_session_id,
            utm_source, utm_medium, utm_campaign, utm_content, utm_term
        ) VALUES (
            :method, :host, :path, :qs, :full,
            :ref, :refhost, :ip,
            :geo_city, :geo_region, :geo_country, :geo_cc,
            :ua, :device, :sid,
            :us, :um, :uc, :ucn, :ut
        )';

        $stmt = $pdo->prepare($sql);
        $stmt->execute([
            ':method' => $method ?: 'GET',
            ':host' => $httpHost,
            ':path' => $path ?: '/',
            ':qs' => $qs,
            ':full' => $fullUrl,
            ':ref' => $referrer,
            ':refhost' => $refHost,
            ':ip' => $ip,
            ':geo_city' => $geo['city'],
            ':geo_region' => $geo['region'],
            ':geo_country' => $geo['country'],
            ':geo_cc' => $geo['country_code'],
            ':ua' => $ua,
            ':device' => $device,
            ':sid' => $sessionId,
            ':us' => $utm('utm_source'),
            ':um' => $utm('utm_medium'),
            ':uc' => $utm('utm_campaign'),
            ':ucn' => $utm('utm_content'),
            ':ut' => $utm('utm_term'),
        ]);
    } catch (Throwable $e) {
        error_log('marketing_track: ' . $e->getMessage());
    }
})();
