<?php

use App\Services\Webhooks\MetaWebhookSignature;

test('it accepts a correctly computed sha256 signature', function () {
    $body = '{"hello":"world"}';
    $secret = 'top-secret';
    $header = 'sha256='.hash_hmac('sha256', $body, $secret);

    expect(MetaWebhookSignature::verify($body, $header, $secret))->toBeTrue();
});

test('it rejects a tampered body', function () {
    $secret = 'top-secret';
    $header = 'sha256='.hash_hmac('sha256', '{"hello":"world"}', $secret);

    expect(MetaWebhookSignature::verify('{"hello":"evil"}', $header, $secret))->toBeFalse();
});

test('it rejects a wrong secret', function () {
    $body = '{"a":1}';
    $header = 'sha256='.hash_hmac('sha256', $body, 'right');

    expect(MetaWebhookSignature::verify($body, $header, 'wrong'))->toBeFalse();
});

test('it fails closed on a missing header or empty secret', function () {
    $body = '{"a":1}';

    expect(MetaWebhookSignature::verify($body, null, 'secret'))->toBeFalse()
        ->and(MetaWebhookSignature::verify($body, '', 'secret'))->toBeFalse()
        ->and(MetaWebhookSignature::verify($body, 'sha1=abc', 'secret'))->toBeFalse()
        ->and(MetaWebhookSignature::verify($body, 'sha256='.hash_hmac('sha256', $body, 'secret'), ''))->toBeFalse();
});
