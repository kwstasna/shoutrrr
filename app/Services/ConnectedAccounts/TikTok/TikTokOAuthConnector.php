<?php

declare(strict_types=1);

namespace App\Services\ConnectedAccounts\TikTok;

use App\Enums\Platform;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Date;
use RuntimeException;

/**
 * TikTok's OAuth 2.0 authorization-code flow.
 *
 * Written by hand rather than through Socialite because TikTok ships no
 * first-party driver and deviates from the conventions Socialite assumes:
 *
 *  - The client identifier parameter is `client_key`, not `client_id`.
 *  - `scope` is COMMA-separated, not space-separated as in the OAuth 2.0 spec.
 *  - The authorization host (www.tiktok.com) differs from the API host
 *    (open.tiktokapis.com).
 *  - Every response — success or failure — carries an `error` envelope, so a 200
 *    does not imply success.
 *
 * PKCE is deliberately not used: TikTok documents `code_verifier` as required for
 * mobile and desktop apps only, and this is a confidential web client
 * authenticating with a client secret.
 */
class TikTokOAuthConnector
{
    private const string AUTHORIZE_URL = 'https://www.tiktok.com/v2/auth/authorize/';

    private const string TOKEN_URL = 'https://open.tiktokapis.com/v2/oauth/token/';

    private const string USER_INFO_URL = 'https://open.tiktokapis.com/v2/user/info/';

    public function __construct(private readonly HttpFactory $http) {}

    /**
     * Build the consent URL to send the user to. TikTok requires `state` (most
     * providers treat it as optional) and rejects any redirect_uri carrying query
     * parameters, so per-connection context must ride in `state` alone.
     */
    public function authorizationUrl(string $state, string $redirectUri): string
    {
        return self::AUTHORIZE_URL.'?'.http_build_query([
            'client_key' => $this->clientKey(),
            'response_type' => 'code',
            // Comma-separated, per TikTok — a space-separated list is rejected.
            'scope' => implode(',', Platform::TikTok->scopes()),
            'redirect_uri' => $redirectUri,
            'state' => $state,
        ]);
    }

    /**
     * Exchange an authorization code for tokens.
     *
     * @return array{accessToken: string, refreshToken: string, expiresAt: CarbonImmutable, openId: string, scopes: list<string>}
     */
    public function exchangeCode(string $code, string $redirectUri): array
    {
        $response = $this->http->asForm()->post(self::TOKEN_URL, [
            'client_key' => $this->clientKey(),
            'client_secret' => $this->clientSecret(),
            'code' => $code,
            'grant_type' => 'authorization_code',
            'redirect_uri' => $redirectUri,
        ]);

        return $this->tokensFrom($response);
    }

    /**
     * Swap a refresh token for a fresh access token.
     *
     * TikTok MAY return a different refresh token than the one supplied, and the
     * old one then stops working — so the caller must always persist what comes
     * back rather than assuming its stored token survives.
     *
     * @return array{accessToken: string, refreshToken: string, expiresAt: CarbonImmutable, openId: string, scopes: list<string>}
     */
    public function refresh(string $refreshToken): array
    {
        $response = $this->http->asForm()->post(self::TOKEN_URL, [
            'client_key' => $this->clientKey(),
            'client_secret' => $this->clientSecret(),
            'grant_type' => 'refresh_token',
            'refresh_token' => $refreshToken,
        ]);

        return $this->tokensFrom($response);
    }

    /**
     * Fetch the profile shown on the account card.
     *
     * `username` is the @handle and needs the `user.info.profile` scope — it is
     * NOT part of `user.info.basic`, which is a common integration trap. It falls
     * back to open_id so a connection never fails over a missing display field.
     *
     * @return array{openId: string, username: string, displayName: ?string, avatarUrl: ?string}
     */
    public function fetchUserInfo(string $accessToken): array
    {
        $response = $this->http->withToken($accessToken)
            ->get(self::USER_INFO_URL, ['fields' => 'open_id,union_id,avatar_url,display_name,username']);

        $this->throwUnlessOk($response, 'Could not read the TikTok profile.');

        $openId = (string) ($response->json('data.user.open_id') ?? '');
        $username = (string) ($response->json('data.user.username') ?? '');
        $displayName = $response->json('data.user.display_name');
        $avatarUrl = $response->json('data.user.avatar_url');

        return [
            'openId' => $openId,
            'username' => $username !== '' ? '@'.ltrim($username, '@') : $openId,
            'displayName' => is_string($displayName) && $displayName !== '' ? $displayName : null,
            'avatarUrl' => is_string($avatarUrl) && $avatarUrl !== '' ? $avatarUrl : null,
        ];
    }

    /**
     * @return array{accessToken: string, refreshToken: string, expiresAt: CarbonImmutable, openId: string, scopes: list<string>}
     */
    private function tokensFrom(Response $response): array
    {
        $this->throwUnlessOk($response, 'TikTok rejected the token request.');

        $accessToken = (string) ($response->json('access_token') ?? '');
        $refreshToken = (string) ($response->json('refresh_token') ?? '');
        $expiresIn = (int) ($response->json('expires_in') ?? 0);
        $openId = (string) ($response->json('open_id') ?? '');
        $scope = (string) ($response->json('scope') ?? '');

        if ($accessToken === '' || $refreshToken === '') {
            throw new RuntimeException('TikTok did not return a usable token pair.');
        }

        return [
            'accessToken' => $accessToken,
            'refreshToken' => $refreshToken,
            // TikTok access tokens last 24h. Fall back to that if expires_in is
            // absent so the token is never treated as immediately stale (which
            // TokenManager reads as "needs refresh" on every single call).
            'expiresAt' => Date::now()->addSeconds($expiresIn > 0 ? $expiresIn : 86_400)->toImmutable(),
            'openId' => $openId,
            'scopes' => $scope === '' ? [] : explode(',', $scope),
        ];
    }

    /**
     * TikTok's OAuth endpoints report failures two different ways: a top-level
     * {error, error_description} on the token endpoint, and a nested
     * {error: {code, message}} envelope on the API endpoints. Both are checked,
     * because either can arrive with an HTTP 200.
     */
    private function throwUnlessOk(Response $response, string $context): void
    {
        $error = $response->json('error');

        // Token endpoint: `error` is a string, and "ok" is not used here — the
        // field is simply absent on success.
        if (is_string($error) && $error !== '' && $error !== 'ok') {
            $description = (string) ($response->json('error_description') ?? $error);

            throw new RuntimeException("{$context} ({$error}: {$description})");
        }

        // API endpoints: `error` is an object whose `code` is "ok" on success.
        if (is_array($error)) {
            $code = (string) ($error['code'] ?? 'ok');

            if ($code !== 'ok' && $code !== '') {
                $message = (string) ($error['message'] ?? $code);

                throw new RuntimeException("{$context} ({$code}: {$message})");
            }
        }

        if ($response->failed()) {
            throw new RuntimeException("{$context} (HTTP {$response->status()})");
        }
    }

    private function clientKey(): string
    {
        // Stored under `client_id` so Platform::isConfigured() can read it
        // generically; TikTok calls it "client key" on the wire and in its portal.
        return (string) config('services.tiktok.client_id');
    }

    private function clientSecret(): string
    {
        return (string) config('services.tiktok.client_secret');
    }
}
