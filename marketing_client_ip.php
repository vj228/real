<?php

declare(strict_types=1);

/** Client IP honoring Cloudflare / X-Forwarded-For (marketing + CTA beacon). */
function marketing_client_ip(): ?string
{
    $raw = '';
    if (!empty($_SERVER['HTTP_CF_CONNECTING_IP'])) {
        $raw = (string) $_SERVER['HTTP_CF_CONNECTING_IP'];
    } elseif (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
        $raw = (string) $_SERVER['HTTP_X_FORWARDED_FOR'];
    } elseif (!empty($_SERVER['REMOTE_ADDR'])) {
        $raw = (string) $_SERVER['REMOTE_ADDR'];
    }
    $raw = trim(explode(',', $raw)[0]);

    return $raw !== '' ? substr($raw, 0, 45) : null;
}
