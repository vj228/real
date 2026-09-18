<?php
/**
 * RapidAPI Real Estate Zillow.com → zillow_sale_listings table.
 */

set_time_limit(0);
ignore_user_abort(true);

function script_flush(string $msg): void {
    echo $msg . "\n";
    if (ob_get_level() > 0) {
        ob_flush();
    }
    flush();
}

if (PHP_SAPI !== 'cli') {
    @ini_set('zlib.output_compression', '0');
    @ini_set('output_buffering', '0');
    ob_implicit_flush(true);
    header('Content-Type: text/plain; charset=UTF-8');
    header('X-Accel-Buffering: no');
}

function rapidapi_address_from_record(array $row): ?string {
    if (!empty($row['address']) && is_string($row['address'])) {
        $s = trim($row['address']);
        return $s !== '' ? $s : null;
    }
    if (!empty($row['fullAddress']) && is_string($row['fullAddress'])) {
        $s = trim($row['fullAddress']);
        return $s !== '' ? $s : null;
    }
    $street = $row['streetAddress'] ?? $row['addressStreet'] ?? '';
    $city = $row['city'] ?? $row['addressCity'] ?? '';
    $state = $row['state'] ?? $row['addressState'] ?? '';
    $zip = $row['zipcode'] ?? $row['zipCode'] ?? $row['postalCode'] ?? $row['addressZipcode'] ?? '';
    if (is_string($street) && trim($street) !== '' && is_string($city) && trim($city) !== '') {
        $parts = [trim($street), trim($city)];
        if (is_string($state) && trim($state) !== '') {
            $parts[] = trim($state);
        }
        if ((is_string($zip) || is_numeric($zip)) && (string) $zip !== '') {
            $parts[] = trim((string) $zip);
        }
        return implode(', ', $parts);
    }
    return null;
}

function rapidapi_price_from_record(array $row): float|int|string|null {
    foreach (['unformattedPrice', 'price', 'listPrice', 'zestimate', 'amount'] as $k) {
        if (!array_key_exists($k, $row)) {
            continue;
        }
        $v = $row[$k];
        if (is_int($v) || is_float($v)) {
            return $v;
        }
        if (is_string($v) && is_numeric(trim(str_replace([',', '$'], '', $v)))) {
            return (float) str_replace([',', '$'], '', $v);
        }
        if (is_array($v) && isset($v['value']) && is_numeric($v['value'])) {
            return (float) $v['value'];
        }
    }
    if (isset($row['hdpData']['homeInfo']) && is_array($row['hdpData']['homeInfo'])) {
        $hi = $row['hdpData']['homeInfo'];
        foreach (['price', 'priceForHDP'] as $k) {
            if (!isset($hi[$k])) {
                continue;
            }
            $v = $hi[$k];
            if (is_numeric($v)) {
                return (float) $v;
            }
            if (is_string($v) && is_numeric(trim(str_replace([',', '$'], '', $v)))) {
                return (float) str_replace([',', '$'], '', $v);
            }
        }
    }
    return null;
}

function rapidapi_tax_from_record(array $row): float|int|string|null {
    foreach (['propertyTax', 'taxAnnualAmount', 'annualTaxAmount', 'property_tax', 'taxes'] as $k) {
        if (!array_key_exists($k, $row)) {
            continue;
        }
        $v = $row[$k];
        if (is_int($v) || is_float($v)) {
            return $v;
        }
        if (is_string($v) && is_numeric(trim(str_replace([',', '$'], '', $v)))) {
            return (float) str_replace([',', '$'], '', $v);
        }
    }
    if (isset($row['resoFacts']) && is_array($row['resoFacts'])) {
        foreach (['taxAnnualAmount', 'annualTaxAmount'] as $k) {
            if (!isset($row['resoFacts'][$k])) {
                continue;
            }
            $v = $row['resoFacts'][$k];
            if (is_numeric($v)) {
                return (float) $v;
            }
        }
    }
    if (isset($row['hdpData']['homeInfo']) && is_array($row['hdpData']['homeInfo'])) {
        $hi = $row['hdpData']['homeInfo'];
        foreach (['taxAnnualAmount', 'annualTaxAmount'] as $k) {
            if (!isset($hi[$k])) {
                continue;
            }
            $v = $hi[$k];
            if (is_numeric($v)) {
                return (float) $v;
            }
        }
    }
    return null;
}

function rapidapi_num(mixed $v, bool $asInt = false): mixed
{
    if ($v === null || $v === '') {
        return null;
    }
    if (!is_numeric($v) && is_string($v)) {
        $v = str_replace([',', '$'], '', trim($v));
    }
    if (!is_numeric($v)) {
        return null;
    }

    return $asInt ? (int) round((float) $v) : round((float) $v, 2);
}

/** @return list<string> */
function rapidapi_images_from_record(array $row): array
{
    $urls = [];
    $add = static function (string $url) use (&$urls): void {
        $url = trim($url);
        if ($url !== '' && !in_array($url, $urls, true)) {
            $urls[] = $url;
        }
    };

    if (!empty($row['imgSrc']) && is_string($row['imgSrc'])) {
        $add($row['imgSrc']);
    }

    $carousel = $row['carouselPhotosComposable'] ?? null;
    if (is_array($carousel)) {
        $base = $carousel['baseUrl'] ?? '';
        if (is_string($base) && $base !== '' && !empty($carousel['photoData']) && is_array($carousel['photoData'])) {
            foreach ($carousel['photoData'] as $photo) {
                if (is_array($photo) && !empty($photo['photoKey'])) {
                    $add(str_replace('{photoKey}', (string) $photo['photoKey'], $base));
                }
            }
        }
    }

    return $urls;
}

function rapidapi_listing_from_record(array $row): ?array
{
    $addr = rapidapi_address_from_record($row);
    if ($addr === null) {
        return null;
    }

    $hi = is_array($row['hdpData']['homeInfo'] ?? null) ? $row['hdpData']['homeInfo'] : [];
    $price = rapidapi_price_from_record($row);
    $images = rapidapi_images_from_record($row);
    $beds = rapidapi_num($row['beds'] ?? $hi['bedrooms'] ?? null);
    $baths = rapidapi_num($row['baths'] ?? $hi['bathrooms'] ?? null);
    $sqft = rapidapi_num($row['area'] ?? $hi['livingArea'] ?? null);
    $zestimate = rapidapi_num($row['zestimate'] ?? $hi['zestimate'] ?? null);
    $hoa = rapidapi_num($hi['monthlyHoaFee'] ?? $row['monthlyHoaFee'] ?? $hi['hoaFee'] ?? null);
    $taxAssessed = rapidapi_num($hi['taxAssessedValue'] ?? null);
    $daysOnZillow = rapidapi_num($row['daysOnZillow'] ?? $hi['daysOnZillow'] ?? null, true);

    $priceNum = is_numeric($price) ? (float) $price : null;
    $pricePerSqft = ($priceNum !== null && $sqft !== null && $sqft > 0)
        ? round($priceNum / $sqft, 2)
        : null;
    $priceVsZestimatePct = null;
    if ($priceNum !== null && $zestimate !== null && $zestimate > 0) {
        $priceVsZestimatePct = round((($priceNum - $zestimate) / $zestimate) * 100, 2);
    }

    return [
        'Address' => $addr,
        'Price' => $price,
        'Tax' => rapidapi_tax_from_record($row),
        'imgSrc' => $images[0] ?? null,
        'images' => $images,
        'beds' => $beds,
        'baths' => $baths,
        'sqft' => $sqft,
        'pricePerSqft' => $pricePerSqft,
        'zestimate' => $zestimate,
        'priceVsZestimatePct' => $priceVsZestimatePct,
        'taxAssessedValue' => $taxAssessed,
        'hoaFee' => $hoa,
        'daysOnZillow' => $daysOnZillow,
        'detailUrl' => !empty($row['detailUrl']) && is_string($row['detailUrl']) ? $row['detailUrl'] : null,
        'zpid' => isset($row['zpid']) ? (string) $row['zpid'] : null,
    ];
}

function rapidapi_upsert_listings_db(PDO $pdo, array $listings, string $searchQuery): array
{
    $savedIds = [];
    $sql = <<<'SQL'
INSERT INTO zillow_sale_listings (
    last_fetched_at, search_query, zpid, address, detail_url,
    list_price, zestimate, price_vs_zestimate_pct, price_per_sqft,
    property_tax_annual, tax_assessed_value, hoa_fee,
    beds, baths, sqft, days_on_zillow, img_src, images_json, is_active
) VALUES (
    :last_fetched_at, :search_query, :zpid, :address, :detail_url,
    :list_price, :zestimate, :price_vs_zestimate_pct, :price_per_sqft,
    :property_tax_annual, :tax_assessed_value, :hoa_fee,
    :beds, :baths, :sqft, :days_on_zillow, :img_src, :images_json, 1
)
ON DUPLICATE KEY UPDATE
    last_fetched_at = VALUES(last_fetched_at),
    search_query = VALUES(search_query),
    address = VALUES(address),
    detail_url = VALUES(detail_url),
    list_price = VALUES(list_price),
    zestimate = VALUES(zestimate),
    price_vs_zestimate_pct = VALUES(price_vs_zestimate_pct),
    price_per_sqft = VALUES(price_per_sqft),
    property_tax_annual = VALUES(property_tax_annual),
    tax_assessed_value = VALUES(tax_assessed_value),
    hoa_fee = VALUES(hoa_fee),
    beds = VALUES(beds),
    baths = VALUES(baths),
    sqft = VALUES(sqft),
    days_on_zillow = VALUES(days_on_zillow),
    img_src = VALUES(img_src),
    images_json = VALUES(images_json),
    is_active = 1
SQL;
    $stmt = $pdo->prepare($sql);
    $now = date('Y-m-d H:i:s');
    $inserted = $updated = $skipped = 0;

    foreach ($listings as $listing) {
        if (!is_array($listing)) {
            $skipped++;
            continue;
        }
        $zpid = trim((string) ($listing['zpid'] ?? ''));
        $address = trim((string) ($listing['Address'] ?? ''));
        if ($zpid === '' || $address === '') {
            $skipped++;
            continue;
        }
        $images = is_array($listing['images'] ?? null) ? $listing['images'] : [];
        $detailUrl = trim((string) ($listing['detailUrl'] ?? ''));
        $imgSrc = trim((string) ($listing['imgSrc'] ?? ''));

        $stmt->execute([
            'last_fetched_at' => $now,
            'search_query' => $searchQuery,
            'zpid' => $zpid,
            'address' => strlen($address) > 512 ? substr($address, 0, 512) : $address,
            'detail_url' => $detailUrl !== '' ? substr($detailUrl, 0, 2048) : null,
            'list_price' => rapidapi_num($listing['Price'] ?? $listing['price'] ?? null),
            'zestimate' => rapidapi_num($listing['zestimate'] ?? null),
            'price_vs_zestimate_pct' => rapidapi_num($listing['priceVsZestimatePct'] ?? null),
            'price_per_sqft' => rapidapi_num($listing['pricePerSqft'] ?? null),
            'property_tax_annual' => rapidapi_num($listing['Tax'] ?? null),
            'tax_assessed_value' => rapidapi_num($listing['taxAssessedValue'] ?? null),
            'hoa_fee' => rapidapi_num($listing['hoaFee'] ?? null),
            'beds' => rapidapi_num($listing['beds'] ?? null),
            'baths' => rapidapi_num($listing['baths'] ?? null),
            'sqft' => rapidapi_num($listing['sqft'] ?? null, true),
            'days_on_zillow' => rapidapi_num($listing['daysOnZillow'] ?? null, true),
            'img_src' => $imgSrc !== '' ? substr($imgSrc, 0, 2048) : null,
            'images_json' => $images === [] ? null : json_encode(array_values($images), JSON_UNESCAPED_UNICODE),
        ]);
        $n = $stmt->rowCount();
        if ($n === 1) {
            $inserted++;
        } elseif ($n === 2) {
            $updated++;
        }

        $idStmt = $pdo->prepare('SELECT id FROM zillow_sale_listings WHERE zpid = ? LIMIT 1');
        $idStmt->execute([$zpid]);
        $listingId = (int) $idStmt->fetchColumn();
        if ($listingId > 0) {
            $savedIds[] = [
                'id' => $listingId,
                'zpid' => $zpid,
                'address' => $address,
                'detail_url' => $detailUrl !== '' ? $detailUrl : null,
                'raw' => is_array($listing['_raw'] ?? null) ? $listing['_raw'] : [],
            ];
        }
    }

    script_flush(sprintf(
        'DB: %d new, %d updated, %d skipped (sent_to_j2v / j2v_file_* unchanged on update).',
        $inserted,
        $updated,
        $skipped
    ));

    return $savedIds;
}

function rapidapi_address_dedupe_key(string $addr): string {
    $a = strtolower(trim($addr));
    $a = preg_replace('/\s+/', ' ', $a);
    $a = preg_replace('/,\s*([a-z]{2})\s*,\s*(\d{5}(?:-\d{4})?)\s*$/', ', $1 $2', $a);
    return $a;
}

function rapidapi_listings_from_response(array $data, int $max): array
{
    $rows = $data['data']['listings'] ?? [];
    if (!is_array($rows)) {
        return [];
    }
    $out = [];
    $seen = [];
    foreach ($rows as $row) {
        if (!is_array($row)) {
            continue;
        }
        $listing = rapidapi_listing_from_record($row);
        if ($listing === null) {
            continue;
        }
        $listing['_raw'] = $row;
        $k = rapidapi_address_dedupe_key($listing['Address']);
        if (isset($seen[$k])) {
            continue;
        }
        $seen[$k] = true;
        $out[] = $listing;
        if (count($out) >= $max) {
            break;
        }
    }

    return $out;
}

$rapidApiKey = '24c72349a4msh978d1a453ef7522p193f33jsn404aedf6c8e9';
$rapidApiHost = 'real-estate-zillow-com.p.rapidapi.com';
$rapidApiPath = '/v1/search/sale';
// Arcadia newest sales — 10 houses per run.
$locations = ['arcadia ca'];
$pageWindow = 12;
$page = 1;
$maxResults = 10;

if ($rapidApiKey === '') {
    script_flush('Set $rapidApiKey in cron/rapid.php.');
    exit(1);
}

require_once dirname(__DIR__) . '/pdo_connect.php';
$pdo = db_pdo_connect();
if ($pdo === null) {
    script_flush('DB not configured. Add db.credentials.php and run sql/zillow_sale_listings.sql');
    exit(1);
}

/** Hostinger closes idle MySQL during long RapidAPI waits — reconnect when needed. */
function rapidapi_pdo_alive(PDO $pdo): PDO
{
    try {
        $pdo->query('SELECT 1');
        return $pdo;
    } catch (Throwable $e) {
        $fresh = db_pdo_connect();
        if ($fresh === null) {
            throw new RuntimeException('DB reconnect failed: ' . (db_pdo_last_error() ?? 'unknown'));
        }
        script_flush('DB: reconnected after idle timeout.');
        return $fresh;
    }
}

function rapidapi_fetch_sale(string $host, string $path, string $apiKey, array $queryParams): array
{
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => 'https://' . $host . $path . '?' . http_build_query($queryParams),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_ENCODING => '',
        CURLOPT_MAXREDIRS => 10,
        CURLOPT_TIMEOUT => 300,
        CURLOPT_CONNECTTIMEOUT => 90,
        CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
        CURLOPT_CUSTOMREQUEST => 'GET',
        CURLOPT_IPRESOLVE => CURL_IPRESOLVE_V4,
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/json',
            'x-rapidapi-host: ' . $host,
            'x-rapidapi-key: ' . $apiKey,
        ],
    ]);

    $raw = curl_exec($ch);
    $http = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    if ($raw === false) {
        throw new RuntimeException('cURL error: ' . curl_error($ch));
    }

    $data = json_decode($raw, true);
    if (!is_array($data)) {
        throw new RuntimeException("Invalid JSON. First 800 bytes:\n" . substr((string) $raw, 0, 800));
    }
    if ($http < 200 || $http >= 300) {
        throw new RuntimeException("RapidAPI error HTTP {$http}:\n" . json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    }

    return $data;
}

/**
 * Try common property-detail paths on this RapidAPI host until one works.
 *
 * @return array<string,mixed>|null
 */
function rapidapi_fetch_property_detail(string $host, string $apiKey, string $zpid, ?string $detailUrl = null): ?array
{
    $zpid = trim($zpid);
    if ($zpid === '' || !ctype_digit($zpid)) {
        return null;
    }
    $attempts = [
        ['/v1/property', array_filter(['zpid' => $zpid, 'url' => $detailUrl])],
        ['/property', array_filter(['zpid' => $zpid, 'url' => $detailUrl])],
        ['/propertyDetails', ['zpid' => $zpid]],
        ['/v1/property/' . rawurlencode($zpid), []],
        ['/pro/property', ['zpid' => $zpid]],
    ];
    foreach ($attempts as [$path, $query]) {
        try {
            $data = rapidapi_fetch_sale($host, $path, $apiKey, is_array($query) ? $query : []);
            // This API often returns HTTP 200 with status=400 and data=null for missing endpoints/params.
            if (!is_array($data) || $data === []) {
                continue;
            }
            $status = $data['status'] ?? null;
            if ($status !== null && (int) $status >= 400) {
                continue;
            }
            if (array_key_exists('data', $data) && $data['data'] === null) {
                continue;
            }
            if (!empty($data['message']) && stripos((string) $data['message'], 'does not exist') !== false) {
                continue;
            }

            return $data;
        } catch (Throwable $e) {
            // try next path
            continue;
        }
    }

    return null;
}

require_once dirname(__DIR__) . '/helpers/listing_agent.php';

// This RapidAPI product's property-detail endpoints are unused/broken and burn quota — keep off.
$rapidApiTryPropertyDetail = false;
// Don't re-spend Gemini Search quota on the same listing more than once per N days.
$geminiAgentRetryDays = 7;

/**
 * @param list<array{id:int,zpid:string,address:string,detail_url:?string,raw:array}> $saved
 */
function rapidapi_enrich_listing_agents(
    PDO $pdo,
    string $host,
    string $apiKey,
    array $saved,
    bool $tryPropertyDetail = false,
    int $geminiRetryDays = 7
): void {
    foreach ($saved as $row) {
        $listingId = (int) $row['id'];
        $zpid = (string) $row['zpid'];
        $address = (string) $row['address'];
        $detailUrl = $row['detail_url'] ?? null;

        script_flush('Agent lookup for zpid=' . $zpid . '…');

        // 1) Start from DB — never re-call APIs if we already have a full agent.
        $existing = listing_agent_load_existing($pdo, $listingId);
        $fromSearch = listing_agent_from_rapid_payload(is_array($row['raw']) ? $row['raw'] : []);
        $agent = [
            'name' => $existing['name'] ?? $fromSearch['name'],
            'phone' => $existing['phone'] ?? $fromSearch['phone'],
            'email' => $existing['email'] ?? $fromSearch['email'],
            'broker' => $existing['broker'] ?? $fromSearch['broker'],
            'source' => $existing['source'] ?? ($fromSearch['source'] ?? 'rapidapi'),
            'raw' => $fromSearch['raw'] ?? [],
        ];

        if (listing_agent_is_complete($agent)) {
            script_flush('  Skip APIs — agent already complete in DB.');
            // Still persist broker/name from search if DB was empty on those (cheap local write).
            if (($existing['broker'] === null && $agent['broker']) || ($existing['name'] === null && $agent['name'])) {
                try {
                    $pdo = rapidapi_pdo_alive($pdo);
                    listing_agent_save($pdo, $listingId, $agent);
                } catch (Throwable $e) {
                    script_flush('  Agent save failed: ' . $e->getMessage());
                }
            }
            continue;
        }

        // 2) Optional RapidAPI property detail (off by default — wastes quota on this host).
        if ($tryPropertyDetail) {
            $detail = rapidapi_fetch_property_detail($host, $apiKey, $zpid, is_string($detailUrl) ? $detailUrl : null);
            if (is_array($detail)) {
                $fromDetail = listing_agent_from_rapid_payload($detail);
                $agent['name'] = $agent['name'] ?? $fromDetail['name'];
                $agent['phone'] = $agent['phone'] ?? $fromDetail['phone'];
                $agent['email'] = $agent['email'] ?? $fromDetail['email'];
                $agent['broker'] = $agent['broker'] ?? $fromDetail['broker'];
                if (!empty($fromDetail['raw'])) {
                    $agent['raw'] = $fromDetail['raw'];
                }
                $agent['source'] = 'rapidapi';
                script_flush('  RapidAPI detail: name=' . ($agent['name'] ?? '—')
                    . ' phone=' . ($agent['phone'] ?? '—')
                    . ' email=' . ($agent['email'] ?? '—'));
            } else {
                script_flush('  RapidAPI property detail skipped/empty.');
            }
        } else {
            script_flush('  RapidAPI property detail skipped (cost save).');
        }

        if (listing_agent_is_complete($agent)) {
            try {
                $pdo = rapidapi_pdo_alive($pdo);
                listing_agent_save($pdo, $listingId, $agent);
                script_flush('  Saved agent fields for listing id=' . $listingId);
            } catch (Throwable $e) {
                script_flush('  Agent save failed: ' . $e->getMessage());
            }
            continue;
        }

        // 3) Gemini + Search only if needed and not recently attempted.
        if (!listing_agent_should_call_gemini($agent, $existing['fetched_at'] ?? null, $geminiRetryDays)) {
            $days = max(1, $geminiRetryDays);
            script_flush("  Skip Gemini — incomplete but tried within last {$days} day(s).");
            // Save any new search-only fields (e.g. broker) without bumping a wasted Gemini attempt
            // unless we have something new worth storing.
            if (($agent['broker'] && !$existing['broker']) || ($agent['name'] && !$existing['name']) || ($agent['phone'] && !$existing['phone'])) {
                try {
                    $pdo = rapidapi_pdo_alive($pdo);
                    listing_agent_save($pdo, $listingId, $agent);
                    script_flush('  Saved partial agent fields for listing id=' . $listingId);
                } catch (Throwable $e) {
                    script_flush('  Agent save failed: ' . $e->getMessage());
                }
            }
            continue;
        }

        script_flush('  Missing fields — asking Gemini + Search…');
        $agent = listing_agent_gemini_enrich($address, $zpid, $detailUrl, $agent);
        script_flush('  After Gemini: name=' . ($agent['name'] ?? '—')
            . ' phone=' . ($agent['phone'] ?? '—')
            . ' email=' . ($agent['email'] ?? '—')
            . ' source=' . ($agent['source'] ?? ''));

        try {
            $pdo = rapidapi_pdo_alive($pdo);
            listing_agent_save($pdo, $listingId, $agent);
            script_flush('  Saved agent fields for listing id=' . $listingId);
        } catch (Throwable $e) {
            script_flush('  Agent save failed: ' . $e->getMessage());
        }
    }
}

foreach ($locations as $locationOrRid) {
    script_flush('RapidAPI: ' . $locationOrRid . ' (sort=newest, page=' . $page . ')');
    try {
        $data = rapidapi_fetch_sale($rapidApiHost, $rapidApiPath, $rapidApiKey, [
            'location_or_rid' => $locationOrRid,
            'property_types' => 'house',
            'sort' => 'newest',
            'page' => (string) $page,
            'doz' => '7',
        ]);
    } catch (Throwable $e) {
        script_flush($e->getMessage());
        continue;
    }

    $listings = rapidapi_listings_from_response($data, $maxResults);
    if ($listings === []) {
        script_flush('No listings parsed for ' . $locationOrRid);
        continue;
    }

    script_flush('Saving ' . count($listings) . ' listing(s) for ' . $locationOrRid);
    $pdo = rapidapi_pdo_alive($pdo);
    $saved = rapidapi_upsert_listings_db($pdo, $listings, $locationOrRid);
    $pdo = rapidapi_pdo_alive($pdo);
    rapidapi_enrich_listing_agents(
        $pdo,
        $rapidApiHost,
        $rapidApiKey,
        $saved,
        $rapidApiTryPropertyDetail,
        $geminiAgentRetryDays
    );
}
