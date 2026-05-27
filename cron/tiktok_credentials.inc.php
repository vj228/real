<?php

declare(strict_types=1);

/**
 * TikTok app settings (safe to commit if this repo is private).
 * OAuth tokens are stored separately in cron/.tiktok_tokens.json (gitignored).
 */

const TIKTOK_CLIENT_KEY = 'awxic2xm6e7b4cpe';
const TIKTOK_CLIENT_SECRET = 'NKiwTESoh77Z6jcNrzQcMNHzmTAao3uQ';
const TIKTOK_VIDEO_URL = 'https://json2video-cdn1.s3.amazonaws.com/clients/cfeqDsDC6w/renders/2026-05-27-24000.mp4';

/** Caption for the post (hashtags allowed). */
const TIKTOK_POST_TITLE = 'Would you pay this much? #realestate #househunting';

/**
 * Privacy: PUBLIC_TO_EVERYONE | MUTUAL_FOLLOW_FRIENDS | FOLLOWER_OF_CREATOR | SELF_ONLY
 * Leave empty to auto-pick (prefers public when your account allows it).
 * Unaudited apps may only post as SELF_ONLY until TikTok approves your client.
 */
const TIKTOK_PRIVACY_LEVEL = '';

const TIKTOK_BRAND_CONTENT_TOGGLE = false;
const TIKTOK_BRAND_ORGANIC_TOGGLE = false;

/** Must match a redirect URI registered under Login Kit for this app. */
const TIKTOK_REDIRECT_URI = 'https://yhome.pro/tiktok_auth.php';
