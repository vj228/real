<?php
// yhome.ai/sms/webhook.php
// Twilio incoming SMS -> double opt-in (YES) -> Gemini listing Q&A -> lead capture -> agent alert
//
// Live at: https://yhome.ai/sms/webhook.php
// The repo is public — never hardcode secrets here. They come from:
//   db.credentials.php      host, name, user, pass   (gitignored; same DB as the rest of the site)
//   ai.credentials.php      gemini_api_key, gemini_model           (gitignored, optional)
//   twilio.credentials.php  twilio_sid, twilio_token               (gitignored, optional)
//   app_secrets DB table    same keys, used when the files above are missing on the server

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
if (!twilioSignatureValid()) {
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
    $resp = curl_exec($ch);
    curl_close($ch);
    return $resp;
}

function askGemini($prompt) {
    $url = 'https://generativelanguage.googleapis.com/v1beta/models/' . GEMINI_MODEL . ':generateContent?key=' . GEMINI_KEY;
    $payload = json_encode(['contents' => [['parts' => [['text' => $prompt]]]]]);
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
        CURLOPT_POSTFIELDS => $payload,
        CURLOPT_TIMEOUT => 25,
    ]);
    $resp = curl_exec($ch);
    curl_close($ch);
    $data = json_decode($resp, true);
    $text = $data['candidates'][0]['content']['parts'][0]['text'] ?? '';
    return trim($text);
}

function twimlOk() {
    header('Content-Type: text/xml');
    echo '<Response></Response>';
    exit;
}

function loadListing($db, $id) {
    $stmt = $db->prepare("SELECT * FROM zillow_sale_listings WHERE id = ? AND is_active = 1 LIMIT 1");
    $stmt->execute([$id]);
    return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
}

// Match a yhome.ai house link or a street address ("323 Santa Rosa", "1501 S Santa Anita Ave") to an active listing.
function findListingInText($db, $text) {
    if (preg_match('~house\.php\?id=(\d+)~i', $text, $m)) {
        return loadListing($db, (int) $m[1]);
    }
    if (!preg_match('/\b(\d{1,6})\s+[A-Za-z]/', $text, $m)) {
        return null;
    }
    $stmt = $db->prepare("SELECT * FROM zillow_sale_listings WHERE is_active = 1 AND address LIKE ?");
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
    $price = $l['list_price'] !== null ? '$' . number_format((float) $l['list_price']) : 'price n/a';
    $beds = $l['beds'] !== null ? rtrim(rtrim((string) $l['beds'], '0'), '.') . ' bed' : '';
    $baths = $l['baths'] !== null ? rtrim(rtrim((string) $l['baths'], '0'), '.') . ' bath' : '';
    return trim("{$l['address']} — " . implode(', ', array_filter([$beds, $baths, $price])));
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

$prompt = "You are a friendly AI assistant for yhome.ai answering a homebuyer by text about the home at {$listing['address']}. "
    . "Facts: list price " . ($listing['list_price'] !== null ? '$' . number_format((float) $listing['list_price']) : 'unknown')
    . ", {$listing['beds']} bed, {$listing['baths']} bath, {$listing['sqft']} sqft. "
    . (!$estimate
        ? "No yhome.ai renovation estimate yet. "
        : ((int) $estimate['total_high'] > 0
            ? "yhome.ai AI renovation estimate from listing media: $" . number_format((int) $estimate['total_low']) . " – $" . number_format((int) $estimate['total_high']) . ". "
            : "yhome.ai AI estimate from listing media found no visible renovation work needed (looks move-in ready); hidden issues need an inspection. "))
    . "Listing agent: $agentName. "
    . "Answer the buyer's question briefly, like a text message, under 240 characters, plain text. "
    . "Never include links, URLs, website names, email addresses, or phone numbers. "
    . "If asked about renovation cost, use the estimate above if available and say clearly it is not a quote. "
    . "If you don't know something, say so. "
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
