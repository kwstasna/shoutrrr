<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Who can see a TikTok Direct Post.
 *
 * IMPORTANT: never present these as a fixed list. TikTok's content-sharing
 * guidelines require the privacy selector to offer exactly the options that
 * `creator_info.privacy_level_options` returns for that creator at that moment —
 * a private account, for example, cannot post publicly — and posting a level the
 * creator is not allowed to use fails with `privacy_level_option_mismatch` (403).
 * This enum exists to type the *chosen* value, not to enumerate what to offer.
 *
 * There is deliberately no default case: the same guidelines require the
 * dropdown to start with nothing pre-selected, so "not chosen yet" is modelled
 * as a null column rather than as a member here.
 */
enum TikTokPrivacyLevel: string
{
    case PublicToEveryone = 'PUBLIC_TO_EVERYONE';
    case MutualFollowFriends = 'MUTUAL_FOLLOW_FRIENDS';
    case FollowerOfCreator = 'FOLLOWER_OF_CREATOR';
    case SelfOnly = 'SELF_ONLY';

    public function label(): string
    {
        return match ($this) {
            self::PublicToEveryone => 'Everyone',
            self::MutualFollowFriends => 'Friends',
            self::FollowerOfCreator => 'Followers',
            self::SelfOnly => 'Only me',
        };
    }

    /**
     * Whether this level keeps the post off the public feed. Branded content may
     * not be private, so this gates the commercial-disclosure interlock in the
     * composer and is re-checked at publish.
     */
    public function isPrivate(): bool
    {
        return $this === self::SelfOnly;
    }
}
