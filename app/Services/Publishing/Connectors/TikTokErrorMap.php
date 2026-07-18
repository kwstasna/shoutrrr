<?php

declare(strict_types=1);

namespace App\Services\Publishing\Connectors;

use App\Enums\ErrorKind;

/**
 * Translates TikTok's `error.code` into an ErrorKind and an actionable message.
 *
 * Every TikTok v2 response — success or failure — carries an `error` envelope
 * ({code, message, log_id}), where `ok` means success. Classifying on that code
 * rather than on the HTTP status is deliberate, because the status is not a
 * reliable signal here:
 *
 *  - The same condition arrives with different statuses on different endpoints.
 *    `spam_risk_too_many_posts` is HTTP 403 from /video/init/ but HTTP **200**
 *    with the code in the body from /creator_info/query/. Status-driven code
 *    would let the 200 sail through as success.
 *  - `spam_risk_too_many_pending_share` is HTTP **403**, not 429, despite being a
 *    throttle in spirit. Routing it through generic 429 retry/backoff would be
 *    wrong: it clears only when the creator empties their TikTok inbox.
 *  - `rate_limit_exceeded` is the *only* genuine 429.
 *
 * The spam/cap codes are all mapped terminal rather than retryable on purpose:
 * they reset on a daily cadence or need a human action, so retrying inside the
 * job's backoff budget can only burn attempts (and, for video, re-upload
 * gigabytes) against a rejection that is deterministic for hours.
 */
final class TikTokErrorMap
{
    /** TikTok's success sentinel. */
    public const string OK = 'ok';

    public static function isOk(?string $code): bool
    {
        // Some endpoints omit the envelope on success; treat an absent code as OK
        // and let the caller's own field checks catch a malformed body.
        return $code === null || $code === '' || $code === self::OK;
    }

    /**
     * $status is only consulted for codes TikTok does not document (and for
     * transport-level failures), so an unrecognised 5xx still retries.
     */
    public static function classify(string $code, int $status): ErrorKind
    {
        return match ($code) {
            // The access token is bad or the app was never granted the scope. Both
            // need a reconnect, not a retry.
            'access_token_invalid',
            'scope_not_authorized',
            'scope_permission_missed' => ErrorKind::AuthExpired,

            // The only true rate limit: a 1-minute sliding window, so backing off
            // and retrying genuinely helps.
            'rate_limit_exceeded' => ErrorKind::RateLimited,

            // Spam / quota ceilings. Terminal — see the class docblock.
            'spam_risk_too_many_posts',
            'spam_risk_too_many_pending_share',
            'spam_risk_user_banned_from_posting',
            'reached_active_user_cap' => ErrorKind::Validation,

            // Compliance rejections. All need a human to change something (app
            // audit status, the chosen privacy level, a portal domain setting).
            'unaudited_client_can_only_post_to_private_accounts',
            'privacy_level_option_mismatch',
            'url_ownership_unverified' => ErrorKind::Validation,

            // Malformed request or a publish_id that isn't ours.
            'invalid_param',
            'invalid_publish_id',
            'token_not_authorized_for_specified_publish_id',
            'file_format_check_failed',
            'duration_check_failed',
            'frame_rate_check_failed',
            'picture_size_check_failed',
            'video_pull_failed',
            'photo_pull_failed' => ErrorKind::Validation,

            'internal_error' => ErrorKind::ServerError,

            // Unknown code: fall back to the HTTP status so a 5xx still retries
            // and a 4xx stays terminal.
            default => self::classifyStatus($status),
        };
    }

    /**
     * A message the user can act on. TikTok's own `error.message` is often terse
     * or internal ("param error"), so the documented codes get purpose-written
     * copy and anything unrecognised falls back to TikTok's text.
     */
    public static function message(string $code, string $fallback): string
    {
        return match ($code) {
            'access_token_invalid' => 'TikTok rejected the access token. Reconnect the account.',
            'scope_not_authorized', 'scope_permission_missed' => 'This TikTok account has not granted the permissions needed to post. Reconnect it and accept all requested permissions.',
            'rate_limit_exceeded' => 'TikTok is rate limiting this account. It will retry shortly.',
            'spam_risk_too_many_posts' => 'This TikTok account has hit its daily posting limit. Try again tomorrow.',
            'spam_risk_too_many_pending_share' => 'This TikTok account already has 5 drafts waiting in its inbox (TikTok\'s 24-hour limit). Finish or discard them in the TikTok app, then retry.',
            'spam_risk_user_banned_from_posting' => 'TikTok has blocked this account from posting.',
            'reached_active_user_cap' => 'This TikTok app has reached its daily quota of publishing users. Try again tomorrow.',
            'unaudited_client_can_only_post_to_private_accounts' => 'This TikTok app has not passed TikTok\'s audit yet, so it can only post to private accounts with "Only me" visibility.',
            'privacy_level_option_mismatch' => 'TikTok no longer allows the visibility chosen for this post. Reopen the post and pick a visibility again.',
            'url_ownership_unverified' => 'TikTok would not fetch the photos because this app\'s domain is not verified. Add and verify it under URL Properties in the TikTok developer portal.',
            'duration_check_failed' => 'The video is longer than this TikTok account is allowed to post.',
            'file_format_check_failed' => 'TikTok rejected the media format.',
            'picture_size_check_failed' => 'TikTok rejected one of the photos for its size or resolution.',
            'video_pull_failed', 'photo_pull_failed' => 'TikTok could not download the media from this app. Check that the app URL is publicly reachable.',
            default => $fallback !== '' ? $fallback : 'TikTok rejected the request.',
        };
    }

    /**
     * Mirrors MapsHttpErrors::classifyStatus. Duplicated deliberately rather than
     * pulled in as a trait: this class is a pure function of the response body and
     * has no Response to hand, and a trait calling into another trait's methods
     * cannot be analysed by PHPStan without both being composed into the caller.
     */
    private static function classifyStatus(int $status): ErrorKind
    {
        return match (true) {
            $status === 429 => ErrorKind::RateLimited,
            $status === 401 => ErrorKind::AuthExpired,
            $status === 403, $status === 422, $status === 400 => ErrorKind::Validation,
            $status >= 500 => ErrorKind::ServerError,
            default => ErrorKind::Unknown,
        };
    }
}
