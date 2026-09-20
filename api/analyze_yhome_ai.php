<?php

declare(strict_types=1);

/**
 * Analyze a yHome AI job with Gemini: kitchen/living/bathroom/bedroom → estimate.
 * POST JSON: { "id": "RqpTrFto_LA_..." }
 */

header('Content-Type: application/json; charset=utf-8');
set_time_limit(0);
ini_set('display_errors', '0');

const YAI_ROOT = __DIR__ . '/../yhome_ai';
/**
 * Soft ceiling only — we send every locally verified house-interior frame
 * (downscaled). Typical tours are well under this after exterior filtering.
 */
const YAI_MAX_IMAGES = 10;
const YAI_SEND_MAX_WIDTH = 640;
const YAI_SEND_JPEG_QUALITY = 72;
const YAI_ROOMS = ['kitchen', 'living', 'bathroom', 'bedroom'];
const YAI_OUTDOOR_REJECT = 0.35;
const YAI_PRIORITIES = ['required', 'recommended', 'optional'];
/** Near-duplicate Hamming distance for aHash — keep distinct slides (strict). */
const YAI_DEDUPE_MAX_DISTANCE = 2;
const YAI_SUMMARY_MAX_CHARS = 160;
const YAI_OBS_MAX_CHARS = 80;
const YAI_REASON_MAX_CHARS = 80;

require_once dirname(__DIR__) . '/config/renovation_pricing.php';
require_once dirname(__DIR__) . '/config/job_frames_sync.php';

function yai_out(array $payload, int $code = 200): void
{
    http_response_code($code);
    echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

function yai_fail(string $msg, int $code = 400, array $extra = []): void
{
    yai_out(array_merge(['ok' => false, 'error' => $msg], $extra), $code);
}

/** @return array{im:\GdImage|false,w:int,h:int}|null */
function yai_load_small(string $path, int $tw = 120): ?array
{
    $im = @imagecreatefromjpeg($path);
    if ($im === false) {
        return null;
    }
    $w = imagesx($im);
    $h = imagesy($im);
    if ($w < 3 || $h < 3) {
        return null;
    }
    $th = max(3, (int) round($h * $tw / $w));
    $small = imagecreatetruecolor($tw, $th);
    imagecopyresampled($small, $im, 0, 0, 0, 0, $tw, $th, $w, $h);

    return ['im' => $small, 'w' => $tw, 'h' => $th];
}

function yai_gray(int $c): int
{
    return (int) (0.299 * (($c >> 16) & 0xFF) + 0.587 * (($c >> 8) & 0xFF) + 0.114 * ($c & 0xFF));
}

/** Laplacian variance — higher = sharper. */
function yai_sharpness(string $path): float
{
    $loaded = yai_load_small($path, 120);
    if ($loaded === null) {
        return 0.0;
    }
    $small = $loaded['im'];
    $tw = $loaded['w'];
    $th = $loaded['h'];
    $sum = 0.0;
    $sum2 = 0.0;
    $n = 0;
    for ($y = 1; $y < $th - 1; $y++) {
        for ($x = 1; $x < $tw - 1; $x++) {
            $g = yai_gray(imagecolorat($small, $x, $y));
            $lap = (float) (
                4 * $g
                - yai_gray(imagecolorat($small, $x - 1, $y))
                - yai_gray(imagecolorat($small, $x + 1, $y))
                - yai_gray(imagecolorat($small, $x, $y - 1))
                - yai_gray(imagecolorat($small, $x, $y + 1))
            );
            $sum += $lap;
            $sum2 += $lap * $lap;
            $n++;
        }
    }
    if ($n === 0) {
        return 0.0;
    }
    $mean = $sum / $n;

    return ($sum2 / $n) - ($mean * $mean);
}

/**
 * 64-bit average hash (aHash) as a binary string of length 64.
 * Near-identical frames (slideshow holds, re-exports) collide within a small Hamming distance.
 */
function yai_perceptual_hash(string $path): ?string
{
    $im = @imagecreatefromjpeg($path);
    if ($im === false) {
        return null;
    }
    $w = imagesx($im);
    $h = imagesy($im);
    if ($w < 8 || $h < 8) {
        return null;
    }
    $tiny = imagecreatetruecolor(8, 8);
    imagecopyresampled($tiny, $im, 0, 0, 0, 0, 8, 8, $w, $h);
    $sum = 0;
    $vals = [];
    for ($y = 0; $y < 8; $y++) {
        for ($x = 0; $x < 8; $x++) {
            $g = yai_gray(imagecolorat($tiny, $x, $y));
            $vals[] = $g;
            $sum += $g;
        }
    }
    $avg = $sum / 64.0;
    $bits = '';
    foreach ($vals as $g) {
        $bits .= $g >= $avg ? '1' : '0';
    }

    return $bits;
}

function yai_hash_hamming(string $a, string $b): int
{
    $n = min(strlen($a), strlen($b));
    if ($n === 0) {
        return PHP_INT_MAX;
    }
    $d = abs(strlen($a) - strlen($b));
    for ($i = 0; $i < $n; $i++) {
        if ($a[$i] !== $b[$i]) {
            $d++;
        }
    }

    return $d;
}

/**
 * Drop near-duplicate house frames; keep the sharper of each similar cluster.
 *
 * @param list<array<string,mixed>> $house
 * @return array{kept:list<array<string,mixed>>,removed:int,log:list<string>}
 */
function yai_dedupe_house_frames(array $house, int $maxDistance = YAI_DEDUPE_MAX_DISTANCE): array
{
    $log = [];
    if (count($house) <= 1) {
        return ['kept' => $house, 'removed' => 0, 'log' => $log];
    }

    $enriched = [];
    foreach ($house as $row) {
        $path = (string) ($row['path'] ?? '');
        $hash = $path !== '' ? yai_perceptual_hash($path) : null;
        $md5 = ($path !== '' && is_readable($path)) ? (string) @md5_file($path) : '';
        $sharp = isset($row['sharp']) ? (float) $row['sharp'] : ($path !== '' ? yai_sharpness($path) : 0.0);
        $row['phash'] = $hash;
        $row['md5'] = $md5;
        $row['sharp'] = $sharp;
        $enriched[] = $row;
    }

    $kept = [];
    $removedTimes = [];
    foreach ($enriched as $row) {
        $dupOf = null;
        foreach ($kept as $ki => $prev) {
            $sameFile = $row['md5'] !== '' && $prev['md5'] !== '' && $row['md5'] === $prev['md5'];
            $near = $row['phash'] !== null && $prev['phash'] !== null
                && yai_hash_hamming((string) $row['phash'], (string) $prev['phash']) <= $maxDistance;
            if (!$sameFile && !$near) {
                continue;
            }
            $dupOf = $ki;
            break;
        }
        if ($dupOf === null) {
            $kept[] = $row;
            continue;
        }
        // Keep sharper (or earlier if tied)
        if ((float) $row['sharp'] > (float) $kept[$dupOf]['sharp']) {
            $removedTimes[] = (int) ($kept[$dupOf]['time_sec'] ?? 0);
            $kept[$dupOf] = $row;
        } else {
            $removedTimes[] = (int) ($row['time_sec'] ?? 0);
        }
    }

    $removed = count($removedTimes);
    if ($removed > 0) {
        sort($removedTimes);
        $log[] = 'Removed ' . $removed . ' duplicate/near-duplicate frame(s)'
            . ' (times: ' . implode(', ', array_map(static fn ($t) => $t . 's', $removedTimes)) . ').';
    } else {
        $log[] = 'No duplicate frames detected.';
    }

    // Strip helper keys before return
    $out = [];
    foreach ($kept as $row) {
        unset($row['phash'], $row['md5']);
        $out[] = $row;
    }

    return ['kept' => $out, 'removed' => $removed, 'log' => $log];
}

/**
 * Heuristic outdoor likelihood 0..1 (sky / grass-heavy frames score higher).
 * Used to prefer indoor kitchen/living/bath/bed frames for Gemini.
 */
function yai_outdoor_likelihood(string $path): float
{
    $loaded = yai_load_small($path, 96);
    if ($loaded === null) {
        return 0.5;
    }
    $im = $loaded['im'];
    $w = $loaded['w'];
    $h = $loaded['h'];
    $topEnd = max(1, (int) floor($h * 0.38));
    $sky = 0;
    $topN = 0;
    $green = 0;
    $bright = 0;
    $n = 0;
    for ($y = 0; $y < $h; $y++) {
        for ($x = 0; $x < $w; $x++) {
            $c = imagecolorat($im, $x, $y);
            $r = ($c >> 16) & 0xFF;
            $g = ($c >> 8) & 0xFF;
            $b = $c & 0xFF;
            $lum = (0.299 * $r + 0.587 * $g + 0.114 * $b) / 255.0;
            $n++;
            if ($lum > 0.72) {
                $bright++;
            }
            if ($y < $topEnd) {
                $topN++;
                // sky-like: blue-dominant and fairly bright
                if ($b > $r + 12 && $b > $g + 4 && $lum > 0.35) {
                    $sky++;
                }
            }
            // grass / foliage
            if ($g > $r + 18 && $g > $b + 12 && $lum > 0.2 && $lum < 0.85) {
                $green++;
            }
        }
    }
    if ($n === 0) {
        return 0.5;
    }
    $skyFrac = $topN > 0 ? $sky / $topN : 0.0;
    $greenFrac = $green / $n;
    $brightFrac = $bright / $n;
    $score = 0.55 * $skyFrac + 0.45 * $greenFrac + 0.28 * max(0.0, $brightFrac - 0.4);

    return max(0.0, min(1.0, $score));
}

/**
 * Detect floor plans / diagrams / maps (B&W line drawings) — not room photos.
 */
function yai_is_floor_plan(string $path): bool
{
    $loaded = yai_load_small($path, 96);
    if ($loaded === null) {
        return false;
    }
    $im = $loaded['im'];
    $w = $loaded['w'];
    $h = $loaded['h'];
    $n = 0;
    $satSum = 0.0;
    $white = 0;
    $nearBlack = 0;
    for ($y = 0; $y < $h; $y++) {
        for ($x = 0; $x < $w; $x++) {
            $c = imagecolorat($im, $x, $y);
            $r = ($c >> 16) & 0xFF;
            $g = ($c >> 8) & 0xFF;
            $b = $c & 0xFF;
            $lum = (0.299 * $r + 0.587 * $g + 0.114 * $b) / 255.0;
            $mx = max($r, $g, $b);
            $mn = min($r, $g, $b);
            $sat = $mx > 0 ? ($mx - $mn) / $mx : 0.0;
            $n++;
            $satSum += $sat;
            if ($lum > 0.88) {
                $white++;
            }
            if ($lum < 0.22) {
                $nearBlack++;
            }
        }
    }
    if ($n === 0) {
        return false;
    }
    $satAvg = $satSum / $n;
    $whiteF = $white / $n;
    $blackF = $nearBlack / $n;

    // Floor plans: grayscale paper + ink (no photographic color).
    return $satAvg < 0.05 && $whiteF >= 0.45 && $blackF >= 0.08;
}

/**
 * Kitchen likelihood 0..1 — requires cooktop/hood evidence (not steel walls/faucets).
 */
function yai_kitchen_cue(string $path): float
{
    $loaded = yai_load_small($path, 120);
    if ($loaded === null) {
        return 0.0;
    }
    $im = $loaded['im'];
    $w = $loaded['w'];
    $h = $loaded['h'];

    $hoodDark = 0;
    $hoodN = 0;
    $cookDark = 0;
    $cookN = 0;
    $knobLike = 0;
    $midN = 0;

    for ($y = 0; $y < $h; $y++) {
        for ($x = 0; $x < $w; $x++) {
            $c = imagecolorat($im, $x, $y);
            $r = ($c >> 16) & 0xFF;
            $g = ($c >> 8) & 0xFF;
            $b = $c & 0xFF;
            $lum = (0.299 * $r + 0.587 * $g + 0.114 * $b) / 255.0;
            $neutral = abs($r - $g) < 20 && abs($g - $b) < 20;

            // Dark angled hood / cooktop zone (upper-mid, centered)
            if ($y > $h * 0.08 && $y < $h * 0.45 && $x > $w * 0.3 && $x < $w * 0.8) {
                $hoodN++;
                if ($neutral && $lum < 0.22) {
                    $hoodDark++;
                }
            }
            // Cooktop slab — dark horizontal band mid-frame
            if ($y > $h * 0.35 && $y < $h * 0.65 && $x > $w * 0.25 && $x < $w * 0.85) {
                $cookN++;
                if ($neutral && $lum < 0.22) {
                    $cookDark++;
                }
                $midN++;
                // Gas grate / control knobs: tiny dark dots on lighter counter
                if ($lum < 0.25 && $neutral) {
                    $knobLike++;
                }
            }
        }
    }

    $hood = $hoodN > 0 ? $hoodDark / $hoodN : 0.0;
    $cook = $cookN > 0 ? $cookDark / $cookN : 0.0;
    $knobs = $midN > 0 ? $knobLike / $midN : 0.0;

    // Real kitchens usually show a dark cooktop or hood; without that, stay low.
    $score = max($cook * 5.5, $hood * 4.5, $knobs * 2.2);
    if ($cook < 0.03 && $hood < 0.03) {
        $score *= 0.25;
    }

    return max(0.0, min(1.0, $score));
}

/**
 * Local color/structure cues for bathroom / bedroom / living (rough 0..1).
 *
 * @return array{bathroom:float,bedroom:float,living:float,interior:float}
 */
function yai_room_cues(string $path): array
{
    $loaded = yai_load_small($path, 96);
    if ($loaded === null) {
        return ['bathroom' => 0.0, 'bedroom' => 0.0, 'living' => 0.0, 'interior' => 0.0];
    }
    $im = $loaded['im'];
    $w = $loaded['w'];
    $h = $loaded['h'];
    $white = 0;
    $warmSoft = 0;
    $neutralWall = 0;
    $n = 0;
    $midBand = 0;
    $midDark = 0;
    $tileWhiteLow = 0;
    $lowN = 0;
    $glassEdge = 0;
    $glassN = 0;
    $prevLum = null;
    $furnMass = 0;
    $furnN = 0;
    $floorWood = 0;
    $floorN = 0;
    for ($y = 0; $y < $h; $y++) {
        for ($x = 0; $x < $w; $x++) {
            $c = imagecolorat($im, $x, $y);
            $r = ($c >> 16) & 0xFF;
            $g = ($c >> 8) & 0xFF;
            $b = $c & 0xFF;
            $lum = (0.299 * $r + 0.587 * $g + 0.114 * $b) / 255.0;
            $n++;
            if ($lum > 0.82 && abs($r - $g) < 22 && abs($g - $b) < 22) {
                $white++;
            }
            if ($lum > 0.55 && $lum < 0.88 && abs($r - $g) < 25 && abs($g - $b) < 25 && $b < 230) {
                $neutralWall++;
            }
            if ($lum > 0.35 && $lum < 0.75 && $r >= $g && $g >= $b - 10) {
                $warmSoft++;
            }
            if ($y > $h * 0.45 && $y < $h * 0.75) {
                $midBand++;
                if ($lum < 0.28) {
                    $midDark++;
                }
            }
            if ($y > $h * 0.5) {
                $lowN++;
                if ($lum > 0.75 && abs($r - $g) < 20 && abs($g - $b) < 20) {
                    $tileWhiteLow++;
                }
            }
            // Shower glass / framed glass: high local contrast on sides
            if ($x < $w * 0.28 || $x > $w * 0.72) {
                $glassN++;
                if ($prevLum !== null && abs($lum - $prevLum) > 0.35) {
                    $glassEdge++;
                }
            }
            if ($y > $h * 0.48 && $y < $h * 0.85) {
                $furnN++;
                // Soft sofa/cushion tones occupying lower half
                if ($lum > 0.5 && $lum < 0.95 && abs($r - $g) < 28 && abs($g - $b) < 28) {
                    $furnMass++;
                }
            }
            if ($y > $h * 0.72) {
                $floorN++;
                if ($r > $g + 6 && $g > $b && $lum > 0.3 && $lum < 0.8) {
                    $floorWood++;
                }
            }
            $prevLum = $lum;
        }
        $prevLum = null;
    }
    if ($n === 0) {
        return ['bathroom' => 0.0, 'bedroom' => 0.0, 'living' => 0.0, 'interior' => 0.0];
    }
    $whiteF = $white / $n;
    $wallF = $neutralWall / $n;
    $warmF = $warmSoft / $n;
    $bedBand = $midBand > 0 ? $midDark / $midBand : 0.0;
    $tileLow = $lowN > 0 ? $tileWhiteLow / $lowN : 0.0;
    $glassF = $glassN > 0 ? $glassEdge / $glassN : 0.0;
    $furnF = $furnN > 0 ? $furnMass / $furnN : 0.0;
    $floorF = $floorN > 0 ? $floorWood / $floorN : 0.0;

    $bathroom = max(0.0, min(1.0, 1.8 * $tileLow + 0.9 * $glassF + 0.4 * $whiteF));
    // Without lower-frame tile, glass/door frames alone must not look like a bath.
    if ($tileLow < 0.12) {
        $bathroom *= 0.35;
    }
    $bedroom = max(0.0, min(1.0, 1.6 * $bedBand + 0.8 * $warmF));
    $living = max(0.0, min(1.0, 0.85 * $furnF + 0.5 * $wallF + 0.4 * $floorF));
    $interior = max(0.0, min(1.0, 0.9 * $wallF + 0.5 * $warmF + 0.35 * $whiteF + 0.4 * $bedBand));

    return [
        'bathroom' => $bathroom,
        'bedroom' => $bedroom,
        'living' => $living,
        'interior' => $interior,
    ];
}

/**
 * Local house-related verdict for one frame.
 *
 * @return array{
 *   house:bool,
 *   guess:string,
 *   outdoor:float,
 *   kitchen:float,
 *   sharp:float,
 *   reason:string
 * }
 */
function yai_classify_house_frame(string $path, int $index, int $total): array
{
    $sharp = yai_sharpness($path);
    $outdoor = yai_outdoor_likelihood($path);
    $kitchen = yai_kitchen_cue($path);
    $cues = yai_room_cues($path);
    $edge = ($total > 0 && ($index < $total * 0.08 || $index > $total * 0.9));

    if ($sharp < 40) {
        return [
            'house' => false,
            'guess' => 'skip',
            'outdoor' => $outdoor,
            'kitchen' => $kitchen,
            'sharp' => $sharp,
            'reason' => 'blank/black frame',
        ];
    }
    if (yai_is_floor_plan($path)) {
        return [
            'house' => false,
            'guess' => 'diagram',
            'outdoor' => $outdoor,
            'kitchen' => $kitchen,
            'sharp' => $sharp,
            'reason' => 'floor plan / diagram',
        ];
    }
    if ($outdoor >= YAI_OUTDOOR_REJECT) {
        return [
            'house' => false,
            'guess' => 'exterior',
            'outdoor' => $outdoor,
            'kitchen' => $kitchen,
            'sharp' => $sharp,
            'reason' => 'likely exterior (sky/grass)',
        ];
    }
    // Curb / aerial / yard shots with weak kitchen/bath cues
    if ($outdoor >= 0.22 && $kitchen < 0.28 && $cues['bathroom'] < 0.28 && $cues['interior'] < 0.35) {
        return [
            'house' => false,
            'guess' => 'exterior',
            'outdoor' => $outdoor,
            'kitchen' => $kitchen,
            'sharp' => $sharp,
            'reason' => 'exterior / yard / aerial',
        ];
    }
    // Early/late curb-appeal shots that still look semi-indoor
    if ($edge && $outdoor >= 0.22 && $kitchen < 0.35 && $cues['interior'] < 0.28) {
        return [
            'house' => false,
            'guess' => 'exterior',
            'outdoor' => $outdoor,
            'kitchen' => $kitchen,
            'sharp' => $sharp,
            'reason' => 'tour edge, weak interior cues',
        ];
    }

    $scores = [
        'kitchen' => $kitchen,
        'bathroom' => $cues['bathroom'],
        'bedroom' => $cues['bedroom'],
        'living' => $cues['living'],
    ];
    // Strong cooktop/hood → kitchen wins over living/bedroom furniture cues.
    if ($kitchen >= 0.28) {
        $scores['kitchen'] = max($scores['kitchen'], min(1.0, $kitchen + 0.35));
        $scores['living'] *= 0.4;
        $scores['bedroom'] *= 0.4;
    } elseif ($scores['kitchen'] < $scores['living'] + 0.1) {
        $scores['kitchen'] *= 0.4;
    }
    // Bathroom tile/glass should beat kitchen cabinets when cooktop is absent.
    if ($cues['bathroom'] >= 0.5 && $kitchen < 0.28) {
        $scores['bathroom'] = max($scores['bathroom'], min(1.0, $cues['bathroom'] + 0.35));
        $scores['kitchen'] *= 0.35;
        $scores['living'] *= 0.45;
        $scores['bedroom'] *= 0.45;
    }
    arsort($scores);
    $guess = (string) array_key_first($scores);
    $best = (float) ($scores[$guess] ?? 0.0);
    $second = (float) (array_values($scores)[1] ?? 0.0);
    // Soft/blurry interiors still count as house (kitchen walk-ins)
    $house = $outdoor < YAI_OUTDOOR_REJECT && (
        $cues['interior'] >= 0.18
        || $kitchen >= 0.3
        || $best >= 0.28
        || ($sharp < 1600 && $outdoor < 0.25)
    );
    if (!$house) {
        return [
            'house' => false,
            'guess' => 'skip',
            'outdoor' => $outdoor,
            'kitchen' => $kitchen,
            'sharp' => $sharp,
            'reason' => 'weak house/interior signal',
        ];
    }
    if ($best < 0.22 && $kitchen < 0.3) {
        $guess = 'interior';
    } elseif ($best - $second < 0.05 && in_array($guess, ['kitchen', 'bathroom'], true) && $scores['living'] >= 0.4) {
        $guess = 'living';
    }

    return [
        'house' => true,
        'guess' => $guess,
        'outdoor' => $outdoor,
        'kitchen' => $kitchen,
        'sharp' => $sharp,
        'reason' => 'house interior',
    ];
}

/** Downscale JPEG bytes for faster Gemini uploads. */
function yai_jpeg_for_gemini(string $path): ?string
{
    $im = @imagecreatefromjpeg($path);
    if ($im === false) {
        $bin = file_get_contents($path);

        return $bin === false ? null : $bin;
    }
    $w = imagesx($im);
    $h = imagesy($im);
    $maxW = YAI_SEND_MAX_WIDTH;
    if ($w > $maxW) {
        $nw = $maxW;
        $nh = max(1, (int) round($h * $maxW / $w));
        $small = imagecreatetruecolor($nw, $nh);
        imagecopyresampled($small, $im, 0, 0, 0, 0, $nw, $nh, $w, $h);
        $im = $small;
    }
    ob_start();
    imagejpeg($im, null, YAI_SEND_JPEG_QUALITY);
    $bin = ob_get_clean();

    return is_string($bin) && $bin !== '' ? $bin : null;
}

/**
 * Locally identify every house-related frame, then send all of them (downscaled).
 *
 * @param list<string> $items
 * @return array{
 *   picked:list<string>,
 *   guesses:list<string>,
 *   log:list<string>,
 *   stats:array<string,mixed>
 * }
 */
function yai_pick_for_analysis(array $items, int $want): array
{
    $n = count($items);
    $log = [];
    if ($n === 0) {
        return ['picked' => [], 'guesses' => [], 'log' => ['No frames available to score.'], 'stats' => []];
    }

    $house = [];
    $rejected = [];
    $guessCounts = [];
    foreach ($items as $i => $path) {
        $base = basename($path);
        $timeSec = preg_match('/^t(\d+)\.jpg$/', $base, $m) ? (int) $m[1] : $i;
        $c = yai_classify_house_frame($path, $i, $n);
        $row = [
            'i' => $i,
            'path' => $path,
            'time_sec' => $timeSec,
            'guess' => $c['guess'],
            'outdoor' => $c['outdoor'],
            'kitchen' => $c['kitchen'],
            'sharp' => $c['sharp'],
            'reason' => $c['reason'],
        ];
        if ($c['house']) {
            $house[] = $row;
            $guessCounts[$c['guess']] = ($guessCounts[$c['guess']] ?? 0) + 1;
        } else {
            $rejected[] = $row;
        }
    }

    $log[] = 'Local pre-filter scanned ' . $n . ' frames for house-related interiors.';
    $log[] = 'Kept ' . count($house) . ' house frames; rejected ' . count($rejected)
        . ' (exterior / blank / weak interior).';
    // Extra outdoor sweep (catch curb/yard that slipped past classify)
    $outdoorSweep = [];
    $house2 = [];
    foreach ($house as $row) {
        if ((float) ($row['outdoor'] ?? 0) >= 0.15) {
            $outdoorSweep[] = $row;
            continue;
        }
        $house2[] = $row;
    }
    if ($outdoorSweep !== []) {
        $house = $house2;
        $rejTimes = array_map(static fn ($r) => $r['time_sec'] . 's', $outdoorSweep);
        $log[] = 'Outdoor sweep removed ' . count($outdoorSweep)
            . ' more frame(s): ' . implode(', ', $rejTimes) . '.';
        $rejected = array_merge($rejected, $outdoorSweep);
    }
    if ($guessCounts !== []) {
        ksort($guessCounts);
        $bits = [];
        foreach ($guessCounts as $g => $cnt) {
            $bits[] = $g . '=' . $cnt;
        }
        $log[] = 'Local room guesses: ' . implode(', ', $bits) . '.';
    }
    if ($rejected !== []) {
        $rejTimes = array_slice(array_map(static fn ($r) => $r['time_sec'] . 's', $rejected), 0, 12);
        $log[] = 'Rejected times: ' . implode(', ', $rejTimes)
            . (count($rejected) > 12 ? '…' : '') . '.';
    }

    $dedupe = yai_dedupe_house_frames($house);
    $house = $dedupe['kept'];
    $log = array_merge($log, $dedupe['log']);
    $guessCounts = [];
    foreach ($house as $row) {
        $g = (string) ($row['guess'] ?? 'interior');
        $guessCounts[$g] = ($guessCounts[$g] ?? 0) + 1;
    }

    // Prefer covering all guessed room types if we must trim under the soft ceiling
    $picked = $house;
    if (count($picked) > $want) {
        $log[] = 'House set (' . count($picked) . ') exceeds soft ceiling ' . $want
            . ' — keeping best coverage per local guess.';
        $byGuess = [];
        foreach ($picked as $row) {
            $g = $row['guess'] !== '' ? $row['guess'] : 'interior';
            $byGuess[$g][] = $row;
        }
        foreach ($byGuess as $g => $rows) {
            usort($rows, static function ($a, $b) use ($g) {
                if ($g === 'kitchen') {
                    return $b['kitchen'] <=> $a['kitchen'];
                }

                // Prefer a mix: keep softer walk-ins when tied-ish
                return $b['sharp'] <=> $a['sharp'];
            });
            $byGuess[$g] = $rows;
        }
        $selected = [];
        $selectedIdx = [];
        // Round-robin per room type first
        $types = array_keys($byGuess);
        $guard = 0;
        while (count($selected) < $want && $guard < $want * 4) {
            $guard++;
            $added = false;
            foreach ($types as $g) {
                if (count($selected) >= $want) {
                    break;
                }
                while ($byGuess[$g] !== []) {
                    $row = array_shift($byGuess[$g]);
                    if (isset($selectedIdx[$row['i']])) {
                        continue;
                    }
                    $selected[] = $row;
                    $selectedIdx[$row['i']] = true;
                    $added = true;
                    break;
                }
            }
            if (!$added) {
                break;
            }
        }
        $picked = $selected;
    }

    usort($picked, static fn ($a, $b) => $a['time_sec'] <=> $b['time_sec']);
    $timesPicked = array_map(static fn ($p) => $p['time_sec'], $picked);
    $guesses = array_map(static fn ($p) => $p['guess'], $picked);
    $log[] = 'Submitting ' . count($picked) . ' locally verified house frames to Gemini'
        . ' (times: ' . implode(', ', $timesPicked) . ').';

    return [
        'picked' => array_map(static fn ($p) => $p['path'], $picked),
        'guesses' => $guesses,
        'log' => $log,
        'stats' => [
            'candidates' => $n,
            'house_kept' => count($house) + (int) ($dedupe['removed'] ?? 0),
            'duplicates_removed' => (int) ($dedupe['removed'] ?? 0),
            'rejected' => count($rejected),
            'picked' => count($picked),
            'guess_counts' => $guessCounts,
            'times_sec' => $timesPicked,
            'local_guesses' => $guesses,
        ],
    ];
}

function yai_normalize_room(string $room): string
{
    $r = strtolower(trim($room));
    if (str_contains($r, 'kitchen')) {
        return 'kitchen';
    }
    if (str_contains($r, 'bath')) {
        return 'bathroom';
    }
    if (str_contains($r, 'bed')) {
        return 'bedroom';
    }
    if (str_contains($r, 'living') || str_contains($r, 'family') || str_contains($r, 'dining')) {
        return 'living';
    }

    return '';
}

function yai_normalize_condition(string $c): string
{
    $c = strtolower(trim($c));
    if (in_array($c, ['good', 'fair', 'poor'], true)) {
        return $c;
    }
    if (str_contains($c, 'poor') || str_contains($c, 'bad') || str_contains($c, 'dated') || str_contains($c, 'needs')) {
        return 'poor';
    }
    if (str_contains($c, 'fair') || str_contains($c, 'average') || str_contains($c, 'ok')) {
        return 'fair';
    }

    return 'good';
}

/**
 * Accept {"rooms":[...]}, a bare [...], truncated JSON, or fenced markdown.
 *
 * @return array{rooms:list<array<string,mixed>>}|null
 */
function yai_parse_rooms_json(string $text): ?array
{
    $text = trim($text);
    if ($text === '') {
        return null;
    }
    if (str_starts_with($text, '```')) {
        $text = preg_replace('/^```(?:json)?\s*/i', '', $text) ?? $text;
        $text = preg_replace('/\s*```$/', '', $text) ?? $text;
        $text = trim($text);
    }

    $normalize = static function (array $decoded): ?array {
        if (isset($decoded['rooms']) && is_array($decoded['rooms'])) {
            return ['rooms' => array_values(array_filter($decoded['rooms'], 'is_array'))];
        }
        if (array_is_list($decoded) && $decoded !== []) {
            $rooms = [];
            foreach ($decoded as $row) {
                if (is_array($row) && isset($row['room'])) {
                    $rooms[] = $row;
                }
            }
            if ($rooms !== []) {
                return ['rooms' => $rooms];
            }
        }

        return null;
    };

    $tryDecode = static function (string $s) use ($normalize): ?array {
        $decoded = json_decode($s, true);

        return is_array($decoded) ? $normalize($decoded) : null;
    };

    $parsed = $tryDecode($text);
    if ($parsed !== null) {
        return $parsed;
    }

    if (preg_match('/(\{[\s\S]*\}|\[[\s\S]*\])/', $text, $m)) {
        $parsed = $tryDecode($m[1]);
        if ($parsed !== null) {
            return $parsed;
        }
    }

    // Salvage complete room objects from truncated output (handles nested work arrays)
    $rooms = [];
    $len = strlen($text);
    for ($i = 0; $i < $len; $i++) {
        if ($text[$i] !== '{') {
            continue;
        }
        $depth = 0;
        $inStr = false;
        $esc = false;
        for ($j = $i; $j < $len; $j++) {
            $ch = $text[$j];
            if ($inStr) {
                if ($esc) {
                    $esc = false;
                } elseif ($ch === '\\') {
                    $esc = true;
                } elseif ($ch === '"') {
                    $inStr = false;
                }
                continue;
            }
            if ($ch === '"') {
                $inStr = true;
                continue;
            }
            if ($ch === '{') {
                $depth++;
            } elseif ($ch === '}') {
                $depth--;
                if ($depth === 0) {
                    $chunk = substr($text, $i, $j - $i + 1);
                    if (str_contains($chunk, '"room"')) {
                        $row = json_decode($chunk, true);
                        if (is_array($row) && isset($row['room']) && !isset($row['rooms'])) {
                            if (!isset($row['images']) || !is_array($row['images'])) {
                                $row['images'] = [];
                            }
                            $rooms[] = $row;
                        }
                    }
                    $i = $j;
                    break;
                }
            }
        }
    }
    if ($rooms !== []) {
        return ['rooms' => $rooms];
    }

    // Light repair then decode (incl. truncated mid-string summaries)
    $repair = $text;
    // If unfinished string at end, close it
    $inStr = false;
    $esc = false;
    $lenR = strlen($repair);
    for ($i = 0; $i < $lenR; $i++) {
        $ch = $repair[$i];
        if ($inStr) {
            if ($esc) {
                $esc = false;
            } elseif ($ch === '\\') {
                $esc = true;
            } elseif ($ch === '"') {
                $inStr = false;
            }
            continue;
        }
        if ($ch === '"') {
            $inStr = true;
        }
    }
    if ($inStr) {
        $repair .= '"';
    }
    $repair = preg_replace('/"images"\s*:\s*$/', '"images":[]', $repair) ?? $repair;
    $repair = preg_replace('/,\s*$/', '', rtrim($repair)) ?? $repair;
    $opens = substr_count($repair, '{') - substr_count($repair, '}');
    $opensArr = substr_count($repair, '[') - substr_count($repair, ']');
    if ($opensArr > 0) {
        $repair .= str_repeat(']', $opensArr);
    }
    if ($opens > 0) {
        $repair .= str_repeat('}', $opens);
    }
    $parsed = $tryDecode($repair);
    if ($parsed !== null) {
        return $parsed;
    }
    if (str_starts_with(ltrim($repair), '[')) {
        $parsed = $tryDecode('{"rooms":' . $repair . '}');
        if ($parsed !== null) {
            return $parsed;
        }
    }

    // Last resort: extract room + score (+ short summary) from truncated / looping output
    $loose = [];
    if (preg_match_all(
        '/"room"\s*:\s*"([^"]+)"\s*,\s*"condition_score"\s*:\s*(\d+)/',
        $text,
        $matches,
        PREG_SET_ORDER | PREG_OFFSET_CAPTURE
    )) {
        foreach ($matches as $m) {
            $roomName = yai_normalize_room($m[1][0]);
            if ($roomName === '') {
                continue;
            }
            $score = (int) $m[2][0];
            $pos = (int) $m[0][1];
            $chunk = substr($text, $pos, 2500);
            $summary = '';
            if (preg_match('/"summary"\s*:\s*"(.*)$/s', $chunk, $sm)) {
                $summary = $sm[1];
                // Cut at escape/loop — take first sentence-ish
                $summary = preg_replace('/\\\\"/', '', $summary) ?? $summary;
                if (preg_match('/^([^"]{10,180})/', $summary, $sm2)) {
                    $summary = $sm2[1];
                } else {
                    $summary = substr($summary, 0, 160);
                }
            }
            $imgs = [];
            if (preg_match('/"images"\s*:\s*\[([^\]]*)\]/', $chunk, $im)) {
                foreach (preg_split('/\s*,\s*/', trim($im[1])) as $n) {
                    if ($n !== '' && is_numeric($n)) {
                        $imgs[] = (int) $n;
                    }
                }
            }
            $conf = 0.75;
            if (preg_match('/"confidence"\s*:\s*([0-9.]+)/', $chunk, $cm)) {
                $conf = (float) $cm[1];
            }
            $loose[$roomName] = [
                'room' => $roomName,
                'condition_score' => $score,
                'confidence' => $conf,
                'summary' => $summary,
                'observations' => [],
                'recommended_work' => [],
                'images' => $imgs,
            ];
        }
    }
    if ($loose !== []) {
        return ['rooms' => array_values($loose)];
    }

    return null;
}

/**
 * Kitchen: $0 / "good" only when Gemini clearly signals near-perfect finish.
 * Typical updated builder kitchens (butcher block, standard appliances) → fair.
 *
 * @param list<array<string,mixed>> $rooms
 * @return list<array<string,mixed>>
 * @deprecated Replaced by condition_score + work-code pricing.
 */
function yai_refine_kitchen_conditions(array $rooms): array
{
    return $rooms;
}

function yai_normalize_priority(string $p): string
{
    $p = strtolower(trim($p));
    if (in_array($p, YAI_PRIORITIES, true)) {
        return $p;
    }
    if (str_contains($p, 'require') || str_contains($p, 'must') || str_contains($p, 'urgent')) {
        return 'required';
    }
    if (str_contains($p, 'option') || str_contains($p, 'cosmetic') || str_contains($p, 'style')) {
        return 'optional';
    }

    return 'recommended';
}

function yai_clamp_score(mixed $score): int
{
    if (!is_numeric($score)) {
        return 70;
    }

    return max(0, min(100, (int) round((float) $score)));
}

function yai_clamp_confidence(mixed $c): float
{
    if (!is_numeric($c)) {
        return 0.7;
    }

    return max(0.0, min(1.0, (float) $c));
}

/** Collapse runaway / repetitive Gemini prose into a short readable line. */
function yai_clamp_prose(string $text, int $maxChars): string
{
    $text = trim(preg_replace('/\s+/u', ' ', $text) ?? $text);
    if ($text === '') {
        return '';
    }
    // Detect looping filler ("solid strong spot solid strong spot…")
    $words = preg_split('/\s+/u', $text) ?: [];
    if (count($words) > 24) {
        $uniq = [];
        foreach ($words as $w) {
            $k = strtolower($w);
            $uniq[$k] = ($uniq[$k] ?? 0) + 1;
        }
        $top = max($uniq);
        if ($top >= 4 && $top / max(1, count($words)) >= 0.12) {
            $text = implode(' ', array_slice($words, 0, 20));
        }
    }
    if (mb_strlen($text) > $maxChars) {
        $cut = mb_substr($text, 0, $maxChars);
        $sp = mb_strrpos($cut, ' ');
        if ($sp !== false && $sp > (int) ($maxChars * 0.5)) {
            $cut = mb_substr($cut, 0, $sp);
        }
        $text = rtrim($cut, " \t.,;:") . '…';
    }

    return $text;
}

/**
 * Correct Gemini room buckets using strong local cues only.
 * Weak local guesses must not override Gemini (they used to force every sofa into kitchen).
 *
 * @param list<array<string,mixed>> $rooms
 * @param list<array<string,mixed>> $frames
 * @return list<array<string,mixed>>
 */
function yai_realign_rooms_by_local_guess(array $rooms, array $frames): array
{
    $guessOf = [];
    $kitchenCue = [];
    $bathCue = [];
    $liveCue = [];
    foreach ($frames as $i => $f) {
        $path = (string) ($f['path'] ?? '');
        if ($path !== '' && is_readable($path) && yai_is_floor_plan($path)) {
            $guessOf[(int) $i] = 'diagram';
            continue;
        }
        $g = yai_normalize_room((string) ($f['local_guess'] ?? ''));
        if ($g !== '') {
            $guessOf[(int) $i] = $g;
        }
        if ($path !== '' && is_readable($path)) {
            $cues = yai_room_cues($path);
            $kitchenCue[(int) $i] = yai_kitchen_cue($path);
            $bathCue[(int) $i] = $cues['bathroom'];
            $liveCue[(int) $i] = $cues['living'];
        }
    }

    $donors = [];
    foreach ($rooms as $row) {
        if (!is_array($row)) {
            continue;
        }
        $room = yai_normalize_room((string) ($row['room'] ?? ''));
        if ($room === '') {
            continue;
        }
        $donors[$room] = $row;
    }

    $buckets = [];
    foreach ($rooms as $row) {
        if (!is_array($row)) {
            continue;
        }
        $idxs = $row['images'] ?? $row['image_indexes'] ?? [];
        if (!is_array($idxs)) {
            $idxs = [];
        }
        $geminiRoom = yai_normalize_room((string) ($row['room'] ?? ''));
        foreach ($idxs as $i) {
            $i = (int) $i;
            $local = $guessOf[$i] ?? '';
            if ($local === 'diagram') {
                continue; // drop floor plans / maps
            }
            $kit = (float) ($kitchenCue[$i] ?? 0);
            $bath = (float) ($bathCue[$i] ?? 0);
            $live = (float) ($liveCue[$i] ?? 0);
            $target = $geminiRoom;
            // Strong local corrections only
            if ($kit >= 0.28) {
                $target = 'kitchen';
            } elseif ($bath >= 0.42 && $kit < 0.25 && $bath + 0.05 >= $live) {
                $target = 'bathroom';
            } elseif ($geminiRoom === 'bathroom' && $bath < 0.32 && $live > $bath + 0.25 && $kit < 0.25) {
                // Living/dining with white walls wrongly labeled as bath
                $target = 'living';
            } elseif ($local === 'living' && $geminiRoom === 'kitchen' && $kit < 0.25) {
                $target = 'living';
            } elseif ($local === 'bedroom' && $geminiRoom === 'kitchen' && $kit < 0.25) {
                $target = 'bedroom';
            } elseif ($local === 'bathroom' && $geminiRoom === 'kitchen' && $kit < 0.25 && $bath >= 0.4) {
                $target = 'bathroom';
            }
            if ($target === '' || !in_array($target, YAI_ROOMS, true)) {
                continue;
            }
            if (!isset($buckets[$target])) {
                $buckets[$target] = ['idxs' => [], 'from' => []];
            }
            $buckets[$target]['idxs'][$i] = true;
            if ($geminiRoom !== '') {
                $buckets[$target]['from'][$geminiRoom] = ($buckets[$target]['from'][$geminiRoom] ?? 0) + 1;
            }
        }
    }

    $out = [];
    foreach ($buckets as $room => $bag) {
        $idxs = array_map('intval', array_keys($bag['idxs']));
        sort($idxs);
        if ($idxs === []) {
            continue;
        }
        $donorKey = $room;
        if (!isset($donors[$donorKey]) && $bag['from'] !== []) {
            arsort($bag['from']);
            $donorKey = (string) array_key_first($bag['from']);
        }
        $donor = $donors[$donorKey] ?? ($donors[$room] ?? null);
        if (!is_array($donor)) {
            $out[] = [
                'room' => $room,
                'condition_score' => 78,
                'confidence' => 0.7,
                'summary' => ucfirst($room) . ' visible in tour frames.',
                'observations' => [],
                'recommended_work' => [],
                'images' => $idxs,
            ];
            continue;
        }
        $row = $donor;
        $row['room'] = $room;
        $row['images'] = $idxs;
        // Don't reuse kitchen narrative on living/bath after a move
        if ($donorKey !== $room) {
            $row['summary'] = ucfirst($room) . ' visible in tour frames.';
            $row['observations'] = [];
            $row['recommended_work'] = [];
        }
        $out[] = $row;
    }

    return $out !== [] ? $out : $rooms;
}

/**
 * Price Gemini work recommendations in PHP (never trust AI dollars).
 *
 * @param list<array<string,mixed>> $rooms
 * @param list<array<string,mixed>> $frames
 * @return array{
 *   rooms:list<array<string,mixed>>,
 *   overall_score:int,
 *   required_low:int,
 *   required_high:int,
 *   recommended_low:int,
 *   recommended_high:int,
 *   optional_low:int,
 *   optional_high:int,
 *   total_low:int,
 *   total_high:int
 * }
 */
function yai_estimate(array $rooms, array $frames): array
{
    $byRoom = [];
    foreach ($rooms as $row) {
        if (!is_array($row)) {
            continue;
        }
        $room = yai_normalize_room((string) ($row['room'] ?? ''));
        if ($room === '' || !in_array($room, YAI_ROOMS, true)) {
            continue;
        }

        $score = yai_clamp_score($row['condition_score'] ?? $row['score'] ?? null);
        // Legacy good/fair/poor → approximate score if model still returns it
        if (!isset($row['condition_score']) && isset($row['condition'])) {
            $legacy = yai_normalize_condition((string) $row['condition']);
            $score = match ($legacy) {
                'poor' => 45,
                'fair' => 65,
                default => 82,
            };
        }
        $confidence = yai_clamp_confidence($row['confidence'] ?? 0.75);
        $summary = yai_clamp_prose((string) ($row['summary'] ?? $row['note'] ?? ''), YAI_SUMMARY_MAX_CHARS);
        $observations = [];
        if (isset($row['observations']) && is_array($row['observations'])) {
            foreach ($row['observations'] as $obs) {
                $obs = yai_clamp_prose((string) $obs, YAI_OBS_MAX_CHARS);
                if ($obs !== '') {
                    $observations[] = $obs;
                }
                if (count($observations) >= 3) {
                    break;
                }
            }
        }

        $idxs = $row['images'] ?? $row['image_indexes'] ?? [];
        if (!is_array($idxs)) {
            $idxs = [];
        }
        $imgs = [];
        foreach ($idxs as $i) {
            $i = (int) $i;
            if (isset($frames[$i])) {
                $imgs[$frames[$i]['url']] = $frames[$i];
            }
        }

        $workItems = [];
        $rawWork = $row['recommended_work'] ?? $row['work'] ?? [];
        if (!is_array($rawWork)) {
            $rawWork = [];
        }
        foreach ($rawWork as $w) {
            if (!is_array($w)) {
                continue;
            }
            $code = strtolower(trim((string) ($w['code'] ?? '')));
            $price = renovation_price_for_code($code);
            if ($price === null) {
                continue; // unknown / hallucinated codes ignored
            }
            $priority = yai_normalize_priority((string) ($w['priority'] ?? 'recommended'));
            $title = trim((string) ($w['title'] ?? ''));
            if ($title === '') {
                $title = $price['title'];
            }
            $reason = yai_clamp_prose((string) ($w['reason'] ?? ''), YAI_REASON_MAX_CHARS);
            // Dedupe by code within room; keep stricter priority
            if (isset($workItems[$code])) {
                $rank = ['required' => 3, 'recommended' => 2, 'optional' => 1];
                if (($rank[$priority] ?? 0) <= ($rank[$workItems[$code]['priority']] ?? 0)) {
                    continue;
                }
            }
            $workItems[$code] = [
                'code' => $code,
                'title' => $title,
                'priority' => $priority,
                'reason' => $reason,
                'estimate_low' => $price['low'],
                'estimate_high' => $price['high'],
            ];
        }

        if (!isset($byRoom[$room])) {
            $byRoom[$room] = [
                'room' => $room,
                'condition_score' => $score,
                'confidence' => $confidence,
                'summary' => $summary,
                'observations' => $observations,
                'work' => $workItems,
                'images' => $imgs,
            ];
            continue;
        }
        // Merge: keep worse (lower) score, merge images/work/observations
        if ($score < $byRoom[$room]['condition_score']) {
            $byRoom[$room]['condition_score'] = $score;
            if ($summary !== '') {
                $byRoom[$room]['summary'] = $summary;
            }
        }
        $byRoom[$room]['confidence'] = min($byRoom[$room]['confidence'], $confidence);
        foreach ($observations as $obs) {
            if (!in_array($obs, $byRoom[$room]['observations'], true)) {
                $byRoom[$room]['observations'][] = $obs;
            }
        }
        foreach ($workItems as $code => $item) {
            if (!isset($byRoom[$room]['work'][$code])) {
                $byRoom[$room]['work'][$code] = $item;
                continue;
            }
            $rank = ['required' => 3, 'recommended' => 2, 'optional' => 1];
            if (($rank[$item['priority']] ?? 0) > ($rank[$byRoom[$room]['work'][$code]['priority']] ?? 0)) {
                $byRoom[$room]['work'][$code] = $item;
            }
        }
        foreach ($imgs as $url => $frame) {
            $byRoom[$room]['images'][$url] = $frame;
        }
    }

    $lines = [];
    $scoreSum = 0;
    $scoreN = 0;
    $budgets = [
        'required' => [0, 0],
        'recommended' => [0, 0],
        'optional' => [0, 0],
    ];

    foreach (YAI_ROOMS as $room) {
        if (!isset($byRoom[$room])) {
            continue;
        }
        $item = $byRoom[$room];
        $workList = array_values($item['work']);
        usort($workList, static function ($a, $b) {
            $rank = ['required' => 0, 'recommended' => 1, 'optional' => 2];

            return ($rank[$a['priority']] ?? 9) <=> ($rank[$b['priority']] ?? 9);
        });

        $roomBudgets = [
            'required' => [0, 0],
            'recommended' => [0, 0],
            'optional' => [0, 0],
        ];
        foreach ($workList as $w) {
            $p = $w['priority'];
            $roomBudgets[$p][0] += $w['estimate_low'];
            $roomBudgets[$p][1] += $w['estimate_high'];
            $budgets[$p][0] += $w['estimate_low'];
            $budgets[$p][1] += $w['estimate_high'];
        }

        $roomLow = $roomBudgets['required'][0] + $roomBudgets['recommended'][0] + $roomBudgets['optional'][0];
        $roomHigh = $roomBudgets['required'][1] + $roomBudgets['recommended'][1] + $roomBudgets['optional'][1];
        $score = (int) $item['condition_score'];
        $scoreSum += $score;
        $scoreN++;

        $lines[] = [
            'room' => $room,
            'condition_score' => $score,
            'condition_label' => renovation_score_label($score),
            'confidence' => round((float) $item['confidence'], 2),
            'summary' => $item['summary'],
            'observations' => $item['observations'],
            'recommended_work' => $workList,
            'required_low' => $roomBudgets['required'][0],
            'required_high' => $roomBudgets['required'][1],
            'recommended_low' => $roomBudgets['recommended'][0],
            'recommended_high' => $roomBudgets['recommended'][1],
            'optional_low' => $roomBudgets['optional'][0],
            'optional_high' => $roomBudgets['optional'][1],
            'estimate_low' => $roomLow,
            'estimate_high' => $roomHigh,
            'images' => array_values($item['images']),
        ];
    }

    $overall = $scoreN > 0 ? (int) round($scoreSum / $scoreN) : 0;
    $totalLow = $budgets['required'][0] + $budgets['recommended'][0] + $budgets['optional'][0];
    $totalHigh = $budgets['required'][1] + $budgets['recommended'][1] + $budgets['optional'][1];

    return [
        'rooms' => $lines,
        'overall_score' => $overall,
        'overall_label' => renovation_score_label($overall),
        'required_low' => $budgets['required'][0],
        'required_high' => $budgets['required'][1],
        'recommended_low' => $budgets['recommended'][0],
        'recommended_high' => $budgets['recommended'][1],
        'optional_low' => $budgets['optional'][0],
        'optional_high' => $budgets['optional'][1],
        'total_low' => $totalLow,
        'total_high' => $totalHigh,
    ];
}

/** Compact rooms payload for DB (no absolute disk paths). */
function yai_rooms_for_db(array $rooms): array
{
    $out = [];
    foreach ($rooms as $row) {
        if (!is_array($row)) {
            continue;
        }
        $imgs = [];
        foreach ($row['images'] ?? [] as $img) {
            if (!is_array($img)) {
                continue;
            }
            $imgs[] = [
                'time_sec' => (int) ($img['time_sec'] ?? 0),
                'url' => (string) ($img['url'] ?? ''),
            ];
        }
        $work = [];
        foreach ($row['recommended_work'] ?? [] as $w) {
            if (!is_array($w)) {
                continue;
            }
            $work[] = [
                'code' => (string) ($w['code'] ?? ''),
                'title' => (string) ($w['title'] ?? ''),
                'priority' => (string) ($w['priority'] ?? ''),
                'reason' => (string) ($w['reason'] ?? ''),
                'estimate_low' => (int) ($w['estimate_low'] ?? 0),
                'estimate_high' => (int) ($w['estimate_high'] ?? 0),
            ];
        }
        $out[] = [
            'room' => (string) ($row['room'] ?? ''),
            'condition_score' => (int) ($row['condition_score'] ?? 0),
            'condition_label' => (string) ($row['condition_label'] ?? ''),
            'confidence' => (float) ($row['confidence'] ?? 0),
            'summary' => (string) ($row['summary'] ?? ''),
            'observations' => array_values($row['observations'] ?? []),
            'recommended_work' => $work,
            'required_low' => (int) ($row['required_low'] ?? 0),
            'required_high' => (int) ($row['required_high'] ?? 0),
            'recommended_low' => (int) ($row['recommended_low'] ?? 0),
            'recommended_high' => (int) ($row['recommended_high'] ?? 0),
            'optional_low' => (int) ($row['optional_low'] ?? 0),
            'optional_high' => (int) ($row['optional_high'] ?? 0),
            'estimate_low' => (int) ($row['estimate_low'] ?? 0),
            'estimate_high' => (int) ($row['estimate_high'] ?? 0),
            'images' => $imgs,
        ];
    }

    return $out;
}

/**
 * Upsert Gemini analysis for a zillow_sale_listings row.
 *
 * @param array<string, mixed> $result
 * @param array<string, mixed> $jobMeta
 * @return array{ok:bool,id?:int,error?:string}
 */
function yai_db_upsert_analysis(int $listingId, string $jobId, array $result, array $jobMeta = []): array
{
    require_once dirname(__DIR__) . '/pdo_connect.php';
    $pdo = db_pdo_connect();
    if (!$pdo instanceof PDO) {
        return ['ok' => false, 'error' => db_pdo_last_error() ?? 'DB connect failed'];
    }

    $exists = $pdo->prepare('SELECT id FROM zillow_sale_listings WHERE id = ? LIMIT 1');
    $exists->execute([$listingId]);
    if (!$exists->fetch()) {
        return ['ok' => false, 'error' => 'listing_id not found: ' . $listingId];
    }

    $videoId = (string) ($jobMeta['video_id'] ?? '');
    if ($videoId === '' && preg_match('/^([A-Za-z0-9_-]{6,20})_/', $jobId, $m)) {
        $videoId = $m[1];
    }
    $source = strtolower(trim((string) ($jobMeta['source'] ?? '')));
    $youtubeUrl = (string) ($jobMeta['youtube_url'] ?? '');
    // Never invent a YouTube URL for file uploads (video_id like upl_xxxxxxxx is not YouTube).
    $isUpload = $source === 'upload'
        || str_starts_with($videoId, 'upl_')
        || str_starts_with($jobId, 'upl_');
    if ($isUpload) {
        $youtubeUrl = '';
    } elseif ($youtubeUrl === '' && $videoId !== '' && !str_starts_with($videoId, 'upl_')) {
        $youtubeUrl = 'https://www.youtube.com/watch?v=' . $videoId;
    }
    $title = (string) ($jobMeta['title'] ?? '');
    if ($title === '') {
        $infoPath = YAI_ROOT . '/' . $jobId . '/video.info.json';
        if (is_readable($infoPath)) {
            $info = json_decode((string) file_get_contents($infoPath), true);
            if (is_array($info)) {
                $title = (string) ($info['title'] ?? $info['fulltitle'] ?? '');
                if ($youtubeUrl === '' && !empty($info['webpage_url'])) {
                    $youtubeUrl = (string) $info['webpage_url'];
                }
                if ($videoId === '' && !empty($info['id'])) {
                    $videoId = (string) $info['id'];
                }
            }
        }
    }
    $contactEmail = strtolower(trim((string) ($jobMeta['email'] ?? $jobMeta['contact_email'] ?? '')));
    if ($contactEmail !== '' && !filter_var($contactEmail, FILTER_VALIDATE_EMAIL)) {
        $contactEmail = '';
    }
    if (strlen($contactEmail) > 255) {
        $contactEmail = substr($contactEmail, 0, 255);
    }

    $roomsJson = json_encode([
        'version' => 2,
        'rooms' => yai_rooms_for_db($result['rooms'] ?? []),
        'overall_score' => (int) ($result['overall_score'] ?? 0),
        'overall_label' => (string) ($result['overall_label'] ?? ''),
        'required_low' => (int) ($result['required_low'] ?? 0),
        'required_high' => (int) ($result['required_high'] ?? 0),
        'recommended_low' => (int) ($result['recommended_low'] ?? 0),
        'recommended_high' => (int) ($result['recommended_high'] ?? 0),
        'optional_low' => (int) ($result['optional_low'] ?? 0),
        'optional_high' => (int) ($result['optional_high'] ?? 0),
        'total_low' => (int) ($result['total_low'] ?? 0),
        'total_high' => (int) ($result['total_high'] ?? 0),
        'disclaimer' => (string) ($result['disclaimer'] ?? ''),
    ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    $geminiRaw = json_encode([
        'rooms_raw' => $result['gemini']['rooms_raw'] ?? null,
        'usage' => $result['gemini']['usage'] ?? null,
        'elapsed_ms' => $result['gemini']['elapsed_ms'] ?? null,
        'finish_reason' => $result['gemini']['finish_reason'] ?? null,
    ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

    $analyzedAt = null;
    if (!empty($result['analyzed_at'])) {
        $ts = strtotime((string) $result['analyzed_at']);
        if ($ts !== false) {
            $analyzedAt = date('Y-m-d H:i:s', $ts);
        }
    }
    if ($analyzedAt === null) {
        $analyzedAt = date('Y-m-d H:i:s');
    }

    $sql = <<<'SQL'
INSERT INTO ai_analyses (
    listing_id, job_id, youtube_url, video_id, video_title, contact_email, model,
    images_used, total_low, total_high, rooms_json, gemini_raw_json, analyzed_at
) VALUES (
    :listing_id, :job_id, :youtube_url, :video_id, :video_title, :contact_email, :model,
    :images_used, :total_low, :total_high, :rooms_json, :gemini_raw_json, :analyzed_at
)
ON DUPLICATE KEY UPDATE
    listing_id = VALUES(listing_id),
    youtube_url = VALUES(youtube_url),
    video_id = VALUES(video_id),
    video_title = VALUES(video_title),
    contact_email = COALESCE(VALUES(contact_email), contact_email),
    model = VALUES(model),
    images_used = VALUES(images_used),
    total_low = VALUES(total_low),
    total_high = VALUES(total_high),
    rooms_json = VALUES(rooms_json),
    gemini_raw_json = VALUES(gemini_raw_json),
    analyzed_at = VALUES(analyzed_at)
SQL;

    try {
        $stmt = $pdo->prepare($sql);
        $stmt->execute([
            ':listing_id' => $listingId,
            ':job_id' => $jobId,
            ':youtube_url' => $youtubeUrl !== '' ? $youtubeUrl : null,
            ':video_id' => $videoId !== '' ? $videoId : null,
            ':video_title' => $title !== '' ? $title : null,
            ':contact_email' => $contactEmail !== '' ? $contactEmail : null,
            ':model' => (string) ($result['model'] ?? ''),
            ':images_used' => (int) ($result['images_used'] ?? 0),
            ':total_low' => (int) ($result['total_low'] ?? 0),
            ':total_high' => (int) ($result['total_high'] ?? 0),
            ':rooms_json' => $roomsJson,
            ':gemini_raw_json' => $geminiRaw,
            ':analyzed_at' => $analyzedAt,
        ]);
        $idRow = $pdo->prepare('SELECT id FROM ai_analyses WHERE job_id = ? LIMIT 1');
        $idRow->execute([$jobId]);
        $row = $idRow->fetch();

        return ['ok' => true, 'id' => (int) ($row['id'] ?? 0)];
    } catch (Throwable $e) {
        return ['ok' => false, 'error' => $e->getMessage()];
    }
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    yai_fail('POST required', 405);
}

$raw = file_get_contents('php://input');
$body = is_string($raw) ? json_decode($raw, true) : null;
$id = is_array($body) ? trim((string) ($body['id'] ?? '')) : '';
if ($id === '' || !preg_match('/^[A-Za-z0-9._-]{4,80}$/', $id)) {
    yai_fail('Missing or invalid id');
}

$credsPath = dirname(__DIR__) . '/ai.credentials.php';
if (!is_readable($credsPath)) {
    yai_fail('Need ai.credentials.php — copy from ai.credentials.example.php', 500);
}
/** @var array<string, mixed> $creds */
$creds = require $credsPath;
$apiKey = trim((string) ($creds['gemini_api_key'] ?? ''));
$model = trim((string) ($creds['gemini_model'] ?? 'gemini-2.0-flash'));
if ($apiKey === '' || $apiKey === 'YOUR_GEMINI_API_KEY') {
    yai_fail('Set gemini_api_key in ai.credentials.php', 500);
}

$selectedDir = YAI_ROOT . '/' . $id . '/selected';
if (!is_dir($selectedDir)) {
    yai_fail('Job not found: ' . $id, 404);
}

$files = glob($selectedDir . '/t*.jpg') ?: [];
natcasesort($files);
$files = array_values($files);
if ($files === []) {
    yai_fail('No frames for this job', 404);
}

$log = [];
$log[] = 'Job ' . $id . ' — targeting kitchen, living, bathroom, bedroom only (no exterior).';
$pick = yai_pick_for_analysis($files, YAI_MAX_IMAGES);
$log = array_merge($log, $pick['log']);
$files = $pick['picked'];
$localGuesses = $pick['guesses'] ?? [];
if ($files === []) {
    yai_fail('No house-related frames after local filter', 422, ['log' => $log]);
}

$frames = [];
foreach ($files as $idx => $path) {
    $base = basename($path);
    $timeSec = preg_match('/^t(\d+)\.jpg$/', $base, $m) ? (int) $m[1] : 0;
    $frames[] = [
        'path' => $path,
        'time_sec' => $timeSec,
        'url' => '/yhome_ai/' . rawurlencode($id) . '/selected/' . rawurlencode($base),
        'local_guess' => $localGuesses[$idx] ?? 'interior',
    ];
}

$parts = [];
$totalBytes = 0;
foreach ($frames as $i => $frame) {
    $bin = yai_jpeg_for_gemini($frame['path']);
    if ($bin === null) {
        continue;
    }
    $totalBytes += strlen($bin);
    $guess = $frame['local_guess'] ?? 'interior';
    $parts[] = ['text' => 'IMAGE #' . $i . ' (t=' . $frame['time_sec'] . 's).'
        . ' Local hint (may be wrong): ' . $guess
        . '. Classify the room from what you SEE (kitchen / living / bathroom / bedroom).'
        . ' Put this image only under the room type that matches the photo.'];
    $parts[] = [
        'inline_data' => [
            'mime_type' => 'image/jpeg',
            'data' => base64_encode($bin),
        ],
    ];
}

$codesList = implode(', ', renovation_allowed_codes());
$prompt = 'You assess visible room condition from house-tour frames for buyer due-diligence.
Images were pre-filtered to interiors (floor plans, maps, and exteriors removed).
For each IMAGE #, decide the room type from visual content: kitchen, living, bathroom, or bedroom.
- kitchen = cooktop/range/hood/cabinets+sink work zone (NOT living rooms with sofas)
- living = sofa/lounge/family room seating areas
- bathroom = vanity/toilet/shower/tub (NOT floor plans)
- bedroom = bed or clear sleeping room
Create one rooms[] entry per room type that appears. Merge all images of that room into ONE assessment.
Assign each IMAGE # only to the room that matches what is actually shown.
Do NOT invent dollar amounts. Do NOT assess hidden plumbing, electrical, structural, HVAC, roof, mold, foundation, or anything not visible.

IGNORE ALL FURNITURE in every room (sofas, chairs, tables, beds, rugs, decor, staging items, freestanding pieces). Never recommend work for furniture and never let furniture condition affect scores or recommended_work.

Room focus (prioritize these when scoring and recommending work; other visible fixed finishes may still be noted if clearly relevant):
- living + bedroom: focus on floors, walls (paint/drywall), and windows.
- kitchen: focus on floors, walls (paint/drywall), windows, cabinets, sinks (incl. faucet if visible), and cooking items (range/cooktop, oven, range hood).
- bathroom: focus on floors, walls (paint/drywall), windows, toilet, and showers (incl. shower enclosure/tile that is part of the shower).

Internally weigh: visible physical condition 40%, age/datedness 20%, maintenance 20%, functional appearance 10%, cosmetic 10% — weighted toward the focused items above.
Return condition_score as an integer 0–100:
90–100 Excellent, 80–89 Very Good, 70–79 Good, 60–69 Fair, 40–59 Poor, 20–39 Very Poor, 0–19 Severe.
Damage hurts scores more than dated style. A clean dated bathroom can still be 70–80.
confidence 0.0–1.0 = image sufficiency for that room (blur ≠ lower score).

recommended_work[].priority must be required|recommended|optional:
- required = visible damage needing repair
- recommended = meaningful improvement for buyers
- optional = primarily cosmetic
Use ONLY these work codes: ' . $codesList . '
Prefer work codes that match each room focus (all rooms → floor/paint/drywall/window; kitchen also → cabinet/sink/faucet/hood/cooking-area; bathroom also → toilet/shower). Other fixed-finish codes are allowed when clearly needed.
Keep summary ≤25 words (one plain sentence, no filler or repeated adjectives). ≤3 short observations (≤12 words each). reason ≤12 words. Deduplicate work items.
Never pad summaries with repeated phrases.

Return ONLY JSON:
{"rooms":[{"room":"kitchen","condition_score":78,"confidence":0.91,"summary":"…","observations":["…"],"recommended_work":[{"code":"cabinet_refinish","title":"Refinish cabinets","priority":"optional","reason":"…"}],"images":[0,3]}]}
Map images[] to IMAGE # indexes. Emit one room entry for each room type clearly visible among the images.';

$parts[] = ['text' => $prompt];
$log[] = 'Built Gemini request with ' . count($frames) . ' house frames (from '
    . count(glob($selectedDir . '/t*.jpg') ?: []) . ' saved), downscaled ≤'
    . YAI_SEND_MAX_WIDTH . 'px (~' . round($totalBytes / 1024) . ' KB).';
$log[] = 'Calling model ' . $model . '…';

$url = 'https://generativelanguage.googleapis.com/v1beta/models/'
    . rawurlencode($model) . ':generateContent?key=' . rawurlencode($apiKey);

$payload = json_encode([
    'contents' => [['parts' => $parts]],
    'generationConfig' => [
        'temperature' => 0.1,
        'maxOutputTokens' => 4096,
        // Minimize thinking so JSON isn't truncated by thought/filler tokens.
        'thinkingConfig' => [
            'thinkingBudget' => 0,
        ],
        'responseMimeType' => 'application/json',
        'responseSchema' => [
            'type' => 'OBJECT',
            'properties' => [
                'rooms' => [
                    'type' => 'ARRAY',
                    'items' => [
                        'type' => 'OBJECT',
                        'properties' => [
                            'room' => ['type' => 'STRING'],
                            'condition_score' => ['type' => 'INTEGER'],
                            'confidence' => ['type' => 'NUMBER'],
                            'summary' => [
                                'type' => 'STRING',
                                'description' => 'One sentence, max 25 words. No repeated filler phrases.',
                            ],
                            'observations' => [
                                'type' => 'ARRAY',
                                'items' => [
                                    'type' => 'STRING',
                                    'description' => 'Max 12 words.',
                                ],
                            ],
                            'recommended_work' => [
                                'type' => 'ARRAY',
                                'items' => [
                                    'type' => 'OBJECT',
                                    'properties' => [
                                        'code' => ['type' => 'STRING'],
                                        'title' => ['type' => 'STRING'],
                                        'priority' => ['type' => 'STRING'],
                                        'reason' => ['type' => 'STRING'],
                                    ],
                                    'required' => ['code', 'priority'],
                                ],
                            ],
                            'images' => [
                                'type' => 'ARRAY',
                                'items' => ['type' => 'INTEGER'],
                            ],
                        ],
                        'required' => ['room', 'condition_score', 'images'],
                    ],
                ],
            ],
            'required' => ['rooms'],
        ],
    ],
], JSON_UNESCAPED_SLASHES);

$t0 = microtime(true);
$ch = curl_init($url);
curl_setopt_array($ch, [
    CURLOPT_POST => true,
    CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
    CURLOPT_POSTFIELDS => $payload,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT => 180,
]);
$response = curl_exec($ch);
$http = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
$err = curl_error($ch);
$elapsedMs = (int) round((microtime(true) - $t0) * 1000);

if ($response === false) {
    $log[] = 'Gemini request failed after ' . $elapsedMs . 'ms: ' . $err;
    yai_fail('Gemini request failed: ' . $err, 502, ['log' => $log]);
}

$json = json_decode((string) $response, true);
if ($http < 200 || $http >= 300 || !is_array($json)) {
    $msg = is_array($json) ? (string) ($json['error']['message'] ?? substr((string) $response, 0, 400)) : substr((string) $response, 0, 400);
    // Schema may be unsupported on some models — retry once without schema
    if ($http === 400 && str_contains(strtolower($msg), 'schema')) {
        $log[] = 'Model rejected responseSchema; retrying without schema…';
        $payloadRetry = json_decode((string) $payload, true);
        if (is_array($payloadRetry)) {
            unset($payloadRetry['generationConfig']['responseSchema']);
            $payload = json_encode($payloadRetry, JSON_UNESCAPED_SLASHES);
            $t0 = microtime(true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
            $response = curl_exec($ch);
            $http = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $err = curl_error($ch);
            $elapsedMs = (int) round((microtime(true) - $t0) * 1000);
            $json = json_decode((string) $response, true);
            $msg = is_array($json) ? (string) ($json['error']['message'] ?? substr((string) $response, 0, 400)) : substr((string) $response, 0, 400);
        }
    }
    // Transient overload / rate limit — retry a few times with backoff
    $attempt = 0;
    while (($http === 503 || $http === 429) && $attempt < 3) {
        $attempt++;
        $waitSec = $attempt * 2;
        $log[] = 'Gemini HTTP ' . $http . ' after ' . $elapsedMs . 'ms (high demand/rate limit); retry '
            . $attempt . '/3 in ' . $waitSec . 's…';
        sleep($waitSec);
        $t0 = microtime(true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
        $response = curl_exec($ch);
        $http = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err = curl_error($ch);
        $elapsedMs = (int) round((microtime(true) - $t0) * 1000);
        if ($response === false) {
            $log[] = 'Gemini request failed after ' . $elapsedMs . 'ms: ' . $err;
            yai_fail('Gemini request failed: ' . $err, 502, ['log' => $log]);
        }
        $json = json_decode((string) $response, true);
        $msg = is_array($json) ? (string) ($json['error']['message'] ?? substr((string) $response, 0, 400)) : substr((string) $response, 0, 400);
    }
    if ($response === false) {
        yai_fail('Gemini request failed: ' . $err, 502, ['log' => $log]);
    }
    if ($http < 200 || $http >= 300 || !is_array($json)) {
        $msg = is_array($json) ? (string) ($json['error']['message'] ?? substr((string) $response, 0, 400)) : substr((string) $response, 0, 400);
        $log[] = 'Gemini HTTP ' . $http . ' after ' . $elapsedMs . 'ms: ' . $msg;
        yai_fail('Gemini error (HTTP ' . $http . '): ' . $msg, 502, ['log' => $log]);
    }
}

$finish = (string) ($json['candidates'][0]['finishReason'] ?? '');
$usage = $json['usageMetadata'] ?? null;
$log[] = 'Gemini responded in ' . $elapsedMs . 'ms'
    . ($finish !== '' ? ' (finish: ' . $finish . ')' : '') . '.';
if (is_array($usage)) {
    $log[] = 'Tokens — prompt: ' . ($usage['promptTokenCount'] ?? '?')
        . ', output: ' . ($usage['candidatesTokenCount'] ?? '?')
        . ', total: ' . ($usage['totalTokenCount'] ?? '?') . '.';
}

$text = '';
$partsOut = $json['candidates'][0]['content']['parts'] ?? [];
if (is_array($partsOut)) {
    foreach ($partsOut as $p) {
        if (is_array($p) && isset($p['text'])) {
            $text .= (string) $p['text'];
        }
    }
}
$text = trim($text);
$parsed = yai_parse_rooms_json($text);
if ($parsed === null) {
    $log[] = 'Could not parse rooms JSON (finish=' . ($finish !== '' ? $finish : '?')
        . '). Raw (truncated): ' . substr($text, 0, 280);
    yai_fail('Could not parse Gemini rooms JSON: ' . substr($text, 0, 300), 502, [
        'log' => $log,
        'gemini' => [
            'finish_reason' => $finish,
            'raw_text' => $text,
        ],
    ]);
}

$geminiRooms = [];
foreach ($parsed['rooms'] as $row) {
    if (!is_array($row)) {
        continue;
    }
    $geminiRooms[] = [
        'room' => (string) ($row['room'] ?? ''),
        'condition_score' => $row['condition_score'] ?? $row['condition'] ?? null,
        'confidence' => $row['confidence'] ?? null,
        'summary' => (string) ($row['summary'] ?? $row['note'] ?? ''),
        'observations' => $row['observations'] ?? [],
        'recommended_work' => $row['recommended_work'] ?? [],
        'images' => $row['images'] ?? [],
    ];
}
$log[] = 'Gemini raw rooms (' . count($geminiRooms) . '): '
    . implode('; ', array_map(static function ($r) {
        $imgs = is_array($r['images']) ? implode(',', $r['images']) : '';
        $score = $r['condition_score'] ?? '?';
        $workN = is_array($r['recommended_work'] ?? null) ? count($r['recommended_work']) : 0;

        return $r['room'] . '/score=' . $score . ' work=' . $workN . ' imgs=[' . $imgs . ']';
    }, $geminiRooms)) . '.';

$aligned = yai_realign_rooms_by_local_guess($parsed['rooms'], $frames);
if (count($aligned) !== count($parsed['rooms'])
    || array_column($aligned, 'room') != array_column($parsed['rooms'], 'room')) {
    $log[] = 'Realigned rooms by local frame guesses: '
        . implode(', ', array_map(static fn ($r) => (string) ($r['room'] ?? '?'), $aligned)) . '.';
}
$estimate = yai_estimate($aligned, $frames);
foreach ($estimate['rooms'] as $er) {
    $log[] = ucfirst((string) $er['room']) . ' score ' . $er['condition_score']
        . '/100 → budget $' . number_format((int) $er['estimate_low'])
        . ' – $' . number_format((int) $er['estimate_high'])
        . ' (' . count($er['recommended_work'] ?? []) . ' work items).';
}
$log[] = 'Overall visible condition ' . $estimate['overall_score'] . '/100; potential total $'
    . number_format($estimate['total_low']) . ' – $' . number_format($estimate['total_high']) . '.';

$result = [
    'ok' => true,
    'job_id' => $id,
    'model' => $model,
    'images_used' => count($frames),
    'rooms' => $estimate['rooms'],
    'overall_score' => $estimate['overall_score'],
    'overall_label' => $estimate['overall_label'],
    'required_low' => $estimate['required_low'],
    'required_high' => $estimate['required_high'],
    'recommended_low' => $estimate['recommended_low'],
    'recommended_high' => $estimate['recommended_high'],
    'optional_low' => $estimate['optional_low'],
    'optional_high' => $estimate['optional_high'],
    'total_low' => $estimate['total_low'],
    'total_high' => $estimate['total_high'],
    'disclaimer' => 'AI estimate based on visible conditions in the provided images. Hidden plumbing, electrical, structural, HVAC, roofing, moisture, mold, foundation and other concealed conditions are not included. Actual contractor pricing may vary.',
    'analyzed_at' => date('c'),
    'log' => $log,
    'pick_stats' => $pick['stats'],
    'gemini' => [
        'model' => $model,
        'http' => $http,
        'elapsed_ms' => $elapsedMs,
        'finish_reason' => $finish,
        'usage' => is_array($usage) ? $usage : null,
        'rooms_raw' => $geminiRooms,
        'raw_text' => $text,
    ],
];

$listingId = 0;
if (is_array($body) && isset($body['listing_id'])) {
    $listingId = (int) $body['listing_id'];
}
$metaPath = YAI_ROOT . '/' . $id . '/job.json';
$jobMeta = [];
if (is_readable($metaPath)) {
    $tmpMeta = json_decode((string) file_get_contents($metaPath), true);
    if (is_array($tmpMeta)) {
        $jobMeta = $tmpMeta;
        if ($listingId <= 0 && isset($tmpMeta['listing_id'])) {
            $listingId = (int) $tmpMeta['listing_id'];
        }
    }
}
if ($listingId > 0) {
    $jobMeta['listing_id'] = $listingId;
    $dbSave = yai_db_upsert_analysis($listingId, $id, $result, $jobMeta);
    if ($dbSave['ok']) {
        $result['listing_id'] = $listingId;
        $result['analysis_db_id'] = $dbSave['id'] ?? null;
        $log[] = 'Saved analysis to ai_analyses id=' . ($dbSave['id'] ?? '?')
            . ' for listing_id=' . $listingId . '.';
        $result['log'] = $log;
    } else {
        $log[] = 'DB save skipped/failed: ' . ($dbSave['error'] ?? 'unknown');
        $result['log'] = $log;
    }
    file_put_contents($metaPath, json_encode($jobMeta, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
}

file_put_contents(
    YAI_ROOT . '/' . $id . '/analysis.json',
    json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
);

$push = yai_push_job_frames_to_public($id, YAI_ROOT);
$result['prod_frames_sync'] = $push;
if (!empty($push['skipped'])) {
    $log[] = 'Frame sync skipped (already on public host).';
} elseif (!empty($push['ok'])) {
    $log[] = 'Uploaded ' . (int) ($push['uploaded'] ?? 0)
        . ' selected frames to ' . yai_public_base_url() . '.';
} else {
    $log[] = 'Frame upload to production failed: ' . (string) ($push['error'] ?? 'unknown');
}
$result['log'] = $log;

yai_out($result);
