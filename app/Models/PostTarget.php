<?php

declare(strict_types=1);

namespace App\Models;

use App\Dto\Post\TikTokOptionsData;
use App\Enums\ErrorKind;
use App\Enums\MetricsStatus;
use App\Enums\Platform;
use App\Enums\PostFormat;
use App\Enums\PostTargetStatus;
use App\Enums\TikTokPostMode;
use App\Enums\TikTokPrivacyLevel;
use Carbon\CarbonImmutable;
use Database\Factories\PostTargetFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Override;

/**
 * @property string $id
 * @property string $post_id
 * @property string $connected_account_id
 * @property Platform $platform
 * @property list<string> $sections
 * @property PostFormat $format
 * @property TikTokPostMode $tiktok_post_mode
 * @property TikTokPrivacyLevel|null $tiktok_privacy_level
 * @property bool $tiktok_disable_comment
 * @property bool $tiktok_disable_duet
 * @property bool $tiktok_disable_stitch
 * @property bool $tiktok_brand_content_toggle
 * @property bool $tiktok_brand_organic_toggle
 * @property int|null $tiktok_video_cover_timestamp_ms
 * @property int|null $tiktok_photo_cover_index
 * @property bool $tiktok_auto_add_music
 * @property string|null $tiktok_photo_title
 * @property array{text?: string|null, media_ids?: list<string>}|null $content_override
 * @property bool $auto_split
 * @property PostTargetStatus $status
 * @property string|null $remote_id
 * @property list<string>|null $remote_ids
 * @property ErrorKind|null $error_kind
 * @property string|null $error_message
 * @property int $attempts
 * @property CarbonImmutable|null $next_attempt_at
 * @property string|null $idempotency_key
 * @property CarbonImmutable|null $posted_at
 * @property array<string, mixed>|null $media_upload_state
 * @property int $likes
 * @property int $comments
 * @property int $reposts
 * @property int|null $impressions
 * @property CarbonImmutable|null $metrics_captured_at
 * @property MetricsStatus|null $metrics_status
 * @property CarbonImmutable|null $reply_fetched_at
 */
#[Fillable([
    'post_id',
    'connected_account_id',
    'platform',
    'sections',
    'format',
    'tiktok_post_mode',
    'tiktok_privacy_level',
    'tiktok_disable_comment',
    'tiktok_disable_duet',
    'tiktok_disable_stitch',
    'tiktok_brand_content_toggle',
    'tiktok_brand_organic_toggle',
    'tiktok_video_cover_timestamp_ms',
    'tiktok_photo_cover_index',
    'tiktok_auto_add_music',
    'tiktok_photo_title',
    'content_override',
    'auto_split',
    'status',
    'remote_id',
    'remote_ids',
    'error_kind',
    'error_message',
    'attempts',
    'next_attempt_at',
    'idempotency_key',
    'posted_at',
    'media_upload_state',
    'likes',
    'comments',
    'reposts',
    'impressions',
    'metrics_captured_at',
    'metrics_status',
    'reply_fetched_at',
])]
class PostTarget extends Model
{
    /** @use HasFactory<PostTargetFactory> */
    use HasFactory, HasUuids;

    /**
     * In-memory defaults so a target created without them still resolves sanely
     * (Eloquent does not hydrate DB defaults after create — without this the
     * booleans below read as null while their @property types promise bool).
     *
     * `tiktok_privacy_level` is deliberately absent: it must stay null until the
     * creator picks one, because TikTok's guidelines forbid a pre-selected
     * privacy default. The model's own defaults enforce that, not just a comment.
     *
     * The `tiktok_disable_*` defaults are TRUE for the same reason: TikTok
     * requires its interaction toggles to start off, and the composer renders
     * them as "Allow …" (allow = !disable). Defaulting them to false would tick
     * every box on a fresh target. These must stay in step with the column
     * defaults in the migration.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'format' => 'feed',
        'tiktok_post_mode' => 'direct_post',
        'tiktok_disable_comment' => true,
        'tiktok_disable_duet' => true,
        'tiktok_disable_stitch' => true,
        'tiktok_brand_content_toggle' => false,
        'tiktok_brand_organic_toggle' => false,
        'tiktok_auto_add_music' => false,
    ];

    /**
     * @return array<string, string>
     */
    #[Override]
    protected function casts(): array
    {
        return [
            'platform' => Platform::class,
            'status' => PostTargetStatus::class,
            'sections' => 'array',
            'format' => PostFormat::class,
            'tiktok_post_mode' => TikTokPostMode::class,
            'tiktok_privacy_level' => TikTokPrivacyLevel::class,
            'tiktok_disable_comment' => 'boolean',
            'tiktok_disable_duet' => 'boolean',
            'tiktok_disable_stitch' => 'boolean',
            'tiktok_brand_content_toggle' => 'boolean',
            'tiktok_brand_organic_toggle' => 'boolean',
            'tiktok_video_cover_timestamp_ms' => 'integer',
            'tiktok_photo_cover_index' => 'integer',
            'tiktok_auto_add_music' => 'boolean',
            'content_override' => 'array',
            'auto_split' => 'boolean',
            'remote_ids' => 'array',
            'media_upload_state' => 'array',
            'error_kind' => ErrorKind::class,
            'attempts' => 'integer',
            'next_attempt_at' => 'immutable_datetime',
            'posted_at' => 'immutable_datetime',
            'likes' => 'integer',
            'comments' => 'integer',
            'reposts' => 'integer',
            'impressions' => 'integer',
            'metrics_captured_at' => 'immutable_datetime',
            'metrics_status' => MetricsStatus::class,
            'reply_fetched_at' => 'immutable_datetime',
        ];
    }

    /**
     * This target's TikTok publishing options, read back off its own columns.
     *
     * Only meaningful for a TikTok target — every other platform's columns hold
     * their inert defaults, exactly as `format` is inert for non-Instagram.
     */
    public function tiktokOptions(): TikTokOptionsData
    {
        return new TikTokOptionsData(
            postMode: $this->tiktok_post_mode,
            privacyLevel: $this->tiktok_privacy_level,
            disableComment: $this->tiktok_disable_comment,
            disableDuet: $this->tiktok_disable_duet,
            disableStitch: $this->tiktok_disable_stitch,
            brandContentToggle: $this->tiktok_brand_content_toggle,
            brandOrganicToggle: $this->tiktok_brand_organic_toggle,
            photoTitle: $this->tiktok_photo_title,
        );
    }

    /**
     * @return BelongsTo<Post, $this>
     */
    public function post(): BelongsTo
    {
        return $this->belongsTo(Post::class);
    }

    /**
     * @return BelongsTo<ConnectedAccount, $this>
     */
    public function account(): BelongsTo
    {
        return $this->belongsTo(ConnectedAccount::class, 'connected_account_id');
    }

    /**
     * @return HasMany<PostTargetAttempt, $this>
     */
    public function attemptLogs(): HasMany
    {
        return $this->hasMany(PostTargetAttempt::class, 'post_target_id');
    }

    /** @return HasMany<PostTargetMetric, $this> */
    public function metrics(): HasMany
    {
        return $this->hasMany(PostTargetMetric::class, 'post_target_id')->orderBy('captured_at');
    }

    /** @return HasMany<PostTargetReply, $this> */
    public function replies(): HasMany
    {
        return $this->hasMany(PostTargetReply::class, 'post_target_id');
    }

    /** @return HasMany<StoryInsight, $this> */
    public function storyInsights(): HasMany
    {
        return $this->hasMany(StoryInsight::class, 'post_target_id')->orderBy('captured_at');
    }
}
