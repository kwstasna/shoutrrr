<?php

declare(strict_types=1);

namespace App\Services\Publishing\Connectors;

use App\Dto\Publishing\MediaUploadState;
use App\Dto\Publishing\PublishContext;
use App\Dto\Publishing\PublishResult;
use App\Dto\Publishing\TikTokChunkPlan;
use App\Enums\ErrorKind;
use App\Enums\Platform;
use App\Enums\TikTokPrivacyLevel;
use App\Enums\UsageCategory;
use App\Models\PostMedia;
use App\Models\PostTarget;
use App\Services\Media\TikTokImageRendition;
use App\Services\Publishing\Connectors\Concerns\MapsHttpErrors;
use App\Services\Publishing\Contracts\PublishConnector;
use App\Services\Usage\Concerns\TracksUsage;
use App\Support\UsageOperation;
use GuzzleHttp\Psr7\LimitStream;
use GuzzleHttp\Psr7\Utils;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

/**
 * Publishes to TikTok via the Content Posting API's async init → transfer → poll
 * choreography, across four paths: {direct post, inbox draft} × {video, photo}.
 *
 * Shape mirrors InstagramConnector — create a remote handle, persist it so a job
 * retry resumes instead of restarting, poll until the platform finishes, and
 * report MediaProcessing to be re-queued while it works. TikTok's handle is a
 * `publish_id` rather than a container id, and it is stored in the same
 * MediaUploadState JSON.
 *
 * Two things differ from every other connector here and drive the design:
 *
 *  1. VIDEO and PHOTO transfer bytes completely differently. Video uses chunked
 *     FILE_UPLOAD (we push bytes out, so nothing about our hosting matters).
 *     Photos have NO byte-upload path at all — they are PULL_FROM_URL only, so
 *     TikTok fetches them from us and the app's domain must be verified in the
 *     TikTok portal.
 *  2. A DIRECT POST must re-check Query Creator Info immediately before
 *     publishing. The composer captured the creator's choices when the post was
 *     written, but this is a scheduler: by the time it publishes, the creator's
 *     allowed privacy levels or max duration may have changed. Publishing a
 *     no-longer-permitted level fails with privacy_level_option_mismatch, so we
 *     check first and fail with something the user can act on.
 */
class TikTokConnector implements PublishConnector
{
    use MapsHttpErrors, TracksUsage;

    private const string API_BASE = 'https://open.tiktokapis.com/v2';

    /**
     * MediaUploadState key for the publish handle. The whole post shares one
     * handle (unlike Instagram's per-child carousel containers), so this is a
     * fixed pseudo media-id rather than a real one.
     */
    private const string PUBLISH_KEY = 'publish';

    /**
     * Write-ahead marker set immediately before a PHOTO init. See guardOrphanedPhotoInit().
     */
    private const string PHOTO_INIT_MARKER = 'photo_init_started';

    /**
     * Per-chunk HTTP timeout. The Laravel client defaults to 30s, which a 10 MiB
     * chunk only meets above ~350 KB/s — below that every upload would throw
     * ConnectionException and burn the target's retry budget on a link that is
     * merely slow rather than broken.
     */
    private const int CHUNK_TIMEOUT_SECONDS = 120;

    /**
     * How long one job run will spend pushing chunks before parking the upload and
     * resuming on the next run. PublishPostTarget::$timeout is 900s, so this plus
     * one in-flight chunk (600 + 120 = 720) stays under it with room for the init
     * and poll calls either side. Without this, a large video on a slow link would
     * be killed mid-transfer by the queue's timeout instead of resuming cleanly.
     */
    private const int UPLOAD_BUDGET_SECONDS = 600;

    /** Poll spacing while TikTok processes, matching InstagramConnector's cadence. */
    private const int PROCESSING_RETRY_AFTER = 6;

    public function __construct(
        private readonly HttpFactory $http,
        private readonly TikTokImageRendition $imageRendition,
    ) {}

    public function publish(PublishContext $context): PublishResult
    {
        $token = (string) ($context->credentials['access_token'] ?? '');

        if ($token === '') {
            return PublishResult::failure(ErrorKind::AuthExpired, 'TikTok access token unavailable; reconnect the account.');
        }

        if (($context->target->remote_id ?? null) !== null) {
            return PublishResult::success($context->target->remote_ids ?? [$context->target->remote_id]);
        }

        if ($context->media === []) {
            return PublishResult::failure(ErrorKind::Validation, 'TikTok requires a video or at least one photo.');
        }

        $state = new MediaUploadState($context->target->media_upload_state);
        $isVideo = $context->media[0]->isVideo();

        try {
            $publishId = $state->remoteRef(self::PUBLISH_KEY);

            if ($publishId === null) {
                $orphaned = $this->guardOrphanedPhotoInit($state, $isVideo);
                if ($orphaned !== null) {
                    return $orphaned;
                }

                $blocked = $this->revalidateAgainstCreatorInfo($context, $token, $isVideo);
                if ($blocked !== null) {
                    return $blocked;
                }

                $publishId = $isVideo
                    ? $this->initVideo($context, $state, $token)
                    : $this->initPhoto($context, $state, $token);
            }

            if ($isVideo) {
                // Resumes at the next un-sent chunk; a no-op once they are all up.
                $parked = $this->transferChunks($context, $state, $token);
                if ($parked !== null) {
                    return $parked;
                }
            }

            return $this->pollStatus($context, $publishId, $token);
        } catch (TikTokRequestFailed $e) {
            return $this->mapFailure($e->response);
        } catch (ConnectionException $e) {
            return PublishResult::failure(ErrorKind::Network, $e->getMessage());
        }
    }

    /**
     * Refuse to re-init a photo post that may already be live.
     *
     * A photo init COMMITS the post the instant it returns 200 — for a direct post
     * it is published, for an inbox draft it consumes one of the creator's five
     * pending shares. If the worker dies between that response and the save that
     * records the publish_id, a naive retry would init again and double-post to a
     * platform with no delete API. So a marker is written before the call: marker
     * present but no publish_id means we cannot prove the first init didn't land,
     * and guessing wrong is unrecoverable.
     *
     * Video needs no such guard, which is why this is photo-only: an orphaned
     * video publish_id that never received its bytes is inert and simply expires.
     */
    private function guardOrphanedPhotoInit(MediaUploadState $state, bool $isVideo): ?PublishResult
    {
        if ($isVideo || $state->remoteRef(self::PHOTO_INIT_MARKER) === null) {
            return null;
        }

        return PublishResult::failure(
            ErrorKind::Unknown,
            'A previous attempt started posting these photos to TikTok but was interrupted before it could be recorded, '
            .'so they may already be on TikTok. Check the account and delete this post here if it published.',
        );
    }

    /**
     * Re-check the creator's live constraints before a direct post.
     *
     * Returns a failure to surface, or null when the post may proceed. Inbox
     * drafts skip this: they carry no post_info, and the creator picks privacy and
     * interaction settings themselves when they finish the post in the app.
     */
    private function revalidateAgainstCreatorInfo(PublishContext $context, string $token, bool $isVideo): ?PublishResult
    {
        $target = $context->target;

        if (! $target->tiktok_post_mode->isDirect()) {
            return null;
        }

        $privacy = $target->tiktok_privacy_level;

        if (! $privacy instanceof TikTokPrivacyLevel) {
            return PublishResult::failure(
                ErrorKind::Validation,
                'This TikTok post has no visibility set. Open the post and choose who can see it.',
            );
        }

        // Branded content may not be private. The composer blocks this, but the
        // two settings can be changed independently, so it is re-checked here
        // rather than trusted.
        if ($target->tiktok_brand_content_toggle && $privacy->isPrivate()) {
            return PublishResult::failure(
                ErrorKind::Validation,
                'TikTok does not allow branded content to be private. Change the visibility or turn off "Branded content".',
            );
        }

        $response = $this->http->withToken($token)
            ->post(self::API_BASE.'/post/publish/creator_info/query/');

        $this->meter(UsageCategory::Publish, UsageOperation::CREATOR_INFO_QUERY, $context->account, $response);

        // creator_info reports the spam/cap codes as HTTP 200 with the code in the
        // body, so a status check alone would read this as success.
        $failure = $this->failureFor($response);
        if ($failure !== null) {
            return $failure;
        }

        /** @var list<string> $allowed */
        $allowed = (array) ($response->json('data.privacy_level_options') ?? []);

        if (! in_array($privacy->value, $allowed, true)) {
            return PublishResult::failure(
                ErrorKind::Validation,
                "TikTok no longer allows this post's visibility ({$privacy->label()}) for this account. "
                .'Open the post and choose a visibility again.',
            );
        }

        if ($isVideo) {
            $maxDuration = (int) ($response->json('data.max_video_post_duration_sec') ?? 0);
            $duration = (int) ($context->media[0]->duration_seconds ?? 0);

            if ($maxDuration > 0 && $duration > $maxDuration) {
                return PublishResult::failure(
                    ErrorKind::Validation,
                    "This TikTok account can post videos up to {$maxDuration} seconds; this one is {$duration}.",
                );
            }
        }

        return null;
    }

    /**
     * Start a video upload and record its handle before a single byte is sent, so
     * a crash mid-transfer resumes against the same publish_id rather than
     * starting a second one.
     */
    private function initVideo(PublishContext $context, MediaUploadState $state, string $token): string
    {
        $media = $context->media[0];
        $plan = TikTokChunkPlan::for($this->sizeOf($media));

        $body = [
            'source_info' => [
                'source' => 'FILE_UPLOAD',
                'video_size' => $plan->totalBytes,
                'chunk_size' => $plan->chunkSize,
                'total_chunk_count' => $plan->totalChunks,
            ],
        ];

        // Only a direct post carries post_info; the inbox endpoint takes
        // source_info alone.
        if ($context->target->tiktok_post_mode->isDirect()) {
            $body['post_info'] = $this->videoPostInfo($context);
        }

        $endpoint = $context->target->tiktok_post_mode->isDirect()
            ? '/post/publish/video/init/'
            : '/post/publish/inbox/video/init/';

        $response = $this->http->withToken($token)->asJson()->post(self::API_BASE.$endpoint, $body);

        $this->meter(UsageCategory::Publish, UsageOperation::MEDIA_UPLOAD, $context->account, $response);

        $this->throwUnlessOk($response);

        $publishId = (string) $response->json('data.publish_id');
        $uploadUrl = (string) $response->json('data.upload_url');

        if ($publishId === '' || $uploadUrl === '') {
            throw new TikTokRequestFailed($response);
        }

        $state->markUploaded(self::PUBLISH_KEY, $publishId);
        $state->setBlob(self::PUBLISH_KEY, [
            ...$plan->toBlob(),
            'upload_url' => $uploadUrl,
            'next_chunk' => 0,
        ]);
        $this->persistState($context, $state);

        return $publishId;
    }

    /**
     * Push the video's bytes chunk by chunk, resuming where a previous run left
     * off. Returns a MediaProcessing result when the time budget runs out (the
     * caller re-queues), or null once every chunk is up.
     */
    private function transferChunks(PublishContext $context, MediaUploadState $state, string $token): ?PublishResult
    {
        $blob = $state->blob(self::PUBLISH_KEY);
        $plan = TikTokChunkPlan::fromBlob($blob);
        $uploadUrl = (string) ($blob['upload_url'] ?? '');

        // No plan means the handle came from somewhere that never uploaded bytes;
        // nothing to transfer.
        if (! $plan instanceof TikTokChunkPlan || $uploadUrl === '') {
            return null;
        }

        $next = (int) ($blob['next_chunk'] ?? 0);

        if ($next >= $plan->totalChunks) {
            return null;
        }

        $media = $context->media[0];
        $disk = Storage::disk($media->disk);
        $startedAt = microtime(true);

        for ($index = $next; $index < $plan->totalChunks; $index++) {
            if (microtime(true) - $startedAt > self::UPLOAD_BUDGET_SECONDS) {
                // Park before the queue's own timeout can kill us mid-chunk.
                return PublishResult::failure(
                    ErrorKind::MediaProcessing,
                    'Still uploading the video to TikTok.',
                    retryAfter: 1,
                );
            }

            $range = $plan->range($index);

            // Wrap the disk resource as a PSR-7 stream and hand Guzzle a window
            // onto it, so only the current chunk is ever read and the whole file is
            // never resident. LimitStream::getSize() also gives Guzzle a correct
            // Content-Length for free.
            $stream = $disk->readStream($media->path);

            try {
                $chunk = new LimitStream(Utils::streamFor($stream), $range['length'], $range['offset']);

                $response = $this->http
                    ->timeout(self::CHUNK_TIMEOUT_SECONDS)
                    ->withHeaders(['Content-Range' => $plan->contentRange($index)])
                    ->withBody($chunk, $media->mime)
                    ->put($uploadUrl);
            } finally {
                if (is_resource($stream)) {
                    fclose($stream);
                }
            }

            $this->meter(UsageCategory::Publish, UsageOperation::MEDIA_UPLOAD, $context->account, $response);

            if ($response->failed()) {
                throw new TikTokRequestFailed($response);
            }

            // Record progress after every chunk: TikTok requires them in order, so
            // resume has to know exactly where it stopped.
            $state->setBlob(self::PUBLISH_KEY, [...$state->blob(self::PUBLISH_KEY), 'next_chunk' => $index + 1]);
            $this->persistState($context, $state);
        }

        return null;
    }

    /**
     * Create a photo post. Photos are PULL_FROM_URL only, so this hands TikTok a
     * list of URLs on our own domain and TikTok fetches them — which is why the
     * domain must be verified in the portal, and why the whole post commits at
     * init rather than after a separate transfer step.
     */
    private function initPhoto(PublishContext $context, MediaUploadState $state, string $token): string
    {
        $target = $context->target;
        $photos = array_slice($context->media, 0, Platform::TikTok->maxMedia());

        $urls = array_map(fn (PostMedia $media): string => $this->imageRendition->urlFor($media), $photos);

        $coverIndex = $target->tiktok_photo_cover_index ?? 0;

        $body = [
            'media_type' => 'PHOTO',
            'post_mode' => $target->tiktok_post_mode->photoPostMode(),
            'source_info' => [
                'source' => 'PULL_FROM_URL',
                'photo_cover_index' => min($coverIndex, count($urls) - 1),
                'photo_images' => $urls,
            ],
        ];

        if ($target->tiktok_post_mode->isDirect()) {
            $body['post_info'] = $this->photoPostInfo($context);
        }

        // Write-ahead marker: this call commits the post, so the fact that it was
        // attempted must survive a crash. See guardOrphanedPhotoInit().
        $state->markUploaded(self::PHOTO_INIT_MARKER, 'started');
        $this->persistState($context, $state);

        $response = $this->http->withToken($token)->asJson()
            ->post(self::API_BASE.'/post/publish/content/init/', $body);

        $this->meter(UsageCategory::Publish, UsageOperation::MEDIA_UPLOAD, $context->account, $response);

        $this->throwUnlessOk($response);

        $publishId = (string) $response->json('data.publish_id');

        if ($publishId === '') {
            throw new TikTokRequestFailed($response);
        }

        $state->markUploaded(self::PUBLISH_KEY, $publishId);
        $this->persistState($context, $state);

        return $publishId;
    }

    /**
     * @return array<string, mixed>
     */
    private function videoPostInfo(PublishContext $context): array
    {
        $target = $context->target;

        $info = [
            'title' => $this->caption($context, Platform::TikTok->maxLength()),
            'privacy_level' => $target->tiktok_privacy_level?->value,
            'disable_comment' => $target->tiktok_disable_comment,
            'disable_duet' => $target->tiktok_disable_duet,
            'disable_stitch' => $target->tiktok_disable_stitch,
            'brand_content_toggle' => $target->tiktok_brand_content_toggle,
            'brand_organic_toggle' => $target->tiktok_brand_organic_toggle,
        ];

        if ($target->tiktok_video_cover_timestamp_ms !== null) {
            $info['video_cover_timestamp_ms'] = $target->tiktok_video_cover_timestamp_ms;
        }

        return $info;
    }

    /**
     * @return array<string, mixed>
     */
    private function photoPostInfo(PublishContext $context): array
    {
        $target = $context->target;

        // A photo post splits its text: a short title plus a long description,
        // where a video has only a title. The post's own text is the description;
        // the title is the separate short field captured in the composer.
        return [
            'title' => (string) ($target->tiktok_photo_title ?? ''),
            'description' => $this->caption($context, 4000),
            'privacy_level' => $target->tiktok_privacy_level?->value,
            'disable_comment' => $target->tiktok_disable_comment,
            'brand_content_toggle' => $target->tiktok_brand_content_toggle,
            'brand_organic_toggle' => $target->tiktok_brand_organic_toggle,
            'auto_add_music' => $target->tiktok_auto_add_music,
        ];
    }

    /**
     * The post's text, joined and clipped to $limit UTF-16 runes — the unit TikTok
     * counts in (see Platform::measure). Clipping rather than failing mirrors how
     * the composer already warns but does not block.
     */
    private function caption(PublishContext $context, int $limit): string
    {
        $caption = implode("\n\n", array_values(array_filter(
            array_map(static fn (string $segment): string => trim($segment), $context->segments),
            static fn (string $segment): bool => $segment !== '',
        )));

        if (Platform::TikTok->measure($caption) <= $limit) {
            return $caption;
        }

        // mb_substr counts code points, not UTF-16 runes, so step back until the
        // rune budget is met — a string of astral characters (emoji) costs two
        // runes each and would otherwise still overshoot.
        $clipped = $caption;
        while ($clipped !== '' && Platform::TikTok->measure($clipped) > $limit) {
            $clipped = mb_substr($clipped, 0, mb_strlen($clipped) - 1);
        }

        return $clipped;
    }

    /**
     * Poll TikTok's processing status. Returns success once published (or once a
     * draft has landed in the creator's inbox), a MediaProcessing failure to be
     * retried while TikTok works, or a terminal failure.
     */
    private function pollStatus(PublishContext $context, string $publishId, string $token): PublishResult
    {
        $response = $this->http->withToken($token)->asJson()
            ->post(self::API_BASE.'/post/publish/status/fetch/', ['publish_id' => $publishId]);

        $this->meter(UsageCategory::Publish, UsageOperation::MEDIA_STATUS_POLL, $context->account, $response);

        $failure = $this->failureFor($response);
        if ($failure !== null) {
            return $failure;
        }

        $status = (string) $response->json('data.status');

        return match ($status) {
            // A direct post is live. TikTok returns the public post id(s); fall back
            // to the publish_id so the target always records something traceable.
            'PUBLISH_COMPLETE' => PublishResult::success($this->publishedIds($response, $publishId)),

            // An inbox draft is terminal here: it is waiting in the creator's app
            // and will never progress to PUBLISH_COMPLETE on its own.
            'SEND_TO_USER_INBOX' => PublishResult::success([$publishId]),

            'PROCESSING_UPLOAD', 'PROCESSING_DOWNLOAD' => PublishResult::failure(
                ErrorKind::MediaProcessing,
                'TikTok is processing the media.',
                retryAfter: self::PROCESSING_RETRY_AFTER,
            ),

            'FAILED' => PublishResult::failure(
                ErrorKind::Validation,
                $this->failReason($response),
                $response->status(),
                $this->excerpt($response),
            ),

            default => PublishResult::failure(
                ErrorKind::ServerError,
                "TikTok returned an unrecognised publish status ({$status}).",
                $response->status(),
                $this->excerpt($response),
            ),
        };
    }

    /**
     * @return list<string>
     */
    private function publishedIds(Response $response, string $publishId): array
    {
        /** @var array<int, int|string> $raw */
        $raw = (array) ($response->json('data.publicaly_available_post_id') ?? []);

        $ids = array_values(array_filter(
            array_map(static fn (int|string $id): string => (string) $id, $raw),
            static fn (string $id): bool => $id !== '',
        ));

        return $ids === [] ? [$publishId] : $ids;
    }

    private function failReason(Response $response): string
    {
        $reason = (string) ($response->json('data.fail_reason') ?? '');

        return $reason === ''
            ? 'TikTok could not publish this post.'
            : TikTokErrorMap::message($reason, "TikTok could not publish this post ({$reason}).");
    }

    /**
     * Build a failure from TikTok's error envelope, or null when the call was OK.
     *
     * Every v2 response carries {code, message, log_id}, and a failure can arrive
     * with HTTP 200 (creator_info) or 403 (init) for the same condition — so the
     * body is authoritative and the status is only a fallback.
     */
    private function failureFor(Response $response): ?PublishResult
    {
        $code = $response->json('error.code');
        $code = is_string($code) ? $code : '';

        if ($response->successful() && TikTokErrorMap::isOk($code)) {
            return null;
        }

        return $this->mapFailure($response);
    }

    private function throwUnlessOk(Response $response): void
    {
        if ($this->failureFor($response) !== null) {
            throw new TikTokRequestFailed($response);
        }
    }

    private function mapFailure(Response $response): PublishResult
    {
        $code = $response->json('error.code');
        $code = is_string($code) ? $code : '';

        $fallback = $response->json('error.message');
        $fallback = is_string($fallback) ? $fallback : '';

        $kind = TikTokErrorMap::classify($code, $response->status());

        return PublishResult::failure(
            $kind,
            TikTokErrorMap::message($code, $fallback),
            $response->status(),
            $this->excerpt($response),
            $this->retryAfter($response),
        );
    }

    private function sizeOf(PostMedia $media): int
    {
        // size_bytes is recorded at upload; fall back to the disk if it is missing
        // so the chunk plan is never computed from a zero.
        if ($media->size_bytes > 0) {
            return $media->size_bytes;
        }

        return (int) Storage::disk($media->disk)->size($media->path);
    }

    private function persistState(PublishContext $context, MediaUploadState $state): void
    {
        $context->target->forceFill(['media_upload_state' => $state->toArray()])->save();
    }

    /**
     * TikTok exposes no delete endpoint — nothing in the Content Posting API can
     * remove a published video or photo post.
     *
     * This is a deliberate no-op rather than a throw: throwing would make
     * DeletePostTarget retry for ~20 minutes against an API that will never
     * accept the call, and still fail. The local record is removed while the post
     * stays live on TikTok, so the UI must say so — the composer surfaces that
     * warning before the user confirms.
     *
     * @param  array<string, mixed>  $credentials
     */
    public function delete(PostTarget $target, array $credentials): void
    {
        // Intentionally empty. See the docblock.
    }
}

/**
 * Internal signal so a failed call short-circuits to the shared error mapping.
 * Not part of the public connector surface.
 *
 * @internal
 */
final class TikTokRequestFailed extends RuntimeException
{
    public function __construct(public readonly Response $response)
    {
        parent::__construct('TikTok request failed.');
    }
}
