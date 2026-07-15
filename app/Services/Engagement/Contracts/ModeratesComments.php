<?php

declare(strict_types=1);

namespace App\Services\Engagement\Contracts;

use App\Dto\Engagement\ReplyActionResult;
use App\Models\ConnectedAccount;
use App\Models\PostTargetReply;

/**
 * Opt-in capability for platforms whose API can hide/unhide an inbound comment
 * (Meta's comment moderation). A connector implements this only where the
 * platform supports it (Instagram); the Engagement controller checks for the
 * interface before offering the action, so unsupported platforms simply don't.
 */
interface ModeratesComments
{
    /**
     * Hide ($hidden = true) or unhide ($hidden = false) an inbound comment on the
     * platform. Hiding removes it from public view without deleting it.
     *
     * @param  array<string, mixed>  $credentials
     */
    public function setCommentHidden(
        ConnectedAccount $account,
        PostTargetReply $reply,
        bool $hidden,
        array $credentials,
    ): ReplyActionResult;
}
