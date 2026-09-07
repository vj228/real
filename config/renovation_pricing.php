<?php

declare(strict_types=1);

/**
 * Renovation work-code pricing.
 * Primary source: MySQL table `renovation_pricing`.
 * Fallback: built-in defaults if DB is unavailable.
 */

/** @return array<string, array{low:int,high:int,title:string}> */
function renovation_pricing_defaults(): array
{
    return [
        'paint_room' => ['low' => 800, 'high' => 2500, 'title' => 'Paint room'],
        'drywall_repair' => ['low' => 400, 'high' => 1800, 'title' => 'Drywall repair'],
        'floor_refinish' => ['low' => 1500, 'high' => 4500, 'title' => 'Refinish floors'],
        'floor_replace' => ['low' => 3500, 'high' => 9000, 'title' => 'Replace flooring'],
        'baseboard_replace' => ['low' => 400, 'high' => 1500, 'title' => 'Replace baseboards'],
        'countertop_replace' => ['low' => 2500, 'high' => 6000, 'title' => 'Replace countertop'],
        'cabinet_refinish' => ['low' => 2000, 'high' => 5000, 'title' => 'Refinish cabinets'],
        'cabinet_replace' => ['low' => 8000, 'high' => 18000, 'title' => 'Replace cabinets'],
        'backsplash_replace' => ['low' => 800, 'high' => 2500, 'title' => 'Replace backsplash'],
        'sink_replace' => ['low' => 400, 'high' => 1200, 'title' => 'Replace sink'],
        'faucet_replace' => ['low' => 250, 'high' => 800, 'title' => 'Replace faucet'],
        'range_hood_replace' => ['low' => 600, 'high' => 2200, 'title' => 'Replace range hood'],
        'bath_vanity_replace' => ['low' => 1200, 'high' => 3500, 'title' => 'Replace vanity'],
        'bath_countertop_replace' => ['low' => 800, 'high' => 2500, 'title' => 'Replace bath countertop'],
        'toilet_replace' => ['low' => 350, 'high' => 900, 'title' => 'Replace toilet'],
        'shower_update' => ['low' => 2500, 'high' => 8000, 'title' => 'Update shower'],
        'tub_replace' => ['low' => 2000, 'high' => 6500, 'title' => 'Replace tub'],
        'bath_tile_replace' => ['low' => 2000, 'high' => 7000, 'title' => 'Replace bath tile'],
        'light_fixture_replace' => ['low' => 200, 'high' => 900, 'title' => 'Replace light fixtures'],
        'door_replace' => ['low' => 400, 'high' => 1500, 'title' => 'Replace door'],
        'closet_update' => ['low' => 800, 'high' => 3000, 'title' => 'Update closet'],
    ];
}

/**
 * @return array<string, array{low:int,high:int,title:string}>
 */
function renovation_pricing_table(bool $forceReload = false): array
{
    static $cache = null;
    if ($forceReload) {
        $cache = null;
    }
    if (is_array($cache)) {
        return $cache;
    }

    $fromDb = renovation_pricing_load_from_db();
    $cache = $fromDb !== [] ? $fromDb : renovation_pricing_defaults();

    return $cache;
}

/**
 * @return array<string, array{low:int,high:int,title:string}>
 */
function renovation_pricing_load_from_db(): array
{
    $connectPath = dirname(__DIR__) . '/pdo_connect.php';
    if (!is_readable($connectPath)) {
        return [];
    }
    require_once $connectPath;
    if (!function_exists('db_pdo_connect')) {
        return [];
    }

    try {
        $pdo = db_pdo_connect();
        if (!$pdo instanceof PDO) {
            return [];
        }
        $stmt = $pdo->query(
            'SELECT code, title, estimate_low, estimate_high
             FROM renovation_pricing
             WHERE is_active = 1
             ORDER BY sort_order ASC, id ASC'
        );
        if ($stmt === false) {
            return [];
        }
        $out = [];
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $code = strtolower(trim((string) ($row['code'] ?? '')));
            if ($code === '') {
                continue;
            }
            $low = (int) ($row['estimate_low'] ?? 0);
            $high = (int) ($row['estimate_high'] ?? 0);
            if ($high < $low) {
                $high = $low;
            }
            $out[$code] = [
                'low' => max(0, $low),
                'high' => max(0, $high),
                'title' => trim((string) ($row['title'] ?? $code)),
            ];
        }

        return $out;
    } catch (Throwable $e) {
        return [];
    }
}

/** Clear request-local cache (e.g. after admin price edits). */
function renovation_pricing_clear_cache(): void
{
    renovation_pricing_table(true);
}

/** @return list<string> */
function renovation_allowed_codes(): array
{
    return array_keys(renovation_pricing_table());
}

/**
 * @return array{low:int,high:int,title:string}|null
 */
function renovation_price_for_code(string $code): ?array
{
    $table = renovation_pricing_table();
    $code = strtolower(trim($code));

    return $table[$code] ?? null;
}

function renovation_score_label(int $score): string
{
    if ($score >= 90) {
        return 'Excellent';
    }
    if ($score >= 80) {
        return 'Very Good';
    }
    if ($score >= 70) {
        return 'Good';
    }
    if ($score >= 60) {
        return 'Fair';
    }
    if ($score >= 40) {
        return 'Poor';
    }
    if ($score >= 20) {
        return 'Very Poor';
    }

    return 'Severe';
}
