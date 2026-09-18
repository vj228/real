<?php

declare(strict_types=1);

/**
 * Extract / enrich listing-agent contact from RapidAPI payloads + Gemini fallback.
 */

/** @param mixed $v */
function listing_agent_str($v, int $max = 255): ?string
{
    if ($v === null) {
        return null;
    }
    if (is_array($v)) {
        foreach (['fullName', 'name', 'text', 'value', 'email', 'phone', 'phoneNumber'] as $k) {
            if (isset($v[$k]) && is_string($v[$k]) && trim($v[$k]) !== '') {
                return listing_agent_str($v[$k], $max);
            }
        }

        return null;
    }
    $s = trim((string) $v);
    if ($s === '' || strcasecmp($s, 'null') === 0) {
        return null;
    }
    $s = preg_replace('/\s+/', ' ', $s) ?? $s;

    return strlen($s) > $max ? substr($s, 0, $max) : $s;
}

function listing_agent_phone(?string $s): ?string
{
    $s = listing_agent_str($s, 64);
    if ($s === null) {
        return null;
    }
    // Keep digits and leading + ; require enough digits to be a phone.
    $digits = preg_replace('/\D+/', '', $s) ?? '';
    if (strlen($digits) < 10) {
        return null;
    }

    return $s;
}

function listing_agent_email(?string $s): ?string
{
    $s = listing_agent_str($s, 255);
    if ($s === null) {
        return null;
    }
    $s = strtolower($s);
    if (!filter_var($s, FILTER_VALIDATE_EMAIL)) {
        return null;
    }

    return $s;
}

/**
 * Walk a RapidAPI property/search payload for agent contact fields.
 *
 * @param array<string,mixed> $data
 * @return array{
 *   name:?string,phone:?string,email:?string,broker:?string,
 *   source:string,raw:array<string,mixed>
 * }
 */
function listing_agent_from_rapid_payload(array $data): array
{
    $out = [
        'name' => null,
        'phone' => null,
        'email' => null,
        'broker' => null,
        'source' => 'rapidapi',
        'raw' => [],
    ];

    $candidates = [];
    // Always consider the root — search listings often only expose brokerName.
    $candidates[] = $data;
    $queue = [$data];
    $seen = 0;
    while ($queue !== [] && $seen < 400) {
        $node = array_shift($queue);
        $seen++;
        if (!is_array($node)) {
            continue;
        }
        $keys = array_change_key_case($node, CASE_LOWER);
        $looksAgent = isset($keys['agentname'])
            || isset($keys['agent_name'])
            || isset($keys['agentemail'])
            || isset($keys['agentphone'])
            || isset($keys['agentphonenumber'])
            || isset($keys['brokername'])
            || isset($keys['attributioninfo'])
            || (isset($keys['listedby']) && is_array($node['listedBy'] ?? $node['listed_by'] ?? null));
        if ($looksAgent || isset($node['attributionInfo']) || isset($node['attribution'])) {
            $candidates[] = $node;
        }
        foreach ($node as $v) {
            if (is_array($v)) {
                $queue[] = $v;
            }
        }
    }

    $pick = static function (array $row, array $names): ?string {
        foreach ($names as $n) {
            if (array_key_exists($n, $row)) {
                $s = listing_agent_str($row[$n]);
                if ($s !== null) {
                    return $s;
                }
            }
        }

        return null;
    };

    foreach ($candidates as $row) {
        if (!is_array($row)) {
            continue;
        }
        $attr = $row['attributionInfo'] ?? $row['attribution'] ?? null;
        if (is_array($attr)) {
            $row = array_merge($attr, $row);
        }
        $listed = $row['listedBy'] ?? $row['listed_by'] ?? null;
        if (is_array($listed)) {
            // listedBy can be a list of { name, phone, email, type }
            if (isset($listed[0]) && is_array($listed[0])) {
                foreach ($listed as $lb) {
                    if (!is_array($lb)) {
                        continue;
                    }
                    $type = strtolower((string) ($lb['type'] ?? $lb['role'] ?? 'agent'));
                    if (str_contains($type, 'broker')) {
                        $out['broker'] = $out['broker'] ?? listing_agent_str($lb['name'] ?? $lb['fullName'] ?? null);
                        $out['phone'] = $out['phone'] ?? listing_agent_phone(listing_agent_str($lb['phone'] ?? $lb['phoneNumber'] ?? null, 64));
                        $out['email'] = $out['email'] ?? listing_agent_email(listing_agent_str($lb['email'] ?? null));
                    } else {
                        $out['name'] = $out['name'] ?? listing_agent_str($lb['name'] ?? $lb['fullName'] ?? null);
                        $out['phone'] = $out['phone'] ?? listing_agent_phone(listing_agent_str($lb['phone'] ?? $lb['phoneNumber'] ?? null, 64));
                        $out['email'] = $out['email'] ?? listing_agent_email(listing_agent_str($lb['email'] ?? null));
                    }
                }
            } else {
                $out['name'] = $out['name'] ?? listing_agent_str($listed['name'] ?? $listed['fullName'] ?? null);
                $out['phone'] = $out['phone'] ?? listing_agent_phone(listing_agent_str($listed['phone'] ?? null, 64));
                $out['email'] = $out['email'] ?? listing_agent_email(listing_agent_str($listed['email'] ?? null));
            }
        }

        $out['name'] = $out['name'] ?? $pick($row, [
            'agentName', 'agent_name', 'listAgentFullName', 'listingAgentName', 'realtorName', 'displayName', 'name',
        ]);
        $out['phone'] = $out['phone'] ?? listing_agent_phone($pick($row, [
            'agentPhoneNumber', 'agentPhone', 'agent_phone', 'agent_phone_number', 'phoneNumber', 'phone', 'businessPhone', 'cellPhone',
        ]));
        $out['email'] = $out['email'] ?? listing_agent_email($pick($row, [
            'agentEmail', 'agent_email', 'email', 'businessEmail',
        ]));
        $out['broker'] = $out['broker'] ?? $pick($row, [
            'brokerName', 'broker_name', 'brokerageName', 'officeName', 'companyName',
        ]);
        $out['raw'] = $row;
        if ($out['name'] || $out['phone'] || $out['email']) {
            break;
        }
    }

    return $out;
}

function listing_agent_missing(array $agent): bool
{
    return ($agent['name'] ?? null) === null
        || ($agent['phone'] ?? null) === null
        || ($agent['email'] ?? null) === null;
}

function listing_agent_is_complete(array $agent): bool
{
    return !listing_agent_missing($agent);
}

/**
 * Load saved agent fields for a listing (avoid re-calling APIs).
 *
 * @return array{name:?string,phone:?string,email:?string,broker:?string,source:?string,fetched_at:?string}
 */
function listing_agent_load_existing(PDO $pdo, int $listingId): array
{
    $out = [
        'name' => null,
        'phone' => null,
        'email' => null,
        'broker' => null,
        'source' => null,
        'fetched_at' => null,
    ];
    if ($listingId <= 0) {
        return $out;
    }
    $stmt = $pdo->prepare(
        'SELECT listing_agent_name, listing_agent_phone, listing_agent_email, listing_broker_name,
                listing_agent_source, listing_agent_fetched_at
         FROM zillow_sale_listings WHERE id = ? LIMIT 1'
    );
    $stmt->execute([$listingId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!is_array($row)) {
        return $out;
    }

    return [
        'name' => listing_agent_str($row['listing_agent_name'] ?? null),
        'phone' => listing_agent_phone(listing_agent_str($row['listing_agent_phone'] ?? null, 64)),
        'email' => listing_agent_email(listing_agent_str($row['listing_agent_email'] ?? null)),
        'broker' => listing_agent_str($row['listing_broker_name'] ?? null),
        'source' => listing_agent_str($row['listing_agent_source'] ?? null, 32),
        'fetched_at' => !empty($row['listing_agent_fetched_at']) ? (string) $row['listing_agent_fetched_at'] : null,
    ];
}

/**
 * Call Gemini only when fields are missing AND we have not tried recently (saves Search quota).
 */
function listing_agent_should_call_gemini(array $agent, ?string $fetchedAt, int $retryAfterDays = 7): bool
{
    if (listing_agent_is_complete($agent)) {
        return false;
    }
    if ($fetchedAt === null || trim($fetchedAt) === '') {
        return true;
    }
    $ts = strtotime($fetchedAt);
    if ($ts === false) {
        return true;
    }

    return (time() - $ts) >= ($retryAfterDays * 86400);
}

/**
 * Parse Zillow-style "Listed by: Broker • Agent • Contact: phone" text.
 *
 * @return array{name:?string,phone:?string,broker:?string}|null
 */
function listing_agent_parse_listed_by(string $text): ?array
{
    $text = trim(preg_replace('/\s+/', ' ', $text) ?? $text);
    if ($text === '') {
        return null;
    }
    // "Listed by: Treelane Realty Group Inc. • Yuling Lee • Contact: 6269934884"
    if (preg_match(
        '/Listed\s*by\s*:\s*(.+?)\s*[•·|\-]\s*(.+?)\s*[•·|\-]\s*Contact\s*:\s*([+\d().\-\s]+)/iu',
        $text,
        $m
    )) {
        return [
            'broker' => listing_agent_str($m[1]),
            'name' => listing_agent_str($m[2]),
            'phone' => listing_agent_phone(listing_agent_str($m[3], 64)),
        ];
    }
    if (preg_match('/Listed\s*by\s*:\s*(.+)$/iu', $text, $m)) {
        $parts = preg_split('/\s*[•·|]\s*/u', $m[1]) ?: [];
        $parts = array_values(array_filter(array_map('trim', $parts), static fn ($p) => $p !== ''));
        $broker = $parts[0] ?? null;
        $name = $parts[1] ?? null;
        $phone = null;
        foreach ($parts as $p) {
            if (preg_match('/Contact\s*:\s*(.+)$/iu', $p, $cm)) {
                $phone = listing_agent_phone(listing_agent_str($cm[1], 64));
            } elseif (listing_agent_phone($p) !== null && $phone === null) {
                $phone = listing_agent_phone($p);
            }
        }

        return [
            'broker' => listing_agent_str($broker),
            'name' => listing_agent_str($name),
            'phone' => $phone,
        ];
    }

    return null;
}

/**
 * Ask Gemini (with Google Search grounding when available) to find public listing-agent contacts.
 * Never invents — returns only fields found in public sources.
 *
 * @param array{name:?string,phone:?string,email:?string,broker:?string} $partial
 * @return array{name:?string,phone:?string,email:?string,broker:?string,source:string,raw:?array}
 */
function listing_agent_gemini_enrich(
    string $address,
    ?string $zpid,
    ?string $detailUrl,
    array $partial
): array {
    $credsPath = dirname(__DIR__) . '/ai.credentials.php';
    if (!is_readable($credsPath)) {
        return array_merge($partial, ['source' => 'gemini_skipped', 'raw' => ['error' => 'no ai.credentials.php']]);
    }
    /** @var array<string,mixed> $creds */
    $creds = require $credsPath;
    $apiKey = trim((string) ($creds['gemini_api_key'] ?? ''));
    $model = trim((string) ($creds['gemini_model'] ?? 'gemini-3.6-flash'));
    if ($apiKey === '' || $apiKey === 'YOUR_GEMINI_API_KEY') {
        return array_merge($partial, ['source' => 'gemini_skipped', 'raw' => ['error' => 'no api key']]);
    }
    $models = array_values(array_unique(array_filter([$model, 'gemini-flash-latest'])));

    $need = [];
    if (empty($partial['name'])) {
        $need[] = 'name';
    }
    if (empty($partial['phone'])) {
        $need[] = 'phone';
    }
    if (empty($partial['email'])) {
        $need[] = 'email';
    }
    if ($need === []) {
        return array_merge($partial, ['source' => 'rapidapi', 'raw' => null]);
    }

    $knownName = listing_agent_str($partial['name'] ?? null);
    $knownPhone = listing_agent_phone(listing_agent_str($partial['phone'] ?? null, 64));
    $knownBroker = listing_agent_str($partial['broker'] ?? null);
    $knownEmail = listing_agent_email(listing_agent_str($partial['email'] ?? null));

    // When name+phone are known (Zillow "Listed by" line), focus Gemini on email — ChatGPT-style lookup.
    if ($knownName !== null && $knownPhone !== null && $knownEmail === null) {
        $prompt = "Find the PUBLIC email address for this California listing agent. Do not invent.\n"
            . "Agent name: {$knownName}\n"
            . "Phone: {$knownPhone}\n"
            . ($knownBroker ? "Brokerage: {$knownBroker}\n" : '')
            . "Property address: {$address}\n"
            . ($detailUrl ? "Zillow listing: {$detailUrl}\n" : '')
            . "Search brokerage websites, Realtor.com agent profiles, Redfin agent pages, "
            . "MLS public agent directories, California DRE, and LinkedIn/public business listings.\n"
            . "Return JSON only: "
            . '{"name":"' . $knownName . '","phone":"' . $knownPhone . '","email":string|null,"broker":'
            . json_encode($knownBroker, JSON_UNESCAPED_SLASHES)
            . ',"sources":[string],"confidence":"high"|"medium"|"low"}'
            . "\nIf you cannot find a real published email, set email to null.";
    } else {
        $prompt = "For this Zillow for-sale listing, find the listing agent from PUBLIC sources.\n"
            . "Address: {$address}\n"
            . ($zpid ? "zpid: {$zpid}\n" : '')
            . ($detailUrl ? "URL: {$detailUrl}\n" : '')
            . "On Zillow the attribution often looks exactly like:\n"
            . "Listed by: {brokerage} • {agent name} • Contact: {phone}\n"
            . "Extract that line when you find it. Then find a public email for that same agent "
            . "(brokerage site, Realtor.com, Redfin, DRE). Never invent.\n"
            . 'Already known: ' . json_encode([
                'name' => $knownName,
                'phone' => $knownPhone,
                'email' => $knownEmail,
                'broker' => $knownBroker,
            ], JSON_UNESCAPED_SLASHES)
            . "\nFill missing: " . implode(', ', $need) . ".\n"
            . 'JSON only: {"name":string|null,"phone":string|null,"email":string|null,"broker":string|null,'
            . '"listed_by_raw":string|null,"sources":[string]}';
    }

    $body = [
        'contents' => [
            ['role' => 'user', 'parts' => [['text' => $prompt]]],
        ],
        'generationConfig' => [
            'temperature' => 0.1,
            'maxOutputTokens' => 2048,
            // Do NOT set responseMimeType with google_search — Gemini often returns empty candidates.
        ],
        'tools' => [
            ['google_search' => new stdClass()],
        ],
    ];

    $raw = false;
    $http = 0;
    $err = '';
    $decoded = null;
    foreach ($models as $tryModel) {
        $url = 'https://generativelanguage.googleapis.com/v1beta/models/'
            . rawurlencode($tryModel) . ':generateContent?key=' . rawurlencode($apiKey);
        $payload = $body;
        $ch = curl_init($url);
        if ($ch === false) {
            continue;
        }
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
            CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_SLASHES),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 120,
        ]);
        $raw = curl_exec($ch);
        $http = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err = curl_error($ch);

        // Retry without google_search tool if model rejects it.
        if ($raw !== false && $http >= 400) {
            $tmp = json_decode((string) $raw, true);
            $msg = is_array($tmp) ? (string) ($tmp['error']['message'] ?? '') : '';
            if ($http === 400 || stripos($msg, 'tool') !== false || stripos($msg, 'google_search') !== false) {
                unset($payload['tools']);
                curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload, JSON_UNESCAPED_SLASHES));
                $raw = curl_exec($ch);
                $http = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
                $err = curl_error($ch);
            }
        }

        if ($raw === false) {
            continue;
        }
        $decoded = json_decode($raw, true);
        if (is_array($decoded) && $http >= 200 && $http < 300) {
            $model = $tryModel;
            break;
        }
        // Soft-retry next model on overload / not found.
        if (in_array($http, [404, 429, 503], true)) {
            $decoded = null;
            continue;
        }
        // Other 4xx: stop (bad request / auth).
        break;
    }

    if ($raw === false) {
        return array_merge($partial, ['source' => 'gemini_error', 'raw' => ['error' => $err ?: 'request failed']]);
    }
    if (!is_array($decoded) || $http < 200 || $http >= 300) {
        return array_merge($partial, [
            'source' => 'gemini_error',
            'raw' => ['http' => $http, 'body' => is_array($decoded) ? $decoded : substr((string) $raw, 0, 800)],
        ]);
    }

    $text = '';
    $parts = $decoded['candidates'][0]['content']['parts'] ?? [];
    if (is_array($parts)) {
        foreach ($parts as $p) {
            if (is_array($p) && isset($p['text'])) {
                $text .= (string) $p['text'];
            }
        }
    }
    $text = trim($text);
    // Strip markdown fences if the model wraps JSON.
    if (preg_match('/```(?:json)?\s*(\{.*?\})\s*```/is', $text, $m)) {
        $text = $m[1];
    } elseif (preg_match('/\{.*\}/s', $text, $m)) {
        $text = $m[0];
    }
    $json = json_decode($text, true);
    // Salvage truncated JSON (common when the model cuts mid-object).
    if (!is_array($json) && str_starts_with($text, '{')) {
        $salvage = $text;
        // Drop a trailing incomplete key/value fragment after the last comma.
        $salvage = preg_replace('/,\s*"[^"]*"\s*:\s*("[^"]*)?$/', '', $salvage) ?? $salvage;
        $salvage = rtrim($salvage, ", \n\r\t");
        $open = substr_count($salvage, '{') - substr_count($salvage, '}');
        $openArr = substr_count($salvage, '[') - substr_count($salvage, ']');
        if ($openArr > 0) {
            $salvage .= str_repeat(']', $openArr);
        }
        if ($open > 0) {
            $salvage .= str_repeat('}', $open);
        }
        $json = json_decode($salvage, true);
    }
    if (!is_array($json)) {
        return array_merge($partial, ['source' => 'gemini_error', 'raw' => ['parse' => $text]]);
    }

    // Prefer parsed Listed by line if Gemini returned it raw.
    if (!empty($json['listed_by_raw']) && is_string($json['listed_by_raw'])) {
        $parsed = listing_agent_parse_listed_by($json['listed_by_raw']);
        if (is_array($parsed)) {
            $json['name'] = $json['name'] ?? $parsed['name'];
            $json['phone'] = $json['phone'] ?? $parsed['phone'];
            $json['broker'] = $json['broker'] ?? $parsed['broker'];
        }
    }

    $merged = [
        'name' => $knownName ?? listing_agent_str($json['name'] ?? null),
        'phone' => $knownPhone ?? listing_agent_phone(listing_agent_str($json['phone'] ?? null, 64)),
        'email' => $knownEmail ?? listing_agent_email(listing_agent_str($json['email'] ?? null)),
        'broker' => $knownBroker ?? listing_agent_str($json['broker'] ?? null),
        'source' => empty($partial['name']) && empty($partial['phone']) && empty($partial['email'])
            ? 'gemini'
            : 'mixed',
        'raw' => $json,
    ];
    // If rapid had some fields, keep source mixed when gemini filled gaps.
    if (($partial['name'] || $partial['phone'] || $partial['email']) && listing_agent_missing($partial)) {
        $merged['source'] = 'mixed';
    }

    return $merged;
}

/**
 * Persist agent fields onto an existing listing row.
 *
 * @param array{name:?string,phone:?string,email:?string,broker:?string,source?:string,raw?:mixed} $agent
 */
function listing_agent_save(PDO $pdo, int $listingId, array $agent): void
{
    if ($listingId <= 0) {
        return;
    }
    $raw = $agent['raw'] ?? null;
    $rawJson = null;
    if (is_array($raw) || is_object($raw)) {
        $rawJson = json_encode($raw, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }
    $stmt = $pdo->prepare(
        'UPDATE zillow_sale_listings SET
            listing_agent_name = COALESCE(?, listing_agent_name),
            listing_agent_phone = COALESCE(?, listing_agent_phone),
            listing_agent_email = COALESCE(?, listing_agent_email),
            listing_broker_name = COALESCE(?, listing_broker_name),
            listing_agent_source = ?,
            listing_agent_raw_json = ?,
            listing_agent_fetched_at = NOW()
         WHERE id = ?'
    );
    $stmt->execute([
        $agent['name'] ?? null,
        $agent['phone'] ?? null,
        $agent['email'] ?? null,
        $agent['broker'] ?? null,
        (string) ($agent['source'] ?? 'rapidapi'),
        $rawJson,
        $listingId,
    ]);
}
