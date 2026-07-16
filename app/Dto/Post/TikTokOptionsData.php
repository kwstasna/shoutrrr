<?php

declare(strict_types=1);

namespace App\Dto\Post;

use App\Enums\TikTokPostMode;
use App\Enums\TikTokPrivacyLevel;

/**
 * The TikTok publishing options a client sent for one target.
 *
 * Everything is optional-shaped because the composer autosaves continuously: a
 * draft is saved long before the creator has picked a visibility, and TikTok's
 * guidelines forbid choosing one on their behalf. "Not chosen yet" is therefore
 * a first-class state all the way down to the nullable column, and completeness
 * is enforced at publish rather than at save.
 */
final readonly class TikTokOptionsData
{
    public function __construct(
        public TikTokPostMode $postMode,
        public ?TikTokPrivacyLevel $privacyLevel,
        public bool $disableComment,
        public bool $disableDuet,
        public bool $disableStitch,
        public bool $brandContentToggle,
        public bool $brandOrganicToggle,
        public ?string $photoTitle,
    ) {}

    /**
     * @param  array<string, mixed>  $raw
     */
    public static function fromArray(array $raw): self
    {
        $photoTitle = $raw['photo_title'] ?? null;
        $photoTitle = is_string($photoTitle) && trim($photoTitle) !== '' ? $photoTitle : null;

        return new self(
            postMode: TikTokPostMode::tryFrom((string) ($raw['post_mode'] ?? '')) ?? TikTokPostMode::DirectPost,
            // No fallback: an unrecognised or absent level stays null rather than
            // silently becoming a default the creator never chose.
            privacyLevel: TikTokPrivacyLevel::tryFrom((string) ($raw['privacy_level'] ?? '')),
            // Absent keys fall back to "interaction off" (disable = true), never
            // to "on": the composer always sends these explicitly, so a missing
            // key means a malformed or partial payload, and the safe reading of
            // that is the audit's default rather than silently enabling comments.
            disableComment: (bool) ($raw['disable_comment'] ?? true),
            disableDuet: (bool) ($raw['disable_duet'] ?? true),
            disableStitch: (bool) ($raw['disable_stitch'] ?? true),
            brandContentToggle: (bool) ($raw['brand_content_toggle'] ?? false),
            brandOrganicToggle: (bool) ($raw['brand_organic_toggle'] ?? false),
            photoTitle: $photoTitle,
        );
    }

    /**
     * Project onto the post_targets columns.
     *
     * @return array<string, mixed>
     */
    public function toColumns(): array
    {
        return [
            'tiktok_post_mode' => $this->postMode->value,
            'tiktok_privacy_level' => $this->privacyLevel?->value,
            'tiktok_disable_comment' => $this->disableComment,
            'tiktok_disable_duet' => $this->disableDuet,
            'tiktok_disable_stitch' => $this->disableStitch,
            'tiktok_brand_content_toggle' => $this->brandContentToggle,
            'tiktok_brand_organic_toggle' => $this->brandOrganicToggle,
            'tiktok_photo_title' => $this->photoTitle,
        ];
    }

    /**
     * The shape the composer hydrates from — the inverse of the client's toWire().
     *
     * @return array<string, mixed>
     */
    public function toView(): array
    {
        return [
            'post_mode' => $this->postMode->value,
            'privacy_level' => $this->privacyLevel?->value,
            'disable_comment' => $this->disableComment,
            'disable_duet' => $this->disableDuet,
            'disable_stitch' => $this->disableStitch,
            'brand_content_toggle' => $this->brandContentToggle,
            'brand_organic_toggle' => $this->brandOrganicToggle,
            'photo_title' => $this->photoTitle,
        ];
    }
}
