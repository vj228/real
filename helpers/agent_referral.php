<?php

declare(strict_types=1);

/**
 * Agent referral capture + attribution helpers.
 * Cookie keeps ref across pages; visitor_key ties visit → walkthrough.
 */

const YHOME_REF_COOKIE = 'yhome_ref';
const YHOME_VISITOR_COOKIE = 'yhome_vid';
const YHOME_REF_COOKIE_DAYS = 60;

function agent_ref_normalize(?string $code): ?string
{
    $code = strtoupper(trim((string) $code));
    if ($code === '') {
        return null;
    }
    if (!preg_match('/^[A-Z0-9_-]{3,32}$/', $code)) {
        return null;
    }

    return $code;
}

function agent_ref_ensure_session(): void
{
    if (session_status() === PHP_SESSION_NONE) {
        @session_start();
    }
}

function agent_ref_visitor_key(): string
{
    agent_ref_ensure_session();
    $fromCookie = isset($_COOKIE[YHOME_VISITOR_COOKIE])
        ? preg_replace('/[^a-zA-Z0-9_-]/', '', (string) $_COOKIE[YHOME_VISITOR_COOKIE])
        : '';
    if (is_string($fromCookie) && strlen($fromCookie) >= 16) {
        return substr($fromCookie, 0, 64);
    }
    try {
        $key = bin2hex(random_bytes(16));
    } catch (Throwable $e) {
        $key = sha1(uniqid((string) mt_rand(), true));
    }
    $secure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off');
    setcookie(YHOME_VISITOR_COOKIE, $key, [
        'expires' => time() + (YHOME_REF_COOKIE_DAYS * 86400),
        'path' => '/',
        'secure' => $secure,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    $_COOKIE[YHOME_VISITOR_COOKIE] = $key;

    return $key;
}

function agent_ref_remember(?string $code): ?string
{
    $code = agent_ref_normalize($code);
    if ($code === null) {
        return agent_ref_current();
    }
    agent_ref_ensure_session();
    $_SESSION['yhome_ref'] = $code;
    $secure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off');
    setcookie(YHOME_REF_COOKIE, $code, [
        'expires' => time() + (YHOME_REF_COOKIE_DAYS * 86400),
        'path' => '/',
        'secure' => $secure,
        'httponly' => false,
        'samesite' => 'Lax',
    ]);
    $_COOKIE[YHOME_REF_COOKIE] = $code;

    return $code;
}

function agent_ref_current(): ?string
{
    agent_ref_ensure_session();
    if (!empty($_SESSION['yhome_ref'])) {
        $fromSession = agent_ref_normalize((string) $_SESSION['yhome_ref']);
        if ($fromSession !== null) {
            return $fromSession;
        }
    }
    if (!empty($_COOKIE[YHOME_REF_COOKIE])) {
        return agent_ref_normalize((string) $_COOKIE[YHOME_REF_COOKIE]);
    }

    return null;
}

/**
 * @return array{id:int,referral_code:string,name:string}|null
 */
function agent_ref_find_agent(PDO $pdo, string $code): ?array
{
    $code = agent_ref_normalize($code);
    if ($code === null) {
        return null;
    }
    $stmt = $pdo->prepare(
        'SELECT id, referral_code, name
         FROM agents
         WHERE referral_code = ? AND is_active = 1
         LIMIT 1'
    );
    $stmt->execute([$code]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        return null;
    }

    return [
        'id' => (int) $row['id'],
        'referral_code' => (string) $row['referral_code'],
        'name' => (string) ($row['name'] ?? ''),
    ];
}

/**
 * Record or refresh a property referral visit. Returns referral row id.
 */
function agent_ref_track_visit(PDO $pdo, string $code, int $listingId): ?int
{
    $agent = agent_ref_find_agent($pdo, $code);
    if ($agent === null || $listingId <= 0) {
        return null;
    }
    agent_ref_remember($agent['referral_code']);
    $visitor = agent_ref_visitor_key();
    agent_ref_ensure_session();
    $sessionId = session_id() !== '' ? substr(session_id(), 0, 128) : null;

    $find = $pdo->prepare(
        'SELECT id, status FROM agent_referrals
         WHERE agent_id = ? AND listing_id = ? AND visitor_key = ?
         LIMIT 1'
    );
    $find->execute([$agent['id'], $listingId, $visitor]);
    $existing = $find->fetch(PDO::FETCH_ASSOC);
    if ($existing) {
        $upd = $pdo->prepare(
            'UPDATE agent_referrals
             SET php_session_id = COALESCE(?, php_session_id), updated_at = CURRENT_TIMESTAMP
             WHERE id = ?'
        );
        $upd->execute([$sessionId, (int) $existing['id']]);

        return (int) $existing['id'];
    }

    $ins = $pdo->prepare(
        'INSERT INTO agent_referrals
            (agent_id, referral_code, listing_id, visitor_key, php_session_id, status, earnings_amount)
         VALUES
            (?, ?, ?, ?, ?, \'visited\', 0.00)'
    );
    $ins->execute([
        $agent['id'],
        $agent['referral_code'],
        $listingId,
        $visitor,
        $sessionId,
    ]);

    return (int) $pdo->lastInsertId();
}

/**
 * Attach a tour submission to the active referral (walkthrough + inquiry).
 *
 * @return array{referral_id:?int,referral_code:?string}
 */
function agent_ref_attach_tour(PDO $pdo, int $listingId, int $tourSubmissionId, ?string $codeHint = null): array
{
    $code = agent_ref_normalize($codeHint) ?? agent_ref_current();
    if ($code === null || $listingId <= 0 || $tourSubmissionId <= 0) {
        return ['referral_id' => null, 'referral_code' => null];
    }
    $agent = agent_ref_find_agent($pdo, $code);
    if ($agent === null) {
        return ['referral_id' => null, 'referral_code' => null];
    }

    agent_ref_remember($agent['referral_code']);
    $visitor = agent_ref_visitor_key();
    agent_ref_ensure_session();
    $sessionId = session_id() !== '' ? substr(session_id(), 0, 128) : null;

    $find = $pdo->prepare(
        'SELECT id, status FROM agent_referrals
         WHERE agent_id = ? AND listing_id = ? AND visitor_key = ?
         LIMIT 1'
    );
    $find->execute([$agent['id'], $listingId, $visitor]);
    $existing = $find->fetch(PDO::FETCH_ASSOC);

    if ($existing) {
        $referralId = (int) $existing['id'];
        $upd = $pdo->prepare(
            'UPDATE agent_referrals
             SET status = CASE
                    WHEN status IN (\'visited\', \'walkthrough_uploaded\') THEN \'renovation_inquiry\'
                    ELSE status
                 END,
                 tour_submission_id = ?,
                 php_session_id = COALESCE(?, php_session_id),
                 updated_at = CURRENT_TIMESTAMP
             WHERE id = ?'
        );
        $upd->execute([$tourSubmissionId, $sessionId, $referralId]);
    } else {
        $ins = $pdo->prepare(
            'INSERT INTO agent_referrals
                (agent_id, referral_code, listing_id, visitor_key, php_session_id, status, earnings_amount, tour_submission_id)
             VALUES
                (?, ?, ?, ?, ?, \'renovation_inquiry\', 0.00, ?)'
        );
        $ins->execute([
            $agent['id'],
            $agent['referral_code'],
            $listingId,
            $visitor,
            $sessionId,
            $tourSubmissionId,
        ]);
        $referralId = (int) $pdo->lastInsertId();
    }

    return [
        'referral_id' => $referralId,
        'referral_code' => $agent['referral_code'],
    ];
}

function agent_ref_public_base_url(): string
{
    $path = dirname(__DIR__) . '/config/site.php';
    $cfg = is_readable($path) ? (require $path) : [];
    $base = is_array($cfg) ? trim((string) ($cfg['public_base_url'] ?? 'https://yhome.ai')) : 'https://yhome.ai';

    return rtrim($base, '/');
}

function agent_ref_status_label(string $status): string
{
    return match ($status) {
        'visited' => 'Visited',
        'walkthrough_uploaded' => 'Walkthrough Uploaded',
        'renovation_inquiry' => 'Renovation Inquiry',
        'qualified' => 'Qualified',
        'paid' => 'Paid',
        default => ucfirst(str_replace('_', ' ', $status)),
    };
}

function agent_ref_activity_label(string $status): string
{
    return match ($status) {
        'visited' => 'Opened property page',
        'walkthrough_uploaded' => 'Uploaded walkthrough',
        'renovation_inquiry' => 'Requested renovation estimate',
        'qualified' => 'Qualified referral',
        'paid' => 'Reward paid',
        default => 'Activity',
    };
}
