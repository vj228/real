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
const YAI_MAX_IMAGES = 20;
const YAI_SEND_MAX_WIDTH = 640;
const YAI_SEND_JPEG_QUALITY = 72;
const YAI_ROOMS = ['kitchen', 'living', 'bathroom', 'bedroom'];
const YAI_OUTDOOR_REJECT = 0.42;
const YAI_PRIORITIES = ['required', 'recommended', 'optional'];

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
    $score = 0.55 * $skyFrac + 0.35 * $greenFrac + 0.15 * max(0.0, $brightFrac - 0.45);

    return max(0.0, min(1.0, $score));
}

/**
 * Heuristic kitchen likelihood 0..1 — hood metal (upper) + wood counter + dark cooktop.
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
    $um = 0;
    $un = 0;
    $mw = 0;
    $mn = 0;
    $ld = 0;
    $ln = 0;
    for ($y = 0; $y < $h; $y++) {
        for ($x = 0; $x < $w; $x++) {
            $c = imagecolorat($im, $x, $y);
            $r = ($c >> 16) & 0xFF;
            $g = ($c >> 8) & 0xFF;
            $b = $c & 0xFF;
            $lum = (0.299 * $r + 0.587 * $g + 0.114 * $b) / 255.0;
            $metal = abs($r - $g) < 18 && abs($g - $b) < 18 && $lum > 0.38 && $lum < 0.8;
            $wood = $r > $g + 8 && $g > $b + 8 && ($r - $b) > 28 && $lum > 0.28 && $lum < 0.82;
            if ($y < $h * 0.5 && $x > $w * 0.35) {
                $un++;
                if ($metal) {
                    $um++;
                }
            }
            if ($y >= $h * 0.3 && $y <= $h * 0.65 && $x > $w * 0.25) {
                $mn++;
                if ($wood) {
                    $mw++;
                }
            }
            if ($y > $h * 0.5 && $x > $w * 0.4) {
                $ln++;
                if ($lum < 0.2) {
                    $ld++;
                }
            }
        }
    }
    $U = $un > 0 ? $um / $un : 0.0;
    $W = $mn > 0 ? $mw / $mn : 0.0;
    $D = $ln > 0 ? $ld / $ln : 0.0;

    return max(0.0, min(1.0, $U * (0.5 + 2.0 * $D) * (0.5 + 1.5 * $W) * 4.0));
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
    $darkHoriz = 0;
    $n = 0;
    $midBand = 0;
    $midDark = 0;
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
                    $darkHoriz++;
                }
            }
        }
    }
    if ($n === 0) {
        return ['bathroom' => 0.0, 'bedroom' => 0.0, 'living' => 0.0, 'interior' => 0.0];
    }
    $whiteF = $white / $n;
    $wallF = $neutralWall / $n;
    $warmF = $warmSoft / $n;
    $bedBand = $midBand > 0 ? $midDark / $midBand : 0.0;
    $bathroom = max(0.0, min(1.0, 2.4 * $whiteF + 0.4 * $wallF));
    $bedroom = max(0.0, min(1.0, 1.6 * $bedBand + 0.8 * $warmF));
    $living = max(0.0, min(1.0, 1.1 * $wallF + 0.7 * $warmF + 0.5 * (1.0 - $whiteF)));
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
    // Early/late curb-appeal shots that still look semi-indoor
    if ($edge && $outdoor >= 0.28 && $kitchen < 0.35 && $cues['interior'] < 0.28) {
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
    arsort($scores);
    $guess = (string) array_key_first($scores);
    $best = (float) ($scores[$guess] ?? 0.0);
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
            'house_kept' => count($house),
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

    // Light repair then decode
    $repair = preg_replace('/"images"\s*:\s*$/', '"images":[]', $text) ?? $text;
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
        $summary = trim((string) ($row['summary'] ?? $row['note'] ?? ''));
        $observations = [];
        if (isset($row['observations']) && is_array($row['observations'])) {
            foreach ($row['observations'] as $obs) {
                $obs = trim((string) $obs);
                if ($obs !== '') {
                    $observations[] = $obs;
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
            $reason = trim((string) ($w['reason'] ?? ''));
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
    $youtubeUrl = (string) ($jobMeta['youtube_url'] ?? '');
    if ($youtubeUrl === '' && $videoId !== '') {
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
    $parts[] = ['text' => 'IMAGE #' . $i . ' (local pre-label: ' . $guess . ', t=' . $frame['time_sec'] . 's)'];
    $parts[] = [
        'inline_data' => [
            'mime_type' => 'image/jpeg',
            'data' => base64_encode($bin),
        ],
    ];
}

$codesList = implode(', ', renovation_allowed_codes());
$prompt = 'You assess visible room condition from house-tour frames for buyer due-diligence.
Images were pre-filtered to interiors. Each photo is IMAGE #N (local pre-label is a hint only).
Only rooms: kitchen, living, bathroom, bedroom. One entry per room type that appears (merge all images of that room into ONE assessment).
Do NOT invent dollar amounts. Do NOT assess hidden plumbing, electrical, structural, HVAC, roof, mold, foundation, or anything not visible.

Internally weigh: visible physical condition 40%, age/datedness 20%, maintenance 20%, functional appearance 10%, cosmetic 10%.
Return condition_score as an integer 0–100:
90–100 Excellent, 80–89 Very Good, 70–79 Good, 60–69 Fair, 40–59 Poor, 20–39 Very Poor, 0–19 Severe.
Damage hurts scores more than dated style. A clean dated bathroom can still be 70–80.
confidence 0.0–1.0 = image sufficiency for that room (blur ≠ lower score).

recommended_work[].priority must be required|recommended|optional:
- required = visible damage needing repair
- recommended = meaningful improvement for buyers
- optional = primarily cosmetic
Use ONLY these work codes: ' . $codesList . '
Keep summary ≤25 words, ≤3 short observations, reason ≤12 words. Deduplicate work items.

Return ONLY JSON:
{"rooms":[{"room":"kitchen","condition_score":78,"confidence":0.91,"summary":"…","observations":["…"],"recommended_work":[{"code":"countertop_replace","title":"Replace countertop","priority":"optional","reason":"…"}],"images":[0,3]}]}
Map images[] to IMAGE # indexes. Kitchen: stove/hood/sink/cabinets. Bathroom: toilet/tub/shower/vanity. Bedroom: bed. Living: sofa/fireplace/TV.';

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
        'temperature' => 0.15,
        'maxOutputTokens' => 16384,
        // Keep thinking low so JSON output isn't truncated by thought tokens.
        'thinkingConfig' => [
            'thinkingLevel' => 'LOW',
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
                            'summary' => ['type' => 'STRING'],
                            'observations' => [
                                'type' => 'ARRAY',
                                'items' => ['type' => 'STRING'],
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

$estimate = yai_estimate($parsed['rooms'], $frames);
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
