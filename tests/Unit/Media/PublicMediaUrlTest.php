<?php

use App\Models\PostMedia;
use App\Services\Media\PublicMediaUrl;
use Illuminate\Support\Facades\Storage;

it('returns an absolute url containing the path for media on the public disk', function () {
    // The public disk serves host-relative URLs ("/storage/…") for the browser;
    // Meta fetches server-side and needs an absolute URL rooted at the app URL.
    config()->set('app.url', 'https://shtr.example.com');
    Storage::fake('public');
    Storage::disk('public')->put('media/ws/pic.jpg', 'contents');

    $media = PostMedia::factory()->create([
        'disk' => 'public',
        'path' => 'media/ws/pic.jpg',
    ]);

    $url = app(PublicMediaUrl::class)->for($media);

    expect($url)->toStartWith('https://shtr.example.com/')
        ->and($url)->toContain('media/ws/pic.jpg')
        ->and($url)->not->toContain('signature=');
});

it('signs the url for media on a private disk so Meta can fetch it over a real HTTPS endpoint', function () {
    $media = PostMedia::factory()->video()->create([
        'disk' => 'local',
        'path' => 'media/ws/clip.mp4',
    ]);

    $url = app(PublicMediaUrl::class)->for($media);

    expect($url)->toContain('media/ws/clip.mp4')
        ->and($url)->toContain('signature=');
});
