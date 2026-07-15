<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * How a target should be published on platforms that expose more than one
 * surface for the same media. Today this only distinguishes Instagram's
 * permanent feed from ephemeral Stories; other platforms ignore it and always
 * behave as `Feed`.
 */
enum PostFormat: string
{
    case Feed = 'feed';
    case Story = 'story';

    public function label(): string
    {
        return match ($this) {
            self::Feed => 'Feed',
            self::Story => 'Story',
        };
    }

    public function isStory(): bool
    {
        return $this === self::Story;
    }
}
