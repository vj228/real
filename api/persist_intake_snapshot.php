<?php

declare(strict_types=1);

/**
 * Alias endpoint for intake JSON save — same logic as save_home_offer.php (avoids
 * some browser extensions blocking paths containing “offer”).
 */
require __DIR__ . '/save_home_offer.php';
