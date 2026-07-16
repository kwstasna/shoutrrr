<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Which TikTok publishing surface a target uses.
 *
 * TikTok exposes two distinct posting flows, and they are not interchangeable:
 * a Direct Post goes live on the creator's profile unattended (and therefore
 * carries the full compliance payload — privacy level, interaction and
 * commercial-disclosure toggles), while an inbox upload lands as a draft the
 * creator must open the TikTok app to finish and publish themselves.
 *
 * The two flows use different endpoints and different scopes, so this drives
 * both routing and the composer UI (see TikTokOptionsPanel).
 */
enum TikTokPostMode: string
{
    /** POST /v2/post/publish/video/init/ (video) or /content/init/ with post_mode=DIRECT_POST. Needs `video.publish`. */
    case DirectPost = 'direct_post';

    /** POST /v2/post/publish/inbox/video/init/ or /content/init/ with post_mode=MEDIA_UPLOAD. Needs `video.upload`. */
    case InboxDraft = 'inbox_draft';

    public function label(): string
    {
        return match ($this) {
            self::DirectPost => 'Direct post',
            self::InboxDraft => 'Draft',
        };
    }

    /**
     * The `post_mode` value TikTok's photo endpoint expects. The video endpoints
     * encode the mode in the URL path instead and never send this field.
     */
    public function photoPostMode(): string
    {
        return match ($this) {
            self::DirectPost => 'DIRECT_POST',
            self::InboxDraft => 'MEDIA_UPLOAD',
        };
    }

    /**
     * Whether this mode publishes straight to the creator's profile. Only a
     * Direct Post accepts (and requires) `post_info`; an inbox draft carries
     * `source_info` alone, because the creator picks privacy and interaction
     * settings inside the TikTok app when they finish the post.
     */
    public function isDirect(): bool
    {
        return $this === self::DirectPost;
    }
}
