<?php

declare(strict_types=1);

namespace App\Services\Metrics;

use App\Enums\MetricsStatus;
use App\Enums\Platform;
use App\Enums\PostFormat;
use App\Models\PostTarget;
use App\Models\StoryInsight;
use Illuminate\Support\Facades\Date;

/**
 * Persists the metrics carried by a Meta `story_insights` webhook change. Stories
 * are ephemeral (gone after 24h) and never surface on the media edge the metrics
 * poller reads, so this webhook is the only source of their numbers. Each event is:
 *
 *  1. saved as a durable {@see StoryInsight} snapshot (full metric set + raw payload), and
 *  2. denormalised onto the PostTarget so the existing analytics dashboard, which
 *     reads the target's own likes/comments/reposts/impressions columns, reflects it.
 *
 * Matching is by the published media id, scoped to Instagram Story targets that
 * belong to the delivering workspace, so a stray id can never touch another
 * workspace's data or a feed post.
 */
class StoryInsightsRecorder
{
    /**
     * @param  array<string, mixed>  $value  the webhook change `value` object
     */
    public function record(string $workspaceId, array $value): void
    {
        $mediaId = isset($value['media_id']) ? (string) $value['media_id'] : '';

        if ($mediaId === '') {
            return;
        }

        $target = PostTarget::withoutGlobalScopes()
            ->with(['post' => fn ($query) => $query->withoutGlobalScopes()])
            ->where('platform', Platform::Instagram->value)
            ->where('format', PostFormat::Story->value)
            ->where('remote_id', $mediaId)
            ->first();

        if ($target === null || $target->post?->workspace_id !== $workspaceId) {
            return;
        }

        $reach = $this->intOrNull($value['reach'] ?? null);
        $impressions = $this->intOrNull($value['impressions'] ?? null);
        $replies = $this->intOrNull($value['replies'] ?? null);
        $shares = $this->intOrNull($value['shares'] ?? $value['reposts'] ?? null);
        $views = $this->intOrNull($value['views'] ?? $value['total_views'] ?? null);

        $now = Date::now();

        StoryInsight::updateOrCreate(
            ['post_target_id' => $target->id, 'captured_at' => $now],
            [
                'reach' => $reach,
                'impressions' => $impressions,
                'replies' => $replies,
                'shares' => $shares,
                'total_interactions' => $this->intOrNull($value['total_interactions'] ?? null),
                'profile_visits' => $this->intOrNull($value['profile_visits'] ?? null),
                'follows' => $this->intOrNull($value['follows'] ?? null),
                'navigation' => $this->intOrNull($value['navigation'] ?? null),
                'views' => $views,
                'raw' => $value,
            ],
        );

        // Denormalise onto the target for the shared analytics dashboard. Stories have
        // no likes; "replies" are their comment equivalent, "shares" their reposts, and
        // "reach" (or views, since impressions is deprecated post-2024-07) their reach.
        $target->forceFill([
            'likes' => 0,
            'comments' => $replies ?? 0,
            'reposts' => $shares ?? 0,
            'impressions' => $reach ?? $impressions ?? $views,
            'metrics_status' => MetricsStatus::Ok->value,
            'metrics_captured_at' => $now,
        ])->save();
    }

    private function intOrNull(mixed $value): ?int
    {
        return is_numeric($value) ? (int) $value : null;
    }
}
