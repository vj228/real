<?php

declare(strict_types=1);

/**
 * Copy to video_upload.credentials.php (gitignored) on your Mac and on yhome.pro.
 * Used by cron/send_to_ffmpeg.php (upload) and api/upload_video.php (receive).
 */
return [
    'upload_url' => 'https://yhome.pro/api/upload_video.php',
    'token' => 'replace-with-a-long-random-secret',
];
