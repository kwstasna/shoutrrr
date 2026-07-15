<?php

use App\Models\PostMedia;
use App\Services\Media\InstagramImageRendition;
use Illuminate\Support\Facades\Storage;

/**
 * Instagram's Content Publishing API only accepts JPEG images; a non-JPEG source
 * must be transcoded to a JPEG rendition Meta can fetch, or the container call
 * fails with "Only photo or video can be accepted as media type" (Graph 36003).
 */
function pngBytes(): string
{
    $image = imagecreatetruecolor(64, 64);
    imagefilledrectangle($image, 0, 0, 63, 63, imagecolorallocate($image, 51, 102, 255));
    ob_start();
    imagepng($image);
    $bytes = (string) ob_get_clean();
    imagedestroy($image);

    return $bytes;
}

test('a jpeg image passes through untouched with no derived rendition', function () {
    Storage::fake('public');
    Storage::disk('public')->put('media/pic.jpg', 'jpeg-bytes');

    $media = PostMedia::factory()->create(['disk' => 'public', 'path' => 'media/pic.jpg', 'mime' => 'image/jpeg']);

    $url = app(InstagramImageRendition::class)->urlFor($media);

    expect($url)->toContain('media/pic.jpg');
    Storage::disk('public')->assertMissing('derived/instagram/'.$media->id.'.jpg');
});

test('a png image is transcoded to a derived jpeg rendition and that url is returned', function () {
    Storage::fake('public');
    Storage::disk('public')->put('media/pic.png', pngBytes());

    $media = PostMedia::factory()->create(['disk' => 'public', 'path' => 'media/pic.png', 'mime' => 'image/png']);

    $url = app(InstagramImageRendition::class)->urlFor($media);

    $derived = 'derived/instagram/'.$media->id.'.jpg';
    Storage::disk('public')->assertExists($derived);
    expect($url)->toContain($derived);

    // The stored rendition is real JPEG bytes (SOI marker 0xFFD8).
    $bytes = Storage::disk('public')->get($derived);
    expect(substr($bytes, 0, 2))->toBe("\xFF\xD8");
});

test('an existing derived rendition is reused rather than transcoded again', function () {
    Storage::fake('public');
    Storage::disk('public')->put('media/pic.png', pngBytes());

    $media = PostMedia::factory()->create(['disk' => 'public', 'path' => 'media/pic.png', 'mime' => 'image/png']);
    $derived = 'derived/instagram/'.$media->id.'.jpg';

    // Seed a sentinel rendition; a second resolve must not overwrite it.
    Storage::disk('public')->put($derived, 'SENTINEL');

    app(InstagramImageRendition::class)->urlFor($media);

    expect(Storage::disk('public')->get($derived))->toBe('SENTINEL');
});

test('a video passes through untouched — the jpeg-only rule is image-specific', function () {
    Storage::fake('public');
    Storage::disk('public')->put('media/clip.mp4', 'mp4-bytes');

    $media = PostMedia::factory()->video()->create(['disk' => 'public', 'path' => 'media/clip.mp4']);

    $url = app(InstagramImageRendition::class)->urlFor($media);

    expect($url)->toContain('media/clip.mp4');
    Storage::disk('public')->assertMissing('derived/instagram/'.$media->id.'.jpg');
});
