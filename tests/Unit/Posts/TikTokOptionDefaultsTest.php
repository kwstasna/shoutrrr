<?php

use App\Dto\Post\TikTokOptionsData;
use App\Models\PostTarget;

// TikTok's content-sharing guidelines require the interaction toggles to start
// OFF. The composer renders them as "Allow comments/Duet/Stitch" and stores the
// inverse (disable_*), so "off" must persist as disable_* = TRUE. Defaulting the
// columns to false hydrates the composer with every box already ticked and
// publishes the opposite of what the audit demands — this pins the polarity at
// the boundary where it was actually got wrong.
test('a fresh post target defaults every TikTok interaction to disabled', function () {
    $target = new PostTarget;

    expect($target->tiktok_disable_comment)->toBeTrue()
        ->and($target->tiktok_disable_duet)->toBeTrue()
        ->and($target->tiktok_disable_stitch)->toBeTrue();
});

test('a fresh post target pre-selects no TikTok privacy level', function () {
    expect((new PostTarget)->tiktok_privacy_level)->toBeNull();
});

test('a fresh post target leaves both TikTok commercial toggles off', function () {
    $target = new PostTarget;

    expect($target->tiktok_brand_content_toggle)->toBeFalse()
        ->and($target->tiktok_brand_organic_toggle)->toBeFalse();
});

test('a partial options payload falls back to interactions off, never on', function () {
    $options = TikTokOptionsData::fromArray([]);

    expect($options->disableComment)->toBeTrue()
        ->and($options->disableDuet)->toBeTrue()
        ->and($options->disableStitch)->toBeTrue()
        ->and($options->privacyLevel)->toBeNull()
        ->and($options->brandContentToggle)->toBeFalse()
        ->and($options->brandOrganicToggle)->toBeFalse();
});

test('an explicit allow survives the round trip to columns', function () {
    $options = TikTokOptionsData::fromArray([
        'post_mode' => 'direct_post',
        'privacy_level' => 'PUBLIC_TO_EVERYONE',
        'disable_comment' => false,
        'disable_duet' => true,
        'disable_stitch' => false,
    ]);

    expect($options->toColumns())->toMatchArray([
        'tiktok_disable_comment' => false,
        'tiktok_disable_duet' => true,
        'tiktok_disable_stitch' => false,
        'tiktok_privacy_level' => 'PUBLIC_TO_EVERYONE',
    ]);
});
