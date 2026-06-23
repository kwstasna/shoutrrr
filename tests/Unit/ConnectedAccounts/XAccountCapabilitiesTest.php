<?php

use App\Services\ConnectedAccounts\XAccountCapabilities;
use Illuminate\Support\Facades\Http;

test('it maps blue verified X accounts to the premium tweet length', function () {
    expect(XAccountCapabilities::fromUserData(['verified_type' => 'blue']))
        ->toBe([
            'x_premium' => true,
            'max_text_length' => 25_000,
            'verified_type' => 'blue',
        ]);
});

test('it maps unverified X accounts to the standard tweet length', function () {
    expect(XAccountCapabilities::fromUserData(['verified_type' => 'none']))
        ->toBe([
            'x_premium' => false,
            'max_text_length' => 280,
            'verified_type' => 'none',
        ]);
});

test('it detects premium status from the X users me endpoint', function () {
    Http::fake([
        'https://api.twitter.com/2/users/me*' => Http::response([
            'data' => ['id' => '1', 'verified_type' => 'blue'],
        ]),
    ]);

    $capabilities = app(XAccountCapabilities::class)->forAccessToken('tok');

    expect($capabilities['x_premium'])->toBeTrue()
        ->and($capabilities['max_text_length'])->toBe(25_000);

    Http::assertSent(fn ($request): bool => $request->url() === 'https://api.twitter.com/2/users/me?user.fields=verified%2Cverified_type'
        && $request->hasHeader('Authorization', 'Bearer tok'));
});
