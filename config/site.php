<?php

declare(strict_types=1);

/**
 * Site URLs + frame sync. Optional override: site.credentials.php (gitignored).
 *
 * return [
 *   'public_base_url' => 'https://yhome.ai',
 *   'sync_key' => 'shared-secret-for-frame-upload',
 * ];
 *
 * Local analysis pushes selected frames to public_base_url after Gemini save.
 * Production and local must share the same sync_key.
 */

$defaults = [
    'public_base_url' => 'https://yhome.ai',
    // Shared secret for api/sync_job_frames.php (override in site.credentials.php).
    'sync_key' => 'yh_frm_sync_9c4e2a71b8f0d3e6',
];

$overridePath = dirname(__DIR__) . '/site.credentials.php';
if (is_readable($overridePath)) {
    $override = require $overridePath;
    if (is_array($override)) {
        $defaults = array_merge($defaults, $override);
    }
}

return $defaults;
