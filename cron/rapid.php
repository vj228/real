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

function rapidapi_upsert_listings_db(PDO $pdo, array $listings, string $searchQuery): void
{
    $sql = <<<'SQL'
INSERT INTO zillow_sale_listings (
    last_fetched_at, search_query, zpid, address, detail_url,
    list_price, zestimate, price_vs_zestimate_pct, price_per_sqft,
    property_tax_annual, tax_assessed_value, hoa_fee,
    beds, baths, sqft, days_on_zillow, img_src, images_json
) VALUES (
    :last_fetched_at, :search_query, :zpid, :address, :detail_url,
    :list_price, :zestimate, :price_vs_zestimate_pct, :price_per_sqft,
    :property_tax_annual, :tax_assessed_value, :hoa_fee,
    :beds, :baths, :sqft, :days_on_zillow, :img_src, :images_json
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
    images_json = VALUES(images_json)
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
    }

    script_flush(sprintf(
        'DB: %d new, %d updated, %d skipped (sent_to_j2v / j2v_file_* unchanged on update).',
        $inserted,
        $updated,
        $skipped
    ));
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
$locationOrRid = 'arcadia ca';
$pageWindow = 12; // rotate through pages to reduce repeats across 2-hour cron runs
$page = ((int) floor((int) date('G') / 2) % $pageWindow) + 1; // 1..6
$queryParams = [
    'location_or_rid' => $locationOrRid,
    'property_types' => 'house',
    'sort' => 'newest',
    'page' => (string) $page,
    'doz' => '7',
];

$maxResults = 10;

if ($rapidApiKey === '') {
    script_flush('Set $rapidApiKey in cron/rapid.php.');
    exit(1);
}
if (trim($locationOrRid) === '') {
    script_flush('Set $locationOrRid.');
    exit(1);
}

script_flush('RapidAPI: ' . $rapidApiHost . $rapidApiPath . ' (sort=newest, page=' . $page . ')');

$ch = curl_init();
curl_setopt_array($ch, [
    CURLOPT_URL => 'https://' . $rapidApiHost . $rapidApiPath . '?' . http_build_query($queryParams),
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
        'x-rapidapi-host: ' . $rapidApiHost,
        'x-rapidapi-key: ' . $rapidApiKey,
    ],
]);

$raw = curl_exec($ch);
$http = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
if ($raw === false) {
    script_flush('cURL error: ' . curl_error($ch));
    exit(1);
}

script_flush('RapidAPI responded HTTP ' . $http);

$data = json_decode($raw, true);
if (!is_array($data)) {
    script_flush("Invalid JSON. First 800 bytes:\n" . substr((string) $raw, 0, 800));
    exit(1);
}

if ($http < 200 || $http >= 300) {
    script_flush("RapidAPI error:\n" . json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    exit(1);
}

$listings = rapidapi_listings_from_response($data, $maxResults);

if ($listings === []) {
    script_flush(
        'No listings parsed. Adjust rapidapi_price_from_record / rapidapi_tax_from_record / address fields. Snippet:'
        . "\n" . substr(json_encode($data, JSON_UNESCAPED_UNICODE), 0, 1200)
    );
    exit(1);
}

require_once dirname(__DIR__) . '/pdo_connect.php';

$pdo = db_pdo_connect();
if ($pdo === null) {
    script_flush('DB not configured. Add db.credentials.php and run sql/zillow_sale_listings.sql');
    exit(1);
}

script_flush('Saving ' . count($listings) . ' listing(s) to zillow_sale_listings.');
rapidapi_upsert_listings_db($pdo, $listings, $locationOrRid);
