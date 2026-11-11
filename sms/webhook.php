<?php
// yhome.ai/sms/webhook.php
// Twilio incoming SMS -> ask which home (address) -> Gemini listing Q&A -> lead capture -> agent alert
//
// Live at: https://yhome.ai/sms/webhook.php
// The repo is public — never hardcode secrets here. They come from:
//   db.credentials.php      host, name, user, pass   (gitignored; same DB as the rest of the site)
//   ai.credentials.php      gemini_api_key, gemini_model           (gitignored, optional)
//   twilio.credentials.php  twilio_sid, twilio_token               (gitignored, optional)
//   app_secrets DB table    same keys, used when the files above are missing on the server

// sms/test.php includes this file with YH_SMS_TEST defined: Twilio is skipped and replies come back as JSON.
$yhTest = defined('YH_SMS_TEST');
// Web lookups can outlast Twilio's 15s webhook timeout; replies go out via the REST API, so keep running.
ignore_user_abort(true);
set_time_limit(90);

// Browser visits and anything that isn't a Twilio POST get a blank 200.
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST' || empty($_POST['From']) || empty($_POST['To'])) {
    exit;
}

// ---------------- CONFIG ----------------
$yhRoot = dirname(__DIR__);
$yhLoad = static function (string $file) use ($yhRoot): array {
    $path = $yhRoot . '/' . $file;
    $cfg = is_readable($path) ? require $path : [];
    return is_array($cfg) ? $cfg : [];
};
$yhDb = $yhLoad('db.credentials.php');
$yhSecrets = $yhLoad('ai.credentials.php') + $yhLoad('twilio.credentials.php');

define('DB_HOST', (string) ($yhDb['host'] ?? 'localhost'));
define('DB_NAME', (string) ($yhDb['name'] ?? ''));
define('DB_USER', (string) ($yhDb['user'] ?? ''));
define('DB_PASS', (string) ($yhDb['pass'] ?? ''));

$db = new PDO(
    'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4',
    DB_USER, DB_PASS,
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
);
foreach ($db->query("SELECT name, value FROM app_secrets")->fetchAll(PDO::FETCH_KEY_PAIR) as $k => $v) {
    if (empty($yhSecrets[$k])) {
        $yhSecrets[$k] = $v;
    }
}

define('TWILIO_SID',   (string) ($yhSecrets['twilio_sid'] ?? ''));
define('TWILIO_TOKEN', (string) ($yhSecrets['twilio_token'] ?? ''));
define('GEMINI_KEY',   (string) ($yhSecrets['gemini_api_key'] ?? ''));
define('GEMINI_MODEL', (string) ($yhSecrets['gemini_model'] ?? 'gemini-3.6-flash'));
define('RAPIDAPI_KEY', (string) ($yhSecrets['rapidapi_key'] ?? ''));
// ----------------------------------------

// Reject requests not signed by Twilio, so nobody can spoof From and make us text arbitrary numbers.
// Without a token configured, the webhook stays inert.
function twilioSignatureValid(): bool {
    if (TWILIO_TOKEN === '') {
        return false;
    }
    $sig = $_SERVER['HTTP_X_TWILIO_SIGNATURE'] ?? '';
    $url = 'https://' . ($_SERVER['HTTP_HOST'] ?? 'yhome.ai') . ($_SERVER['REQUEST_URI'] ?? '/sms/webhook.php');
    $params = $_POST;
    ksort($params);
    foreach ($params as $k => $v) {
        $url .= $k . $v;
    }
    $expected = base64_encode(hash_hmac('sha1', $url, TWILIO_TOKEN, true));
    return $sig !== '' && hash_equals($expected, $sig);
}
if (!$yhTest && !twilioSignatureValid()) {
    header('Content-Type: text/xml');
    echo '<Response></Response>';
    exit;
}

$from  = $_POST['From'] ?? '';
$to    = $_POST['To']   ?? '';   // your Twilio number
$body  = trim($_POST['Body'] ?? '');
$upper = strtoupper($body);

// Texting agents who never opted in can get the A2P campaign suspended — keep off unless agents consent.
const AGENT_ALERTS = false;

// Get or create subscriber row (consent tracking)
$stmt = $db->prepare("SELECT * FROM sms_subscribers WHERE buyer_phone = ? AND twilio_number = ? LIMIT 1");
$stmt->execute([$from, $to]);
$sub = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$sub) {
    $stmt = $db->prepare("INSERT INTO sms_subscribers (buyer_phone, twilio_number, status) VALUES (?, ?, 'pending')");
    $stmt->execute([$from, $to]);
    $sub = ['status' => 'pending'];
}
$status = $sub['status'];

function logLead($db, $listingId, $phone, $direction, $message) {
    $stmt = $db->prepare("INSERT INTO sms_leads (listing_id, buyer_phone, direction, message) VALUES (?, ?, ?, ?)");
    $stmt->execute([$listingId, $phone, $direction, $message]);
}

function setStatus($db, $from, $to, $status) {
    $stmt = $db->prepare("UPDATE sms_subscribers SET status = ? WHERE buyer_phone = ? AND twilio_number = ?");
    $stmt->execute([$status, $from, $to]);
}

// Registered A2P 10DLC messaging service. Carriers filter (error 30007) texts that don't match the
// campaign's sample messages, and the campaign declares no links or phone numbers in messages.
const MESSAGING_SERVICE_SID = 'MGc049c9b7fadc8cbd2edc5ded01ad15ca';

function smsSafe($text) {
    $text = preg_replace('~(https?://|www\.)\S+~i', '', $text);
    $text = preg_replace('/\b(?!yhome\.ai\b)[a-z0-9-]+\.(com|net|org|ai|io|co)\b/i', '', $text);
    $text = preg_replace('/\+?1?[\s.-]?\(?\d{3}\)?[\s.-]?\d{3}[\s.-]?\d{4}\b/', '', $text);
    return trim(preg_replace('/\s{2,}/', ' ', $text));
}

function sendSms($toNumber, $fromNumber, $text) {
    if (defined('YH_SMS_TEST')) {
        $GLOBALS['yhTestReplies'][] = smsSafe($text);
        return '';
    }
    $ch = curl_init('https://api.twilio.com/2010-04-01/Accounts/' . TWILIO_SID . '/Messages.json');
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_USERPWD => TWILIO_SID . ':' . TWILIO_TOKEN,
        CURLOPT_POSTFIELDS => http_build_query([
            'MessagingServiceSid' => MESSAGING_SERVICE_SID,
            'To'   => $toNumber,
            'Body' => smsSafe($text),
        ]),
    ]);
    return curl_exec($ch);
}

function askGemini($prompt) {
    $url = 'https://generativelanguage.googleapis.com/v1beta/models/' . GEMINI_MODEL . ':generateContent?key=' . GEMINI_KEY;
    $req = ['contents' => [['parts' => [['text' => $prompt]]]]];
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
        CURLOPT_POSTFIELDS => json_encode($req),
        CURLOPT_TIMEOUT => 40,
    ]);
    $data = json_decode((string) curl_exec($ch), true);
    $text = '';
    foreach ($data['candidates'][0]['content']['parts'] ?? [] as $part) {
        $text .= $part['text'] ?? '';
    }
    return trim($text);
}

// RapidAPI "Real Estate Zillow.com". Free plan has a hard monthly cap, so only call it for homes
// that aren't in the DB yet, and cache the details per listing in sms_home_facts.
function rapidGet($path, array $params) {
    if (RAPIDAPI_KEY === '') {
        return null;
    }
    $ch = curl_init('https://real-estate-zillow-com.p.rapidapi.com' . $path . '?' . http_build_query($params));
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 25,
        CURLOPT_HTTPHEADER => [
            'x-rapidapi-key: ' . RAPIDAPI_KEY,
            'x-rapidapi-host: real-estate-zillow-com.p.rapidapi.com',
        ],
    ]);
    $data = json_decode((string) curl_exec($ch), true);
    return (int) ($data['status'] ?? 0) === 200 && is_array($data['data'] ?? null) ? $data['data'] : null;
}

// Address text -> Zillow zpid (1 API call).
function rapidZpid($text) {
    $query = preg_match('/,|\b\d{5}\b|arcadia/i', $text) ? $text : "$text, Arcadia, CA";
    foreach (rapidGet('/v1/autocomplete', ['query' => $query])['results'] ?? [] as $r) {
        if (($r['resultType'] ?? '') === 'Address' && !empty($r['metaData']['zpid'])) {
            return (string) $r['metaData']['zpid'];
        }
    }
    return null;
}

// Full property details for a zpid (1 API call), trimmed to what the bot needs.
function rapidDetails($zpid) {
    $d = rapidGet('/v1/property', ['zpid_or_url' => $zpid]);
    if (!$d || empty($d['streetAddress'])) {
        return null;
    }
    return [
        'address' => trim("{$d['streetAddress']}, {$d['city']}, {$d['state']} {$d['zipcode']}"),
        'status' => $d['homeStatus'] ?? null,
        'type' => $d['homeType'] ?? null,
        'price' => $d['price'] ?? null,
        'zestimate' => $d['zestimate'] ?? null,
        'rent_zestimate' => $d['rentZestimate'] ?? null,
        'beds' => $d['bedrooms'] ?? null,
        'baths' => $d['bathrooms'] ?? null,
        'sqft' => $d['livingArea'] ?? null,
        'lot_sqft' => $d['lotSize'] ?? null,
        'year_built' => $d['yearBuilt'] ?? null,
        'hoa_monthly' => $d['monthlyHoaFee'] ?? null,
        'tax_assessed' => $d['taxAssessedValue'] ?? null,
        'days_on_zillow' => $d['daysOnZillow'] ?? null,
        'last_sold_price' => $d['lastSoldPrice'] ?? null,
        'agent' => $d['attributionInfo']['agentName'] ?? null,
        'img' => $d['responsivePhotos'][0]['url'] ?? ($d['thumb'] ?? null),
        'url' => !empty($d['hdpUrl']) ? 'https://www.zillow.com' . $d['hdpUrl'] : null,
        'description' => mb_substr(trim((string) ($d['description'] ?? '')), 0, 600),
    ];
}

function saveFacts($db, $listingId, array $facts) {
    $stmt = $db->prepare("REPLACE INTO sms_home_facts (listing_id, facts_json) VALUES (?, ?)");
    $stmt->execute([$listingId, json_encode($facts)]);
}

// Cached Zillow details for a listing; fetched once (1 API call) if the listing has a real zpid.
function homeFacts($db, $listing) {
    $stmt = $db->prepare("SELECT facts_json FROM sms_home_facts WHERE listing_id = ?");
    $stmt->execute([$listing['id']]);
    $json = $stmt->fetchColumn();
    if ($json !== false) {
        return json_decode($json, true) ?: null;
    }
    if (!ctype_digit((string) $listing['zpid'])) {
        return null;
    }
    $facts = rapidDetails($listing['zpid']);
    if ($facts) {
        saveFacts($db, (int) $listing['id'], $facts);
    }
    return $facts;
}

// An address that isn't an active listing: check every row in the DB first (free),
// then RapidAPI (2 calls), and save it as an SMS-only listing (is_active = 0, search_query = 'sms')
// so it stays off the public houses page.
function lookupAndSaveListing($db, $text) {
    $listing = findListingInText($db, $text, true);
    if ($listing) {
        return $listing;
    }
    $zpid = rapidZpid($text);
    if ($zpid === null) {
        return null;
    }
    $stmt = $db->prepare("SELECT id FROM zillow_sale_listings WHERE zpid = ? LIMIT 1");
    $stmt->execute([$zpid]);
    $id = (int) $stmt->fetchColumn();
    if ($id) {
        return loadListing($db, $id);
    }
    $f = rapidDetails($zpid);
    if (!$f) {
        return null;
    }
    $num = static fn($v) => is_numeric($v) ? $v : null;
    $stmt = $db->prepare("INSERT INTO zillow_sale_listings
        (last_fetched_at, search_query, zpid, address, detail_url, list_price, zestimate, hoa_fee, tax_assessed_value,
         beds, baths, sqft, days_on_zillow, img_src, listing_agent_name, is_active)
        VALUES (NOW(), 'sms', ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 0)");
    $stmt->execute([
        $zpid, $f['address'], $f['url'],
        $f['status'] === 'FOR_SALE' ? $num($f['price']) : null,
        $num($f['zestimate']), $num($f['hoa_monthly']), $num($f['tax_assessed']),
        $num($f['beds']), $num($f['baths']), $num($f['sqft']), $num($f['days_on_zillow']),
        $f['img'], $f['agent'] ?: null,
    ]);
    $id = (int) $db->lastInsertId();
    saveFacts($db, $id, $f);
    return loadListing($db, $id);
}

function twimlOk() {
    if (defined('YH_SMS_TEST')) {
        header('Content-Type: application/json');
        echo json_encode(['replies' => $GLOBALS['yhTestReplies'] ?? []]);
        exit;
    }
    header('Content-Type: text/xml');
    echo '<Response></Response>';
    exit;
}

function loadListing($db, $id) {
    $stmt = $db->prepare("SELECT * FROM zillow_sale_listings WHERE id = ? LIMIT 1");
    $stmt->execute([$id]);
    return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
}

// Match a yhome.ai house link or a street address ("323 Santa Rosa", "1501 S Santa Anita Ave") to a listing:
// active and SMS-saved ones by default, or any row (including off-market) with $anyStatus.
function findListingInText($db, $text, $anyStatus = false) {
    if (preg_match('~house\.php\?id=(\d+)~i', $text, $m)) {
        return loadListing($db, (int) $m[1]);
    }
    if (!preg_match('/\b(\d{1,6})\s+[A-Za-z]/', $text, $m)) {
        return null;
    }
    $stmt = $db->prepare("SELECT * FROM zillow_sale_listings WHERE "
        . ($anyStatus ? "1" : "(is_active = 1 OR search_query = 'sms')") . " AND address LIKE ?");
    $stmt->execute([$m[1] . ' %']);
    $candidates = $stmt->fetchAll(PDO::FETCH_ASSOC);
    if (!$candidates) {
        return null;
    }
    $skip = ['st', 'street', 'ave', 'avenue', 'rd', 'road', 'dr', 'drive', 'blvd', 'ln', 'lane', 'way', 'ct', 'court',
        'pl', 'the', 'and', 'ca', 'house', 'home', 'about', 'for'];
    $words = array_diff(preg_split('/[^a-z]+/', strtolower($text), -1, PREG_SPLIT_NO_EMPTY), $skip);
    $best = null;
    $bestScore = 0;
    $tie = false;
    foreach ($candidates as $c) {
        $street = preg_split('/[^a-z]+/', strtolower(explode(',', $c['address'])[0]), -1, PREG_SPLIT_NO_EMPTY);
        $score = count(array_intersect(array_unique($words), array_diff($street, $skip)));
        if ($score > $bestScore) {
            [$best, $bestScore, $tie] = [$c, $score, false];
        } elseif ($score === $bestScore && $score > 0) {
            $tie = true;
        }
    }
    return ($best && !$tie) ? $best : null;
}

function listingSummary($l) {
    $price = $l['list_price'] !== null ? '$' . number_format((float) $l['list_price']) : '';
    $beds = $l['beds'] !== null ? rtrim(rtrim((string) $l['beds'], '0'), '.') . ' bed' : '';
    $baths = $l['baths'] !== null ? rtrim(rtrim((string) $l['baths'], '0'), '.') . ' bath' : '';
    $facts = implode(', ', array_filter([$beds, $baths, $price]));
    return $facts === '' ? $l['address'] : "{$l['address']} — $facts";
}

$askAddress = "yhome.ai: Which home are you asking about? Reply with the street address (for example: 323 Santa Rosa Rd).";

// Which house: one named in this message, else the last one this buyer asked about.
$listing = findListingInText($db, $body);
$justPicked = $listing !== null;
if (!$listing) {
    $stmt = $db->prepare("SELECT listing_id FROM sms_leads WHERE buyer_phone = ? AND listing_id > 0 ORDER BY id DESC LIMIT 1");
    $stmt->execute([$from]);
    $lastId = (int) $stmt->fetchColumn();
    $listing = $lastId ? loadListing($db, $lastId) : null;
}
$listingId = $listing ? (int) $listing['id'] : 0;

// Log every inbound message
if ($body !== '') {
    logLead($db, $justPicked ? $listingId : 0, $from, 'in', $body);
}

// --- STOP / HELP: Twilio's opt-out handling already sends the registered reply; just record it ---
if (in_array($upper, ['STOP', 'END', 'CANCEL', 'UNSUBSCRIBE', 'QUIT', 'STOPALL', 'OPTOUT', 'REVOKE'], true)) {
    setStatus($db, $from, $to, 'opted_out');
    twimlOk();
}
if (in_array($upper, ['HELP', 'INFO'], true)) {
    twimlOk();
}

// --- START / UNSTOP: Twilio resubscribes them; continue as a normal conversation ---
if (in_array($upper, ['START', 'UNSTOP'], true)) {
    setStatus($db, $from, $to, 'confirmed');
    $status = 'confirmed';
}

// --- Already opted out: stay silent ---
if ($status === 'opted_out') {
    twimlOk();
}

// Texting us first is the opt-in; the first reply carries the opt-out notice.
$firstContact = $status !== 'confirmed';
if ($firstContact) {
    setStatus($db, $from, $to, 'confirmed');
}
$optOut = $firstContact ? ' Reply STOP to opt out.' : '';

// --- An address that isn't an active listing: check the DB, then RapidAPI, and save it ---
if (!$justPicked && preg_match('/^\s*\d{1,6}\s+[A-Za-z]/', $body)) {
    $listing = lookupAndSaveListing($db, $body);
    if (!$listing) {
        $msg = "yhome.ai: I couldn't find that address. Please check it and include the city (for example: 323 Santa Rosa Rd, Arcadia)." . $optOut;
        sendSms($from, $to, $msg);
        logLead($db, 0, $from, 'out', $msg);
        twimlOk();
    }
    $justPicked = true;
    $listingId = (int) $listing['id'];
}

// --- No house yet: ask for the address ---
if (!$listing) {
    $msg = (preg_match('/\b\d{1,6}\s+[A-Za-z]/', $body)
        ? "yhome.ai: I couldn't find that address. Please reply with the street address as shown on the listing page (for example: 323 Santa Rosa Rd)."
        : $askAddress) . $optOut;
    sendSms($from, $to, $msg);
    logLead($db, 0, $from, 'out', $msg);
    twimlOk();
}

// --- They just named the house: confirm it ---
if ($justPicked) {
    $msg = "yhome.ai: Got it — " . listingSummary($listing) . ". What would you like to know about this home?" . $optOut;
    sendSms($from, $to, $msg);
    logLead($db, $listingId, $from, 'out', $msg);
    twimlOk();
}

// --- Answer the question with Gemini ---
$stmt = $db->prepare("SELECT total_low, total_high FROM ai_analyses WHERE listing_id = ? ORDER BY analyzed_at DESC, id DESC LIMIT 1");
$stmt->execute([$listingId]);
$estimate = $stmt->fetch(PDO::FETCH_ASSOC);
$agentName = trim((string) ($listing['listing_agent_name'] ?? '')) ?: 'the listing agent';
$zillow = homeFacts($db, $listing);
if ($zillow) {
    unset($zillow['img'], $zillow['url'], $zillow['address']);
}

$prompt = "You are a friendly AI assistant for yhome.ai answering a homebuyer by text about the home at {$listing['address']}. "
    . "Facts: list price " . ($listing['list_price'] !== null ? '$' . number_format((float) $listing['list_price']) : 'unknown')
    . ", beds " . ($listing['beds'] ?? 'unknown') . ", baths " . ($listing['baths'] ?? 'unknown')
    . ", sqft " . ($listing['sqft'] ?? 'unknown') . ". "
    . ($zillow
        ? "Zillow details (status FOR_SALE means 'price' is the asking price; otherwise the home isn't for sale and 'price' is only an estimate): "
            . json_encode(array_filter($zillow, static fn($v) => $v !== null && $v !== '')) . ". "
        : '')
    . (!$estimate
        ? "No yhome.ai renovation estimate yet. "
        : ((int) $estimate['total_high'] > 0
            ? "yhome.ai AI renovation estimate from listing media: $" . number_format((int) $estimate['total_low']) . " – $" . number_format((int) $estimate['total_high']) . ". "
            : "yhome.ai AI estimate from listing media found no visible renovation work needed (looks move-in ready); hidden issues need an inspection. "))
    . "Listing agent: $agentName. "
    . "Answer the buyer's question briefly, like a text message, under 240 characters, plain text. "
    . "Never include links, URLs, website names, email addresses, or phone numbers. "
    . "If asked about renovation cost, use the estimate above if available and say clearly it is not a quote. "
    . "For facts about this specific home (price, beds, baths, size, year built, HOA, condition), use only the facts above; if a fact isn't listed, say you don't have it. "
    . "For general questions (schools, neighborhood, commute, the city, buying or financing tips), answer helpfully from your own knowledge. "
    . "Don't offer to contact the agent; that offer is added after your answer. "
    . "Buyer asks: \"$body\"";

$answer = askGemini($prompt);
if ($answer === '') {
    $answer = askGemini($prompt);
}
$answer = $answer === ''
    ? "yhome.ai: Sorry, I couldn't pull that up right now. Want me to have the listing agent follow up with you? Reply STOP to opt out."
    : "yhome.ai: " . rtrim(smsSafe($answer)) . " Want me to have the listing agent follow up with you? Reply STOP to opt out.";
sendSms($from, $to, $answer);
logLead($db, $listingId, $from, 'out', $answer);

// --- Agent alert: first question from this buyer for this listing ---
$agentPhone = trim((string) ($listing['listing_agent_phone'] ?? ''));
if (AGENT_ALERTS && $agentPhone !== '') {
    $stmt = $db->prepare("SELECT COUNT(*) FROM sms_leads WHERE listing_id = ? AND buyer_phone = ? AND direction = 'out'");
    $stmt->execute([$listingId, $from]);
    if ((int) $stmt->fetchColumn() <= 2) {
        sendSms($agentPhone, $to, "yhome.ai lead for {$listing['address']}: $from asked \"$body\"");
    }
}

twimlOk();
