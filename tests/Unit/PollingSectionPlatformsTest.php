<?php

use App\Enums\Platform;

// TikTok joins LinkedIn here until its metrics connector lands: correlating a
// published post to its stats depends on an id mapping TikTok never documents.
test('post metrics is supported by every platform except LinkedIn and TikTok', function () {
    foreach (Platform::cases() as $platform) {
        expect($platform->supportsPostMetrics())
            ->toBe($platform !== Platform::LinkedIn && $platform !== Platform::TikTok);
    }
});

test('account metrics is supported by every platform except LinkedIn, Discord and TikTok', function () {
    foreach (Platform::cases() as $platform) {
        expect($platform->supportsAccountMetrics())
            ->toBe(
                $platform !== Platform::LinkedIn
                && $platform !== Platform::Discord
                && $platform !== Platform::TikTok
            );
    }
});

test('pollingSectionPlatforms returns the capability matrix per section', function () {
    $values = fn (string $section): array => array_map(
        fn (Platform $p): string => $p->value,
        Platform::pollingSectionPlatforms($section),
    );

    expect($values('engagement'))->toBe(['x', 'bluesky', 'linkedin', 'facebook', 'instagram', 'threads'])
        ->and($values('post_metrics'))->toBe(['x', 'bluesky', 'facebook', 'instagram', 'threads', 'discord'])
        ->and($values('account_metrics'))->toBe(['x', 'bluesky', 'facebook', 'instagram', 'threads']);
});

test('pollingSectionPlatforms is empty for an unknown section', function () {
    expect(Platform::pollingSectionPlatforms('nonsense'))->toBe([]);
});
