<?php

declare(strict_types=1);

/**
 * Site URLs. Optional override: site.credentials.php (gitignored).
 *
 * return [
 *   'public_base_url' => 'https://yhome.pro',
 * ];
 */

$defaults = [
    'public_base_url' => 'https://yhome.pro',
];

$overridePath = dirname(__DIR__) . '/site.credentials.php';
if (is_readable($overridePath)) {
    $override = require $overridePath;
    if (is_array($override)) {
        $defaults = array_merge($defaults, $override);
    }
}

return $defaults;
