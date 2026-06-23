<?php

declare(strict_types=1);

namespace App\Services\ConnectedAccounts;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Support\Facades\Log;

class XAccountCapabilities
{
    private const string USER_URL = 'https://api.twitter.com/2/users/me';

    private const int STANDARD_TEXT_LENGTH = 280;

    private const int PREMIUM_TEXT_LENGTH = 25_000;

    public function __construct(private readonly HttpFactory $http) {}

    /**
     * @return array{x_premium: bool, max_text_length: int, verified_type: string|null}
     */
    public function forAccessToken(?string $token): array
    {
        if ($token === null || $token === '') {
            return self::fromUserData([]);
        }

        try {
            $response = $this->http
                ->withToken($token)
                ->acceptJson()
                ->timeout(5)
                ->connectTimeout(3)
                ->get(self::USER_URL, [
                    'user.fields' => 'verified,verified_type',
                ]);
        } catch (ConnectionException $exception) {
            Log::warning('Could not detect X account capabilities.', [
                'exception' => $exception::class,
                'message' => $exception->getMessage(),
            ]);

            return self::fromUserData([]);
        }

        if ($response->failed()) {
            Log::warning('X account capabilities lookup failed.', [
                'status' => $response->status(),
            ]);

            return self::fromUserData([]);
        }

        return self::fromUserData((array) $response->json('data', []));
    }

    /**
     * @param  array<string, mixed>  $user
     * @return array{x_premium: bool, max_text_length: int, verified_type: string|null}
     */
    public static function fromUserData(array $user): array
    {
        $verifiedType = isset($user['verified_type']) ? (string) $user['verified_type'] : null;
        $isPremium = in_array($verifiedType, ['blue', 'business', 'government'], true);

        return [
            'x_premium' => $isPremium,
            'max_text_length' => $isPremium ? self::PREMIUM_TEXT_LENGTH : self::STANDARD_TEXT_LENGTH,
            'verified_type' => $verifiedType,
        ];
    }
}
