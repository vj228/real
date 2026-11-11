<?php
// yhome.ai/sms/test.php — fake SMS chat that runs the real webhook flow without Twilio.
// Localhost only: it skips Twilio's signature check and calls Gemini.

$host = preg_replace('/:\d+$/', '', $_SERVER['HTTP_HOST'] ?? '');
if (!in_array($host, ['localhost', '127.0.0.1', '[::1]'], true)) {
    http_response_code(404);
    exit;
}

const TEST_BUYER = '+15550001234';
const TEST_NUMBER = '+16263238836';

if (($_POST['action'] ?? '') === 'reset') {
    require_once dirname(__DIR__) . '/pdo_connect.php';
    $db = db_pdo_connect();
    $db->prepare("DELETE FROM sms_leads WHERE buyer_phone = ?")->execute([TEST_BUYER]);
    $db->prepare("DELETE FROM sms_subscribers WHERE buyer_phone = ?")->execute([TEST_BUYER]);
    header('Content-Type: application/json');
    echo json_encode(['ok' => true]);
    exit;
}

if (($_POST['action'] ?? '') === 'send') {
    $_POST = ['From' => TEST_BUYER, 'To' => TEST_NUMBER, 'Body' => (string) ($_POST['body'] ?? '')];
    define('YH_SMS_TEST', true);
    require __DIR__ . '/webhook.php';
    exit;
}
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>SMS test — yhome.ai</title>
<style>
  body { margin: 0; font-family: -apple-system, system-ui, sans-serif; background: #f2f2f7; }
  .phone { max-width: 420px; margin: 24px auto; background: #fff; border-radius: 24px; box-shadow: 0 4px 24px rgba(0,0,0,.1);
           display: flex; flex-direction: column; height: calc(100vh - 48px); overflow: hidden; }
  header { padding: 14px 16px; border-bottom: 1px solid #e5e5ea; display: flex; justify-content: space-between; align-items: center; }
  header b { font-size: 16px; }
  header small { color: #8e8e93; display: block; }
  header button { border: 0; background: #e5e5ea; border-radius: 14px; padding: 6px 12px; cursor: pointer; }
  #chat { flex: 1; overflow-y: auto; padding: 16px; display: flex; flex-direction: column; gap: 8px; }
  .msg { max-width: 78%; padding: 9px 13px; border-radius: 18px; line-height: 1.35; white-space: pre-wrap; font-size: 15px; }
  .me { align-self: flex-end; background: #0b84ff; color: #fff; }
  .bot { align-self: flex-start; background: #e9e9eb; color: #000; }
  .note { align-self: center; color: #8e8e93; font-size: 12px; }
  form { display: flex; gap: 8px; padding: 10px; border-top: 1px solid #e5e5ea; }
  input { flex: 1; border: 1px solid #d1d1d6; border-radius: 18px; padding: 9px 14px; font-size: 15px; outline: none; }
  form button { border: 0; background: #0b84ff; color: #fff; border-radius: 18px; padding: 0 16px; font-size: 15px; cursor: pointer; }
  form button:disabled { background: #a0c8f5; }
</style>
</head>
<body>
<div class="phone">
  <header>
    <div><b>(626) 323-8836</b><small>Test mode — no real SMS is sent</small></div>
    <button id="reset" type="button">Start over</button>
  </header>
  <div id="chat"><div class="note">Text "Hi" to start, like a buyer would.</div></div>
  <form id="form">
    <input id="body" autocomplete="off" placeholder="Text message" autofocus>
    <button id="send">Send</button>
  </form>
</div>
<script>
const chat = document.getElementById('chat');
const input = document.getElementById('body');
const send = document.getElementById('send');

function add(text, cls) {
  const d = document.createElement('div');
  d.className = 'msg ' + cls;
  d.textContent = text;
  chat.appendChild(d);
  chat.scrollTop = chat.scrollHeight;
  return d;
}

async function post(data) {
  const r = await fetch('test.php', { method: 'POST', body: new URLSearchParams(data) });
  return r.json();
}

document.getElementById('form').addEventListener('submit', async (e) => {
  e.preventDefault();
  const text = input.value.trim();
  if (!text) return;
  add(text, 'me');
  input.value = '';
  send.disabled = true;
  const typing = add('…', 'bot');
  try {
    const res = await post({ action: 'send', body: text });
    typing.remove();
    if (!res.replies.length) add('(no reply)', 'note');
    res.replies.forEach((t) => add(t, 'bot'));
  } catch (err) {
    typing.remove();
    add('Error: ' + err.message, 'note');
  }
  send.disabled = false;
  input.focus();
});

document.getElementById('reset').addEventListener('click', async () => {
  await post({ action: 'reset' });
  chat.innerHTML = '<div class="note">Started over. Text "Hi" to begin.</div>';
  input.focus();
});
</script>
</body>
</html>
