<?php

declare(strict_types=1);

namespace App\Services\Engagement;

use App\Enums\Platform;
use App\Enums\PostFormat;
use App\Enums\ReplyStatus;
use App\Models\PostTarget;
use App\Models\PostTargetReply;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Date;

/**
 * Ingests an Instagram story reply into the Engagement inbox.
 *
 * Unlike feed/Reel comments (the `comments` webhook field), replying to a story
 * sends a Direct Message, delivered Messenger-style under `entry[].messaging[]`
 * with a `message.reply_to.story` context naming the story it answers. We match
 * that story id back to its published {@see PostTarget} (a `format=story` Instagram
 * target keyed by `remote_id`) and persist the reply on the same
 * (post_target_id, remote_reply_id) contract as {@see InstagramCommentRecorder},
 * so story replies land in the same inbox as comments.
 *
 * A messaging event that isn't a story reply (a plain DM, a reaction, an echo of
 * our own message) has no `reply_to.story` and is ignored.
 */
class InstagramStoryReplyRecorder
{
    /**
     * @param  array<string, mixed>  $messaging  a single `entry[].messaging[]` event
     */
    public function record(string $workspaceId, array $messaging): void
    {
        $message = $messaging['message'] ?? null;

        if (! is_array($message)) {
            return;
        }

        // Only story replies carry a reply_to.story context; everything else here is
        // a plain DM we deliberately don't ingest.
        $story = $message['reply_to']['story'] ?? null;
        $storyId = is_array($story) ? $this->stringOrNull($story['id'] ?? null) : null;
        $mid = $this->stringOrNull($message['mid'] ?? null);

        if ($storyId === null || $mid === null) {
            return;
        }

        // Ignore echoes of messages we sent from the account itself.
        if (($message['is_echo'] ?? false) === true) {
            return;
        }

        $target = PostTarget::withoutGlobalScopes()
            ->with(['post' => fn ($query) => $query->withoutGlobalScopes()])
            ->where('platform', Platform::Instagram->value)
            ->where('format', PostFormat::Story->value)
            ->where('remote_id', $storyId)
            ->first();

        if ($target === null || $target->post?->workspace_id !== $workspaceId) {
            return;
        }

        $senderId = $this->stringOrNull($messaging['sender']['id'] ?? null);

        $reply = PostTargetReply::withoutGlobalScopes()->firstOrNew([
            'post_target_id' => $target->id,
            'remote_reply_id' => $mid,
        ]);

        // Never let a webhook redelivery flip a reply we've already actioned back to
        // Pending or clobber its read state; only (re)fill the immutable content.
        $reply->fill([
            'workspace_id' => $workspaceId,
            'platform' => Platform::Instagram->value,
            'parent_remote_id' => $storyId,
            'conversation_remote_id' => $senderId ?? $storyId,
            'author_handle' => $senderId !== null ? 'ig:'.$senderId : 'instagram_user',
            'author_name' => null,
            'text' => (string) ($message['text'] ?? ''),
            'remote_created_at' => $this->parseTimestamp($messaging['timestamp'] ?? null),
            'fetched_at' => Date::now(),
            'is_ours' => false,
        ]);

        if (! $reply->exists) {
            $reply->status = ReplyStatus::Pending;
        }

        $reply->save();
    }

    /**
     * Messaging timestamps arrive as epoch milliseconds (13 digits); the polling and
     * comments paths use seconds. Normalise to a Carbon instance either way.
     */
    private function parseTimestamp(mixed $timestamp): CarbonInterface
    {
        if (! is_numeric($timestamp)) {
            return Date::now();
        }

        $value = (int) $timestamp;

        // Anything past ~year 5138 in seconds is really milliseconds.
        if ($value > 100_000_000_000) {
            $value = intdiv($value, 1000);
        }

        return Date::createFromTimestamp($value);
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
