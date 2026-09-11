<?php

declare(strict_types=1);

/**
 * Copy to site.credentials.php (gitignored) to override.
 */
return [
    'public_base_url' => 'https://yhome.ai',
    // Must match production so local analyze can upload frames.
    'sync_key' => 'change-me-to-a-long-random-string',
];
