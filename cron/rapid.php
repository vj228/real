<?php
/**
 * RapidAPI Real Estate Zillow.com → JSON with Address, Price, Tax per listing.
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

function rapidapi_address_dedupe_key(string $addr): string {
    $a = strtolower(trim($addr));
    $a = preg_replace('/\s+/', ' ', $a);
    $a = preg_replace('/,\s*([a-z]{2})\s*,\s*(\d{5}(?:-\d{4})?)\s*$/', ', $1 $2', $a);
    return $a;
}

/** @return list<array{Address: string, Price: float|int|string|null, Tax: float|int|string|null}> */
function rapidapi_extract_listings(mixed $node, int $max): array {
    $out = [];
    $seen = [];
    $walk = function ($n, int $depth) use (&$walk, &$out, &$seen, $max): void {
        if (count($out) >= $max || $depth > 22) {
            return;
        }
        if (!is_array($n)) {
            return;
        }
        if (array_is_list($n)) {
            foreach ($n as $item) {
                $walk($item, $depth + 1);
                if (count($out) >= $max) {
                    return;
                }
            }
            return;
        }
        $addr = rapidapi_address_from_record($n);
        if ($addr !== null) {
            $k = rapidapi_address_dedupe_key($addr);
            if (!isset($seen[$k])) {
                $seen[$k] = true;
                $out[] = [
                    'Address' => $addr,
                    'Price' => rapidapi_price_from_record($n),
                    'Tax' => rapidapi_tax_from_record($n),
                ];
            }
        }
        foreach ($n as $v) {
            if (is_array($v)) {
                $walk($v, $depth + 1);
            }
            if (count($out) >= $max) {
                return;
            }
        }
    };
    $walk($node, 0);
    return $out;
}

$rapidApiKey = '24c72349a4msh978d1a453ef7522p193f33jsn404aedf6c8e9';
$rapidApiHost = 'real-estate-zillow-com.p.rapidapi.com';
$rapidApiPath = '/v1/search/sale';
$locationOrRid = 'arcadia ca';
$queryParams = [
    'location_or_rid' => $locationOrRid,
    'property_types' => 'house',
    'sort' => 'relevant',
    'page' => '1',
    'doz' => '7',
];

$maxResults = 10;
$outputFile = __DIR__ . '/addresses_from_rapidapi.json';

if ($rapidApiKey === '') {
    script_flush('Set $rapidApiKey in cron/rapid.php.');
    exit(1);
}
if (trim($locationOrRid) === '') {
    script_flush('Set $locationOrRid.');
    exit(1);
}

script_flush('RapidAPI: ' . $rapidApiHost . $rapidApiPath);

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

$listings = rapidapi_extract_listings($data, $maxResults);

if ($listings === []) {
    script_flush(
        'No listings parsed. Adjust rapidapi_price_from_record / rapidapi_tax_from_record / address fields. Snippet:'
        . "\n" . substr(json_encode($data, JSON_UNESCAPED_UNICODE), 0, 1200)
    );
    exit(1);
}

$payload = [
    'fetchedAt' => date('c'),
    'searchQuery' => $locationOrRid,
    'path' => $rapidApiPath,
    'count' => count($listings),
    'listings' => $listings,
];

if (file_put_contents($outputFile, json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE), LOCK_EX) === false) {
    script_flush("Could not write: {$outputFile}");
    exit(1);
}

script_flush('Done. Wrote ' . count($listings) . ' listing(s) to ' . $outputFile);
