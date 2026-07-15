<?php

declare(strict_types=1);

namespace App\Models;

use Carbon\CarbonImmutable;
use Database\Factories\StoryInsightFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Override;

/**
 * A persisted snapshot of a published Instagram Story's insights, delivered by the
 * `story_insights` webhook. Stories vanish after 24h and never appear on the media
 * edge the metrics poller reads, so these rows are the durable record of how a
 * story performed.
 *
 * @property string $id
 * @property string $post_target_id
 * @property CarbonImmutable $captured_at
 * @property int|null $reach
 * @property int|null $impressions
 * @property int|null $replies
 * @property int|null $shares
 * @property int|null $total_interactions
 * @property int|null $profile_visits
 * @property int|null $follows
 * @property int|null $navigation
 * @property int|null $views
 * @property array<string, mixed>|null $raw
 */
#[Fillable([
    'post_target_id',
    'captured_at',
    'reach',
    'impressions',
    'replies',
    'shares',
    'total_interactions',
    'profile_visits',
    'follows',
    'navigation',
    'views',
    'raw',
])]
class StoryInsight extends Model
{
    /** @use HasFactory<StoryInsightFactory> */
    use HasFactory, HasUuids;

    /**
     * @return array<string, string>
     */
    #[Override]
    protected function casts(): array
    {
        return [
            'captured_at' => 'immutable_datetime',
            'reach' => 'integer',
            'impressions' => 'integer',
            'replies' => 'integer',
            'shares' => 'integer',
            'total_interactions' => 'integer',
            'profile_visits' => 'integer',
            'follows' => 'integer',
            'navigation' => 'integer',
            'views' => 'integer',
            'raw' => 'array',
        ];
    }

    /**
     * @return BelongsTo<PostTarget, $this>
     */
    public function target(): BelongsTo
    {
        return $this->belongsTo(PostTarget::class, 'post_target_id');
    }
}
