<?php

declare(strict_types=1);

namespace App\Services\Engagement;

use App\Enums\Platform;
use App\Enums\ReplyStatus;
use App\Jobs\FetchPostTargetReplies;
use App\Models\PostTarget;
use App\Models\PostTargetReply;
use Illuminate\Support\Facades\Date;

/**
 * Ingests a Meta `comments` webhook change into the Engagement inbox, mirroring the
 * dedup/persistence contract of {@see FetchPostTargetReplies} (the polling
 * path) so a comment that arrives by webhook and one fetched by polling converge on
 * the same row via the unique (post_target_id, remote_reply_id) key.
 *
 * The comment is matched to the target by the published media id, scoped to the
 * delivering workspace's Instagram accounts.
 */
class InstagramCommentRecorder
{
    /**
     * @param  array<string, mixed>  $value  the webhook change `value` object
     */
    public function record(string $workspaceId, array $value): void
    {
        // Instagram connects via Facebook Login, whose `comments` payload keys the
        // comment as `comment_id`; the Business-Login shape uses `id`. Accept both.
        $commentId = $this->stringOrNull($value['comment_id'] ?? $value['id'] ?? null);
        $mediaId = $this->stringOrNull($value['media']['id'] ?? $value['media_id'] ?? null);

        if ($commentId === null || $mediaId === null) {
            return;
        }

        $target = PostTarget::withoutGlobalScopes()
            ->with(['post' => fn ($query) => $query->withoutGlobalScopes()])
            ->where('platform', Platform::Instagram->value)
            ->where('remote_id', $mediaId)
            ->first();

        if ($target === null || $target->post?->workspace_id !== $workspaceId) {
            return;
        }

        $username = $this->stringOrNull($value['from']['username'] ?? $value['username'] ?? null);
        $parentId = $this->stringOrNull($value['parent_id'] ?? null);
        $createdAt = isset($value['timestamp'])
            ? Date::parse($value['timestamp'])
            : Date::now();

        $reply = PostTargetReply::withoutGlobalScopes()->firstOrNew([
            'post_target_id' => $target->id,
            'remote_reply_id' => $commentId,
        ]);

        // Never let a webhook redelivery flip a reply we've already actioned back to
        // Pending, and preserve read state; only (re)fill the immutable content.
        $reply->fill([
            'workspace_id' => $workspaceId,
            'platform' => Platform::Instagram->value,
            'parent_remote_id' => $parentId ?? $mediaId,
            'conversation_remote_id' => $parentId ?? $mediaId,
            'author_handle' => $username ?? 'instagram_user',
            'author_name' => $username,
            'text' => (string) ($value['text'] ?? ''),
            'remote_created_at' => $createdAt,
            'fetched_at' => Date::now(),
            'is_ours' => false,
        ]);

        if (! $reply->exists) {
            $reply->status = ReplyStatus::Pending;
        }

        $reply->save();
    }

    private function stringOrNull(mixed $value): ?string
    {
        if (! is_scalar($value)) {
            return null;
        }

        $string = trim((string) $value);

        return $string === '' ? null : $string;
    }
}
