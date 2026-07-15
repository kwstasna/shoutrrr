<?php

declare(strict_types=1);

namespace App\Services\Webhooks;

/**
 * Verifies the `X-Hub-Signature-256` header Meta attaches to every webhook POST.
 *
 * Meta signs the raw request body with HMAC-SHA256 keyed by the app secret and
 * sends the hex digest as `sha256=<digest>`. Verification recomputes the digest
 * over the exact bytes received and compares in constant time. It fails closed: a
 * missing secret, a missing/malformed header, or any mismatch returns false.
 */
final class MetaWebhookSignature
{
    public static function verify(string $rawBody, ?string $header, string $secret): bool
    {
        $header = (string) $header;

        if ($secret === '' || ! str_starts_with($header, 'sha256=')) {
            return false;
        }

        $expected = 'sha256='.hash_hmac('sha256', $rawBody, $secret);

        return hash_equals($expected, $header);
    }
}
