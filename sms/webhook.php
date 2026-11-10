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

// Find the listing mapped to this Twilio number
$stmt = $db->prepare("SELECT * FROM sms_listings WHERE twilio_number = ? LIMIT 1");
$stmt->execute([$to]);
$listing = $stmt->fetch(PDO::FETCH_ASSOC);
$listingId = $listing ? (int)$listing['id'] : 0;

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

function sendSms($toNumber, $fromNumber, $text) {
    $ch = curl_init('https://api.twilio.com/2010-04-01/Accounts/' . TWILIO_SID . '/Messages.json');
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_USERPWD => TWILIO_SID . ':' . TWILIO_TOKEN,
        CURLOPT_POSTFIELDS => http_build_query([
            'From' => $fromNumber,
            'To'   => $toNumber,
            'Body' => $text,
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

// Log every inbound message
if ($body !== '') {
    logLead($db, $listingId, $from, 'in', $body);
}

// --- STOP: opt out ---
if (in_array($upper, ['STOP', 'END', 'CANCEL', 'UNSUBSCRIBE', 'QUIT'], true)) {
    setStatus($db, $from, $to, 'opted_out');
    $msg = "yhome.ai: You've been unsubscribed. You won't receive more messages. Reply START to rejoin.";
    sendSms($from, $to, $msg);
    logLead($db, $listingId, $from, 'out', $msg);
    twimlOk();
}

// --- YES / START: confirm opt-in ---
if (in_array($upper, ['YES', 'Y', 'START', 'UNSTOP'], true)) {
    if ($status !== 'confirmed') {
        setStatus($db, $from, $to, 'confirmed');
        $greeting = $listing
            ? "yhome.ai: You're in! This is the AI assistant for {$listing['address']} — {$listing['beds']} bed, {$listing['baths']} bath, \${$listing['price']}. Ask me anything about the home!"
            : "yhome.ai: You're in! Ask me anything about the home.";
        sendSms($from, $to, $greeting);
        logLead($db, $listingId, $from, 'out', $greeting);
        twimlOk();
    }
    // already confirmed -> fall through and treat as a question
}

// --- Already opted out: stay silent ---
if ($status === 'opted_out') {
    twimlOk();
}

// --- Not yet confirmed: ask for YES (double opt-in, required by carriers) ---
if ($status !== 'confirmed') {
    $ask = "yhome.ai: Thanks for texting! Reply YES to confirm you want SMS updates about this listing, or reply STOP to opt out.";
    // only send the ask once per conversation burst (avoid loops): skip if last outbound was the ask
    $stmt = $db->prepare("SELECT message FROM sms_leads WHERE buyer_phone = ? AND direction = 'out' ORDER BY id DESC LIMIT 1");
    $stmt->execute([$from]);
    $lastOut = $stmt->fetchColumn();
    if ($lastOut !== $ask) {
        sendSms($from, $to, $ask);
        logLead($db, $listingId, $from, 'out', $ask);
    }
    twimlOk();
}

// --- Confirmed: answer the question with Gemini ---
if (!$listing) {
    $msg = "yhome.ai: Sorry, this number isn't linked to a listing yet.";
    sendSms($from, $to, $msg);
    logLead($db, 0, $from, 'out', $msg);
    twimlOk();
}

$prompt = "You are a friendly AI assistant for the home at {$listing['address']}. "
    . "Facts: price \${$listing['price']}, {$listing['beds']} bed, {$listing['baths']} bath, {$listing['sqft']} sqft. "
    . "Description: {$listing['description']} Listing agent: {$listing['agent_name']}. "
    . "Answer the buyer's question briefly, like a text message, under 300 characters. "
    . "If asked about renovation cost, give a rough ballpark and say clearly it is not a quote. "
    . "If you don't know something, say so and offer to have {$listing['agent_name']} follow up. "
    . "Buyer asks: \"$body\"";

$answer = askGemini($prompt);
if ($answer === '') {
    $answer = "yhome.ai: Sorry, I couldn't pull that up right now. Want me to have {$listing['agent_name']} follow up with you?";
}
sendSms($from, $to, $answer);
logLead($db, $listingId, $from, 'out', $answer);

// --- Agent alert: first question from this buyer for this listing ---
$stmt = $db->prepare("SELECT COUNT(*) FROM sms_leads WHERE listing_id = ? AND buyer_phone = ? AND direction = 'in'");
$stmt->execute([$listingId, $from]);
if ((int)$stmt->fetchColumn() <= 1 && !empty($listing['agent_phone'])) {
    $alert = "yhome.ai lead for {$listing['address']}: $from asked \"$body\"";
    sendSms($listing['agent_phone'], $to, $alert);
}

twimlOk();
