<?php

declare(strict_types=1);

/**
 * TikTok app settings.
 * Set TIKTOK_USE_SANDBOX = true to test with Sandbox "Real" credentials (separate tokens file).
 */

// --- true = Sandbox tab "Real" | false = Production "yHome" ---
const TIKTOK_USE_SANDBOX = true;

const TIKTOK_PROD_CLIENT_KEY = 'awxic2xm6e7b4cpe';
const TIKTOK_PROD_CLIENT_SECRET = 'NKiwTESoh77Z6jcNrzQcMNHzmTAao3uQ';

const TIKTOK_SANDBOX_CLIENT_KEY = 'sbawoefrjas77yc7zb';
const TIKTOK_SANDBOX_CLIENT_SECRET = 'abe7iEA8hsfkm3ILdtGowmOonwM7Cau0';

const TIKTOK_CLIENT_KEY = TIKTOK_USE_SANDBOX ? TIKTOK_SANDBOX_CLIENT_KEY : TIKTOK_PROD_CLIENT_KEY;
const TIKTOK_CLIENT_SECRET = TIKTOK_USE_SANDBOX ? TIKTOK_SANDBOX_CLIENT_SECRET : TIKTOK_PROD_CLIENT_SECRET;

/**
 * Comma-separated. Must be enabled under Sandbox → Scopes (or Production → Scopes).
 * If login fails with "scope", try: user.info.basic,video.upload first
 */
const TIKTOK_OAUTH_SCOPES = 'user.info.basic,video.upload,video.publish';

const TIKTOK_VIDEO_URL = 'https://json2video-cdn1.s3.amazonaws.com/clients/cfeqDsDC6w/renders/2026-05-27-24000.mp4';

const TIKTOK_POST_TITLE = 'Would you pay this much? #realestate #househunting';
const TIKTOK_PRIVACY_LEVEL = '';
const TIKTOK_BRAND_CONTENT_TOGGLE = false;
const TIKTOK_BRAND_ORGANIC_TOGGLE = false;

/** Register this under Login Kit for the active environment (Sandbox + Production). */
const TIKTOK_REDIRECT_URI = 'https://yhome.pro/tiktok_auth.php';
