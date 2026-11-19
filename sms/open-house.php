<?php
// Simple editor for listing open-house text (dedicated column — no JSON editing).
declare(strict_types=1);

require_once dirname(__DIR__) . '/pdo_connect.php';

$db = db_pdo_connect();
if ($db === null) {
    http_response_code(500);
    echo 'Database connection failed.';
    exit;
}

// Ensure column exists (Hostinger may not have run the migration yet).
try {
    $db->query('SELECT open_house FROM sms_home_facts LIMIT 1');
} catch (Throwable $e) {
    $db->exec('ALTER TABLE sms_home_facts ADD COLUMN open_house VARCHAR(500) NULL DEFAULT NULL AFTER facts_json');
}

function h(?string $s): string
{
    return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
}

$saved = false;
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $listingId = (int) ($_POST['listing_id'] ?? 0);
    // Empty string = explicitly none (beats stale JSON). Leave column non-null after a manual edit.
    $open = mb_substr(trim((string) ($_POST['open_house'] ?? '')), 0, 500);

    if ($listingId <= 0) {
        $error = 'Missing listing.';
    } else {
        $exists = $db->prepare('SELECT id, address FROM zillow_sale_listings WHERE id = ? AND is_active = 1');
        $exists->execute([$listingId]);
        $listing = $exists->fetch(PDO::FETCH_ASSOC);
        if (!$listing) {
            $error = 'Listing not found.';
        } else {
            // Upsert facts row; keep existing facts_json if present.
            $stmt = $db->prepare(
                'INSERT INTO sms_home_facts (listing_id, facts_json, open_house)
                 VALUES (?, ?, ?)
                 ON DUPLICATE KEY UPDATE open_house = VALUES(open_house)'
            );
            $stmt->execute([$listingId, '{}', $open]);
            $saved = true;
        }
    }
}

$rows = $db->query(
    'SELECT l.id, l.address, l.list_price, f.open_house
     FROM zillow_sale_listings l
     LEFT JOIN sms_home_facts f ON f.listing_id = l.id
     WHERE l.is_active = 1
     ORDER BY (f.open_house IS NULL OR f.open_house = "") ASC, l.address ASC'
)->fetchAll(PDO::FETCH_ASSOC);
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Open houses — yHome.ai</title>
<style>
  :root { --ink: #14261c; --muted: #5b6e63; --line: #d7e5dc; --bg: #f4f8f5; --accent: #176948; }
  * { box-sizing: border-box; }
  body { margin: 0; font-family: Georgia, "Times New Roman", serif; background: var(--bg); color: var(--ink); }
  main { max-width: 720px; margin: 0 auto; padding: 28px 16px 64px; }
  h1 { margin: 0 0 6px; font-size: 1.7rem; }
  .sub { margin: 0 0 22px; color: var(--muted); font-family: system-ui, sans-serif; font-size: 0.95rem; }
  .flash { margin: 0 0 16px; padding: 10px 12px; border-radius: 8px; font-family: system-ui, sans-serif; font-size: 0.9rem; }
  .flash.ok { background: #ebf8f1; color: #176948; }
  .flash.err { background: #fdecea; color: #9b1c1c; }
  .card { background: #fff; border: 1px solid var(--line); border-radius: 12px; padding: 14px; margin-bottom: 10px; }
  .addr { margin: 0 0 4px; font-size: 1.05rem; }
  .meta { margin: 0 0 10px; color: var(--muted); font-family: system-ui, sans-serif; font-size: 0.82rem; }
  form { display: flex; gap: 8px; align-items: stretch; }
  input[type=text] { flex: 1; border: 1px solid var(--line); border-radius: 8px; padding: 9px 11px;
    font-family: system-ui, sans-serif; font-size: 0.92rem; }
  button { border: 0; background: var(--accent); color: #fff; border-radius: 8px; padding: 0 14px;
    font-family: system-ui, sans-serif; font-size: 0.88rem; font-weight: 600; cursor: pointer; white-space: nowrap; }
  .hint { margin-top: 4px; color: var(--muted); font-family: system-ui, sans-serif; font-size: 0.75rem; }
  a.home { color: var(--accent); font-family: system-ui, sans-serif; font-size: 0.85rem; }
</style>
</head>
<body>
<main>
  <p><a class="home" href="/houses.php">← Houses</a></p>
  <h1>Open houses</h1>
  <p class="sub">Type a short schedule (e.g. <em>Sat Nov 22, 1–4pm</em>). Leave blank and save to clear. SMS answers use this field.</p>

  <?php if ($saved): ?>
    <p class="flash ok">Saved.</p>
  <?php endif; ?>
  <?php if ($error !== ''): ?>
    <p class="flash err"><?= h($error) ?></p>
  <?php endif; ?>

  <?php foreach ($rows as $r): ?>
    <div class="card">
      <p class="addr"><?= h((string) $r['address']) ?></p>
      <p class="meta">#<?= (int) $r['id'] ?><?= $r['list_price'] !== null ? ' · $' . number_format((float) $r['list_price']) : '' ?></p>
      <form method="post">
        <input type="hidden" name="listing_id" value="<?= (int) $r['id'] ?>">
        <input type="text" name="open_house" maxlength="500"
               value="<?= h((string) ($r['open_house'] ?? '')) ?>"
               placeholder="e.g. Sat Nov 22, 1–4pm">
        <button type="submit">Save</button>
      </form>
      <?php if (trim((string) ($r['open_house'] ?? '')) === ''): ?>
        <p class="hint">No open house set</p>
      <?php endif; ?>
    </div>
  <?php endforeach; ?>
</main>
</body>
</html>
