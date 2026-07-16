<?php

declare(strict_types=1);

use App\Dto\Publishing\PublishContext;
use App\Enums\ErrorKind;
use App\Enums\Platform;
use App\Enums\TikTokPostMode;
use App\Enums\TikTokPrivacyLevel;
use App\Models\ConnectedAccount;
use App\Models\PostMedia;
use App\Models\PostTarget;
use App\Services\Publishing\Connectors\TikTokConnector;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

beforeEach(function (): void {
    Storage::fake('public');

    // Any request these tests do not explicitly fake is a bug in the test, not a
    // request to let out to the real TikTok: without this, a mistyped url pattern
    // would silently fall through to the network and still "pass".
    Http::preventStrayRequests();
});

function tiktokUrl(string $path): string
{
    return 'https://open.tiktokapis.com/v2'.$path;
}

/**
 * TikTok wraps every v2 response — success and failure alike — as
 * {data, error:{code, message, log_id}}, where error.code is 'ok' on success.
 *
 * @param  array<string, mixed>  $data
 * @return array<string, mixed>
 */
function tiktokEnvelope(array $data = [], string $code = 'ok', string $message = ''): array
{
    return [
        'data' => $data,
        'error' => ['code' => $code, 'message' => $message, 'log_id' => 'log-abc123'],
    ];
}

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function tiktokCreatorInfo(array $overrides = []): array
{
    return tiktokEnvelope(array_merge([
        'creator_username' => 'a-creator',
        'creator_nickname' => 'A Creator',
        'privacy_level_options' => ['PUBLIC_TO_EVERYONE', 'MUTUAL_FOLLOW_FRIENDS', 'SELF_ONLY'],
        'comment_disabled' => false,
        'max_video_post_duration_sec' => 600,
    ], $overrides));
}

/**
 * @param  list<PostMedia>  $media
 * @param  array<string, mixed>  $targetOverrides
 */
function tiktokContext(array $media = [], array $targetOverrides = [], string $token = 'tt-token'): PublishContext
{
    $account = ConnectedAccount::factory()->create([
        'platform' => Platform::TikTok->value,
        'remote_account_id' => 'tt-123',
    ]);

    $target = PostTarget::factory()->for($account, 'account')->create(array_merge([
        'platform' => Platform::TikTok->value,
        'tiktok_post_mode' => TikTokPostMode::DirectPost->value,
        'tiktok_privacy_level' => TikTokPrivacyLevel::PublicToEveryone->value,
    ], $targetOverrides));

    return new PublishContext(
        target: $target,
        segments: ['hello tiktok'],
        media: $media,
        account: $account,
        credentials: ['access_token' => $token],
    );
}

/** A video small enough to be a single chunk, with size_bytes matching the bytes on disk. */
function tiktokVideo(int $sizeBytes = 2048, int $durationSeconds = 30): PostMedia
{
    Storage::disk('public')->put('media/clip.mp4', str_repeat('v', $sizeBytes));

    return PostMedia::factory()->video()->create([
        'disk' => 'public',
        'path' => 'media/clip.mp4',
        'mime' => 'video/mp4',
        'size_bytes' => $sizeBytes,
        'duration_seconds' => $durationSeconds,
    ]);
}

function tiktokPhoto(string $path): PostMedia
{
    Storage::disk('public')->put($path, 'jpeg-bytes');

    return PostMedia::factory()->create([
        'disk' => 'public',
        'path' => $path,
        'mime' => 'image/jpeg',
        'size_bytes' => 1024,
    ]);
}

test('tiktok direct-posts a video: creator_info, chunked upload, then a completed poll', function (): void {
    Http::fake([
        tiktokUrl('/post/publish/creator_info/query/') => Http::response(tiktokCreatorInfo()),
        tiktokUrl('/post/publish/video/init/') => Http::response(tiktokEnvelope([
            'publish_id' => 'pub-video-1',
            'upload_url' => 'https://open-upload.tiktokapis.com/video/?upload_id=u1',
        ])),
        'https://open-upload.tiktokapis.com/*' => Http::response('', 201),
        tiktokUrl('/post/publish/status/fetch/') => Http::response(tiktokEnvelope([
            'status' => 'PUBLISH_COMPLETE',
            'publicaly_available_post_id' => ['7300000000000000001'],
        ])),
    ]);

    $result = app(TikTokConnector::class)->publish(tiktokContext([tiktokVideo()]));

    expect($result->isSuccessful())->toBeTrue()
        ->and($result->remoteIds)->toBe(['7300000000000000001']);

    Http::assertSent(fn ($request): bool => $request->url() === tiktokUrl('/post/publish/creator_info/query/'));

    Http::assertSent(fn ($request): bool => $request->url() === tiktokUrl('/post/publish/video/init/')
        && $request['source_info']['source'] === 'FILE_UPLOAD'
        && $request['source_info']['video_size'] === 2048
        && $request['source_info']['total_chunk_count'] === 1
        && $request['post_info']['privacy_level'] === 'PUBLIC_TO_EVERYONE'
        && $request['post_info']['title'] === 'hello tiktok');

    // The 2 KiB file is a single chunk, PUT to the url init handed back, with an
    // inclusive last byte.
    Http::assertSent(fn ($request): bool => $request->method() === 'PUT'
        && str_starts_with($request->url(), 'https://open-upload.tiktokapis.com/')
        && $request->hasHeader('Content-Range', 'bytes 0-2047/2048'));

    Http::assertSent(fn ($request): bool => $request->url() === tiktokUrl('/post/publish/status/fetch/')
        && $request['publish_id'] === 'pub-video-1');
});

test('tiktok drafts a video to the inbox endpoint with no post_info and treats the inbox as terminal success', function (): void {
    Http::fake([
        tiktokUrl('/post/publish/inbox/video/init/') => Http::response(tiktokEnvelope([
            'publish_id' => 'pub-inbox-1',
            'upload_url' => 'https://open-upload.tiktokapis.com/video/?upload_id=u2',
        ])),
        'https://open-upload.tiktokapis.com/*' => Http::response('', 201),
        tiktokUrl('/post/publish/status/fetch/') => Http::response(tiktokEnvelope([
            'status' => 'SEND_TO_USER_INBOX',
        ])),
    ]);

    $result = app(TikTokConnector::class)->publish(tiktokContext(
        [tiktokVideo()],
        ['tiktok_post_mode' => TikTokPostMode::InboxDraft->value],
    ));

    // A draft waits in the creator's app and will never reach PUBLISH_COMPLETE on
    // its own, so this status is as finished as an inbox upload ever gets.
    expect($result->isSuccessful())->toBeTrue()
        ->and($result->remoteIds)->toBe(['pub-inbox-1']);

    // The inbox endpoint takes source_info alone: the creator picks privacy and
    // interaction settings themselves when they finish the post.
    Http::assertSent(fn ($request): bool => $request->url() === tiktokUrl('/post/publish/inbox/video/init/')
        && $request['source_info']['source'] === 'FILE_UPLOAD'
        && ! isset($request['post_info']));

    Http::assertNotSent(fn ($request): bool => $request->url() === tiktokUrl('/post/publish/video/init/'));

    // No privacy choice to re-check, so creator_info is not consulted either.
    Http::assertNotSent(fn ($request): bool => $request->url() === tiktokUrl('/post/publish/creator_info/query/'));
});

test('tiktok direct-posts photos through content/init with PULL_FROM_URL', function (): void {
    Http::fake([
        tiktokUrl('/post/publish/creator_info/query/') => Http::response(tiktokCreatorInfo()),
        tiktokUrl('/post/publish/content/init/') => Http::response(tiktokEnvelope(['publish_id' => 'pub-photo-1'])),
        tiktokUrl('/post/publish/status/fetch/') => Http::response(tiktokEnvelope([
            'status' => 'PUBLISH_COMPLETE',
            'publicaly_available_post_id' => ['7300000000000000002'],
        ])),
    ]);

    $result = app(TikTokConnector::class)->publish(tiktokContext([
        tiktokPhoto('media/a.jpg'),
        tiktokPhoto('media/b.jpg'),
    ]));

    expect($result->isSuccessful())->toBeTrue()
        ->and($result->remoteIds)->toBe(['7300000000000000002']);

    Http::assertSent(function ($request): bool {
        if ($request->url() !== tiktokUrl('/post/publish/content/init/')) {
            return false;
        }

        $images = $request['source_info']['photo_images'];

        return $request['media_type'] === 'PHOTO'
            && $request['post_mode'] === 'DIRECT_POST'
            && $request['source_info']['source'] === 'PULL_FROM_URL'
            && count($images) === 2
            && str_contains($images[0], 'a.jpg')
            && str_contains($images[1], 'b.jpg');
    });

    // Photos have no byte-upload path at all — TikTok fetches them from us.
    Http::assertNotSent(fn ($request): bool => $request->method() === 'PUT');
});

test('tiktok reports MediaProcessing with a retryAfter while TikTok is still processing', function (): void {
    Http::fake([
        tiktokUrl('/post/publish/creator_info/query/') => Http::response(tiktokCreatorInfo()),
        tiktokUrl('/post/publish/video/init/') => Http::response(tiktokEnvelope([
            'publish_id' => 'pub-video-2',
            'upload_url' => 'https://open-upload.tiktokapis.com/video/?upload_id=u3',
        ])),
        'https://open-upload.tiktokapis.com/*' => Http::response('', 201),
        tiktokUrl('/post/publish/status/fetch/') => Http::response(tiktokEnvelope([
            'status' => 'PROCESSING_UPLOAD',
        ])),
    ]);

    $context = tiktokContext([tiktokVideo()]);
    $result = app(TikTokConnector::class)->publish($context);

    // Re-queue the job rather than fail it, and do it without burning an attempt.
    expect($result->isSuccessful())->toBeFalse()
        ->and($result->errorKind)->toBe(ErrorKind::MediaProcessing)
        ->and($result->errorKind->isRetryable())->toBeTrue()
        ->and($result->retryAfter)->toBe(6);

    // The handle is persisted, so the retry resumes rather than re-uploading.
    $state = $context->target->fresh()->media_upload_state;
    expect($state['publish']['remote_ref'])->toBe('pub-video-2');
});

test('tiktok treats a FAILED publish status as a terminal validation failure', function (): void {
    Http::fake([
        tiktokUrl('/post/publish/creator_info/query/') => Http::response(tiktokCreatorInfo()),
        tiktokUrl('/post/publish/video/init/') => Http::response(tiktokEnvelope([
            'publish_id' => 'pub-video-3',
            'upload_url' => 'https://open-upload.tiktokapis.com/video/?upload_id=u4',
        ])),
        'https://open-upload.tiktokapis.com/*' => Http::response('', 201),
        tiktokUrl('/post/publish/status/fetch/') => Http::response(tiktokEnvelope([
            'status' => 'FAILED',
            'fail_reason' => 'file_format_check_failed',
        ])),
    ]);

    $result = app(TikTokConnector::class)->publish(tiktokContext([tiktokVideo()]));

    expect($result->isSuccessful())->toBeFalse()
        ->and($result->errorKind)->toBe(ErrorKind::Validation)
        ->and($result->errorKind->isRetryable())->toBeFalse()
        // fail_reason is run through the error map, not surfaced raw.
        ->and($result->errorMessage)->toContain('media format');
});

test('tiktok refuses a direct post whose visibility creator_info no longer allows', function (): void {
    // The composer captured the creator's choice when the post was written; by the
    // time the scheduler publishes, the account may have gone private. Posting a
    // no-longer-permitted level fails with privacy_level_option_mismatch, so the
    // live options are re-checked first.
    Http::fake([
        tiktokUrl('/post/publish/creator_info/query/') => Http::response(tiktokCreatorInfo([
            'privacy_level_options' => ['SELF_ONLY'],
        ])),
    ]);

    $result = app(TikTokConnector::class)->publish(tiktokContext([tiktokVideo()]));

    expect($result->isSuccessful())->toBeFalse()
        ->and($result->errorKind)->toBe(ErrorKind::Validation)
        ->and($result->errorMessage)->toContain('Everyone');

    // creator_info only: init is never reached.
    Http::assertSentCount(1);
    Http::assertNotSent(fn ($request): bool => str_contains($request->url(), '/init/'));
});

test('tiktok refuses a direct post with no visibility chosen and never calls the api', function (): void {
    // tiktok_privacy_level is nullable by design: TikTok's guidelines require the
    // dropdown to start unselected, so "not chosen" must be caught here.
    Http::fake();

    $result = app(TikTokConnector::class)->publish(tiktokContext(
        [tiktokVideo()],
        ['tiktok_privacy_level' => null],
    ));

    expect($result->isSuccessful())->toBeFalse()
        ->and($result->errorKind)->toBe(ErrorKind::Validation)
        ->and($result->errorMessage)->toContain('no visibility set');

    Http::assertNothingSent();
});

test('tiktok treats a 200 creator_info carrying a spam code in the body as a failure', function (): void {
    // The endpoint-dependent trap: /video/init/ returns HTTP 403 for this exact
    // condition, but creator_info returns HTTP 200 with the code in the error
    // envelope. Status-driven code would read this 200 as success and post anyway.
    Http::fake([
        tiktokUrl('/post/publish/creator_info/query/') => Http::response(
            tiktokEnvelope(code: 'spam_risk_too_many_posts', message: 'spam risk'),
            200,
        ),
    ]);

    $result = app(TikTokConnector::class)->publish(tiktokContext([tiktokVideo()]));

    expect($result->isSuccessful())->toBeFalse()
        ->and($result->errorKind)->toBe(ErrorKind::Validation)
        ->and($result->errorKind->isRetryable())->toBeFalse()
        ->and($result->errorMessage)->toContain('daily posting limit')
        ->and($result->httpStatus)->toBe(200);

    Http::assertNotSent(fn ($request): bool => str_contains($request->url(), '/init/'));
});

test('tiktok refuses branded content on a private post without calling the api', function (): void {
    // The composer blocks this, but the two settings can be changed independently,
    // so it is re-checked rather than trusted.
    Http::fake();

    $result = app(TikTokConnector::class)->publish(tiktokContext([tiktokVideo()], [
        'tiktok_privacy_level' => TikTokPrivacyLevel::SelfOnly->value,
        'tiktok_brand_content_toggle' => true,
    ]));

    expect($result->isSuccessful())->toBeFalse()
        ->and($result->errorKind)->toBe(ErrorKind::Validation)
        ->and($result->errorMessage)->toContain('branded content');

    Http::assertNothingSent();
});

test('tiktok refuses a video longer than the creator is allowed to post', function (): void {
    Http::fake([
        tiktokUrl('/post/publish/creator_info/query/') => Http::response(tiktokCreatorInfo([
            'max_video_post_duration_sec' => 60,
        ])),
    ]);

    $result = app(TikTokConnector::class)->publish(tiktokContext([tiktokVideo(durationSeconds: 120)]));

    expect($result->isSuccessful())->toBeFalse()
        ->and($result->errorKind)->toBe(ErrorKind::Validation)
        ->and($result->errorMessage)->toContain('up to 60 seconds');

    Http::assertSentCount(1);
    Http::assertNotSent(fn ($request): bool => str_contains($request->url(), '/init/'));
});

test('tiktok refuses to re-init photos a crashed attempt may already have posted', function (): void {
    // A photo init commits the post the instant it returns 200. If the worker died
    // between that response and the save recording the publish_id, re-initing would
    // double-post to a platform with no delete api.
    Http::fake();

    $result = app(TikTokConnector::class)->publish(tiktokContext([tiktokPhoto('media/a.jpg')], [
        'media_upload_state' => ['photo_init_started' => ['remote_ref' => 'started', 'state' => 'processing']],
    ]));

    expect($result->isSuccessful())->toBeFalse()
        ->and($result->errorKind)->toBe(ErrorKind::Unknown)
        ->and($result->errorMessage)->toContain('may already be on TikTok');

    Http::assertNothingSent();
});

test('tiktok returns success for a target that already has a remote id without calling the api', function (): void {
    Http::fake();

    $result = app(TikTokConnector::class)->publish(tiktokContext([tiktokVideo()], [
        'remote_id' => '7300000000000000009',
        'remote_ids' => ['7300000000000000009'],
    ]));

    expect($result->isSuccessful())->toBeTrue()
        ->and($result->remoteIds)->toBe(['7300000000000000009']);

    Http::assertNothingSent();
});

test('tiktok fails with AuthExpired when the access token is empty', function (): void {
    Http::fake();

    $result = app(TikTokConnector::class)->publish(tiktokContext([tiktokVideo()], token: ''));

    expect($result->isSuccessful())->toBeFalse()
        ->and($result->errorKind)->toBe(ErrorKind::AuthExpired);

    Http::assertNothingSent();
});

test('tiktok fails validation with no media and makes no http calls', function (): void {
    Http::fake();

    $result = app(TikTokConnector::class)->publish(tiktokContext());

    expect($result->isSuccessful())->toBeFalse()
        ->and($result->errorKind)->toBe(ErrorKind::Validation);

    Http::assertNothingSent();
});

test('tiktok delete is a no-op because TikTok exposes no delete endpoint', function (): void {
    // Throwing would make DeletePostTarget retry for ~20 minutes against an api
    // that will never accept the call. The local record goes; the post stays live.
    Http::fake();

    $target = tiktokContext([])->target;

    app(TikTokConnector::class)->delete($target, ['access_token' => 'tt-token']);

    Http::assertNothingSent();
});
