<?php

declare(strict_types=1);

/**
 * TikTok app settings (safe to commit if this repo is private).
 * OAuth tokens are stored separately in cron/.tiktok_tokens.json (gitignored).
 */

const TIKTOK_CLIENT_KEY = 'awxic2xm6e7b4cpe';
const TIKTOK_CLIENT_SECRET = 'NKiwTESoh77Z6jcNrzQcMNHzmTAao3uQ';
const TIKTOK_VIDEO_URL = 'https://placeholdervideo.dev/1080x1920';

/** Must match a redirect URI registered under Login Kit for this app. */
const TIKTOK_REDIRECT_URI = 'https://yhome.pro/tiktok_auth.php';
