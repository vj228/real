<?php

declare(strict_types=1);

/**
 * Near-duplicate JPEG detection for yHome tour frames (aHash + file md5).
 */

if (!function_exists('yai_dedupe_gray')) {
    function yai_dedupe_gray(int $c): int
    {
        return (int) (0.299 * (($c >> 16) & 0xFF) + 0.587 * (($c >> 8) & 0xFF) + 0.114 * ($c & 0xFF));
    }
}

if (!function_exists('yai_dedupe_perceptual_hash')) {
    /** @return ?string 64-bit binary aHash */
    function yai_dedupe_perceptual_hash(string $path): ?string
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
                $g = yai_dedupe_gray(imagecolorat($tiny, $x, $y));
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
}

if (!function_exists('yai_dedupe_hash_hamming')) {
    function yai_dedupe_hash_hamming(string $a, string $b): int
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
}

if (!function_exists('yai_dedupe_sharpness')) {
    function yai_dedupe_sharpness(string $path): float
    {
        $im = @imagecreatefromjpeg($path);
        if ($im === false) {
            return 0.0;
        }
        $w = imagesx($im);
        $h = imagesy($im);
        if ($w < 3 || $h < 3) {
            return 0.0;
        }
        $tw = 120;
        $th = max(3, (int) round($h * $tw / $w));
        $small = imagecreatetruecolor($tw, $th);
        imagecopyresampled($small, $im, 0, 0, 0, 0, $tw, $th, $w, $h);
        $sum = 0.0;
        $sum2 = 0.0;
        $n = 0;
        for ($y = 1; $y < $th - 1; $y++) {
            for ($x = 1; $x < $tw - 1; $x++) {
                $g = yai_dedupe_gray(imagecolorat($small, $x, $y));
                $lap = (float) (
                    4 * $g
                    - yai_dedupe_gray(imagecolorat($small, $x - 1, $y))
                    - yai_dedupe_gray(imagecolorat($small, $x + 1, $y))
                    - yai_dedupe_gray(imagecolorat($small, $x, $y - 1))
                    - yai_dedupe_gray(imagecolorat($small, $x, $y + 1))
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
}

/**
 * @param list<string> $paths Absolute JPEG paths
 * @return array{kept:list<string>,removed:list<string>}
 */
function yai_dedupe_jpeg_paths(array $paths, int $maxDistance = 6): array
{
    $kept = []; // list of ['path'=>,'phash'=>,'md5'=>,'sharp'=>]
    $removed = [];
    foreach ($paths as $path) {
        if (!is_string($path) || $path === '' || !is_readable($path)) {
            continue;
        }
        $phash = yai_dedupe_perceptual_hash($path);
        $md5 = (string) @md5_file($path);
        $sharp = yai_dedupe_sharpness($path);
        $dupOf = null;
        foreach ($kept as $ki => $prev) {
            $sameFile = $md5 !== '' && $prev['md5'] !== '' && $md5 === $prev['md5'];
            $near = $phash !== null && $prev['phash'] !== null
                && yai_dedupe_hash_hamming($phash, (string) $prev['phash']) <= $maxDistance;
            if ($sameFile || $near) {
                $dupOf = $ki;
                break;
            }
        }
        if ($dupOf === null) {
            $kept[] = [
                'path' => $path,
                'phash' => $phash,
                'md5' => $md5,
                'sharp' => $sharp,
            ];
            continue;
        }
        if ($sharp > (float) $kept[$dupOf]['sharp']) {
            $removed[] = $kept[$dupOf]['path'];
            $kept[$dupOf] = [
                'path' => $path,
                'phash' => $phash,
                'md5' => $md5,
                'sharp' => $sharp,
            ];
        } else {
            $removed[] = $path;
        }
    }

    return [
        'kept' => array_values(array_map(static fn ($r) => $r['path'], $kept)),
        'removed' => $removed,
    ];
}

/**
 * Delete near-duplicate t*.jpg files under a selected/ directory. Keeps sharper copies.
 *
 * @return array{kept:int,deleted:int,deleted_files:list<string>}
 */
function yai_dedupe_selected_dir(string $selectedDir, bool $delete = true): array
{
    if (!is_dir($selectedDir)) {
        return ['kept' => 0, 'deleted' => 0, 'deleted_files' => []];
    }
    $files = glob(rtrim($selectedDir, '/\\') . '/t*.jpg') ?: [];
    natcasesort($files);
    $files = array_values($files);
    $result = yai_dedupe_jpeg_paths($files);
    $deletedFiles = [];
    if ($delete) {
        foreach ($result['removed'] as $path) {
            if (@unlink($path)) {
                $deletedFiles[] = basename($path);
            }
        }
    }

    return [
        'kept' => count($result['kept']),
        'deleted' => count($deletedFiles),
        'deleted_files' => $deletedFiles,
    ];
}

/**
 * Deduplicate room image entries (exact URL + near-duplicate JPEGs on disk).
 *
 * @param list<mixed> $images
 * @return list<array{url:string}>
 */
function yai_dedupe_room_images(array $images, string $docRoot, int $maxDistance = 6): array
{
    $paths = [];
    $byPath = [];
    $urlOnly = [];
    foreach ($images as $img) {
        $url = '';
        if (is_array($img)) {
            $url = trim((string) ($img['url'] ?? ''));
        } elseif (is_string($img)) {
            $url = trim($img);
        }
        if ($url === '') {
            continue;
        }
        $pathPart = parse_url($url, PHP_URL_PATH);
        $abs = is_string($pathPart) && $pathPart !== ''
            ? rtrim($docRoot, '/\\') . '/' . ltrim(str_replace('\\', '/', $pathPart), '/')
            : '';
        if ($abs !== '' && is_readable($abs) && is_file($abs)) {
            $paths[] = $abs;
            $byPath[$abs] = $url;
        } else {
            $urlOnly[$url] = true;
        }
    }

    $keptUrls = [];
    $seen = [];
    if ($paths !== []) {
        $deduped = yai_dedupe_jpeg_paths(array_values(array_unique($paths)), $maxDistance);
        foreach ($deduped['kept'] as $path) {
            $url = $byPath[$path] ?? '';
            if ($url === '' || isset($seen[$url])) {
                continue;
            }
            $seen[$url] = true;
            $keptUrls[] = ['url' => $url];
        }
    }
    foreach (array_keys($urlOnly) as $url) {
        if (isset($seen[$url])) {
            continue;
        }
        $seen[$url] = true;
        $keptUrls[] = ['url' => $url];
    }

    return $keptUrls;
}
