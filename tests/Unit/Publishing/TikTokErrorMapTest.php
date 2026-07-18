<?php

declare(strict_types=1);

use App\Enums\ErrorKind;
use App\Services\Publishing\Connectors\TikTokErrorMap;

test('spam_risk_too_many_pending_share is terminal Validation, never RateLimited', function (): void {
    // The trap: this is a throttle in spirit, but TikTok returns HTTP 403 for it,
    // not 429 — and it does not clear on a timer. It clears only when the creator
    // opens the TikTok app and empties their inbox of pending drafts. Classifying
    // it as RateLimited would send the job into a retry/backoff loop against a
    // rejection that is deterministic until a human acts.
    expect(TikTokErrorMap::classify('spam_risk_too_many_pending_share', 403))
        ->toBe(ErrorKind::Validation)
        ->not->toBe(ErrorKind::RateLimited);

    expect(ErrorKind::Validation->isRetryable())->toBeFalse();

    // Even if TikTok were to send it with a 429 status, the body's code wins.
    expect(TikTokErrorMap::classify('spam_risk_too_many_pending_share', 429))
        ->toBe(ErrorKind::Validation);
});

test('rate_limit_exceeded is the only code that maps to RateLimited', function (): void {
    // A genuine 1-minute sliding window, so backing off actually helps.
    expect(TikTokErrorMap::classify('rate_limit_exceeded', 429))->toBe(ErrorKind::RateLimited);

    $everyOtherCode = [
        'access_token_invalid',
        'scope_not_authorized',
        'scope_permission_missed',
        'spam_risk_too_many_posts',
        'spam_risk_too_many_pending_share',
        'spam_risk_user_banned_from_posting',
        'reached_active_user_cap',
        'unaudited_client_can_only_post_to_private_accounts',
        'privacy_level_option_mismatch',
        'url_ownership_unverified',
        'invalid_param',
        'invalid_publish_id',
        'token_not_authorized_for_specified_publish_id',
        'file_format_check_failed',
        'duration_check_failed',
        'frame_rate_check_failed',
        'picture_size_check_failed',
        'video_pull_failed',
        'photo_pull_failed',
        'internal_error',
    ];

    foreach ($everyOtherCode as $code) {
        // Status 403 deliberately, since that is what TikTok sends for the spam
        // family: no other documented code may be lifted into RateLimited.
        expect(TikTokErrorMap::classify($code, 403))->not->toBe(ErrorKind::RateLimited);
    }
});

test('the spam and cap ceilings are all terminal and never retried', function (string $code): void {
    // These reset on a daily cadence or need a human action. Retrying inside the
    // job's backoff budget can only burn attempts — and for video, re-upload
    // gigabytes — against a rejection that holds for hours.
    $kind = TikTokErrorMap::classify($code, 403);

    expect($kind)->toBe(ErrorKind::Validation)
        ->and($kind->isRetryable())->toBeFalse();
})->with([
    'spam_risk_too_many_posts',
    'spam_risk_too_many_pending_share',
    'spam_risk_user_banned_from_posting',
    'reached_active_user_cap',
]);

test('token and scope failures map to AuthExpired', function (string $code): void {
    // Both need a reconnect rather than a retry.
    expect(TikTokErrorMap::classify($code, 401))->toBe(ErrorKind::AuthExpired);
})->with([
    'access_token_invalid',
    'scope_not_authorized',
    'scope_permission_missed',
]);

test('url_ownership_unverified is Validation and tells the user to verify the domain', function (): void {
    // Photos are PULL_FROM_URL only, so TikTok refuses to fetch them until the
    // app's domain is verified in the developer portal — nothing a retry fixes.
    expect(TikTokErrorMap::classify('url_ownership_unverified', 403))->toBe(ErrorKind::Validation);

    expect(TikTokErrorMap::message('url_ownership_unverified', ''))
        ->toContain('domain')
        ->toContain('verif');
});

test('internal_error is a retryable ServerError', function (): void {
    $kind = TikTokErrorMap::classify('internal_error', 500);

    expect($kind)->toBe(ErrorKind::ServerError)
        ->and($kind->isRetryable())->toBeTrue();
});

test('an unknown code falls back to the http status', function (int $status, ErrorKind $expected): void {
    // Codes TikTok has not documented (or transport-level failures) are the only
    // place the status is consulted, so an unrecognised 5xx still retries and an
    // unrecognised 4xx stays terminal.
    expect(TikTokErrorMap::classify('some_code_tiktok_invented_yesterday', $status))->toBe($expected);
})->with([
    'server error retries' => [500, ErrorKind::ServerError],
    'bad gateway retries' => [502, ErrorKind::ServerError],
    'bad request is terminal' => [400, ErrorKind::Validation],
    'forbidden is terminal' => [403, ErrorKind::Validation],
    'unprocessable is terminal' => [422, ErrorKind::Validation],
    'too many requests backs off' => [429, ErrorKind::RateLimited],
    'unauthorized needs a reconnect' => [401, ErrorKind::AuthExpired],
    'anything else is unknown' => [418, ErrorKind::Unknown],
]);

test('isOk accepts the success sentinel and an absent code', function (): void {
    // Some endpoints omit the envelope on success, so an absent or empty code is
    // treated as OK and the caller's own field checks catch a malformed body.
    expect(TikTokErrorMap::isOk('ok'))->toBeTrue()
        ->and(TikTokErrorMap::isOk(TikTokErrorMap::OK))->toBeTrue()
        ->and(TikTokErrorMap::isOk(null))->toBeTrue()
        ->and(TikTokErrorMap::isOk(''))->toBeTrue();
});

test('isOk rejects anything else', function (string $code): void {
    expect(TikTokErrorMap::isOk($code))->toBeFalse();
})->with([
    'spam_risk_too_many_posts',
    'rate_limit_exceeded',
    'internal_error',
    'OK',
    'okay',
    'ok ',
]);

test('message returns purpose-written copy for known codes', function (string $code, string $needle): void {
    // TikTok's own error.message is terse or internal ("param error"), so the
    // documented codes get copy the user can act on. The fallback must be ignored.
    expect(TikTokErrorMap::message($code, 'param error'))
        ->toContain($needle)
        ->not->toBe('param error');
})->with([
    'token' => ['access_token_invalid', 'Reconnect the account'],
    'scope' => ['scope_not_authorized', 'permissions'],
    'daily cap' => ['spam_risk_too_many_posts', 'daily posting limit'],
    'pending inbox' => ['spam_risk_too_many_pending_share', 'drafts waiting'],
    'banned' => ['spam_risk_user_banned_from_posting', 'blocked this account'],
    'user cap' => ['reached_active_user_cap', 'daily quota'],
    'unaudited' => ['unaudited_client_can_only_post_to_private_accounts', 'audit'],
    'privacy mismatch' => ['privacy_level_option_mismatch', 'visibility'],
    'duration' => ['duration_check_failed', 'longer than'],
    'format' => ['file_format_check_failed', 'media format'],
    'picture size' => ['picture_size_check_failed', 'size or resolution'],
    'video pull' => ['video_pull_failed', 'publicly reachable'],
    'photo pull' => ['photo_pull_failed', 'publicly reachable'],
]);

test('message falls back to TikTok text for an unknown code', function (): void {
    expect(TikTokErrorMap::message('brand_new_code', 'something specific went wrong'))
        ->toBe('something specific went wrong');
});

test('message uses generic copy when an unknown code carries no text', function (): void {
    expect(TikTokErrorMap::message('brand_new_code', ''))->toBe('TikTok rejected the request.');
});
