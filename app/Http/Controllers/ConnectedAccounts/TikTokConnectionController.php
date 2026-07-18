<?php

declare(strict_types=1);

namespace App\Http\Controllers\ConnectedAccounts;

use App\Dto\ConnectedAccount\ConnectedAccountData;
use App\Enums\Platform;
use App\Http\Controllers\Controller;
use App\Models\ConnectedAccount;
use App\Services\ConnectedAccounts\AccountConnectionService;
use App\Services\ConnectedAccounts\TikTok\TikTokOAuthConnector;
use App\Support\InstanceSettings;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/**
 * TikTok's connect flow.
 *
 * Dedicated rather than folded into the generic OAuthConnectionController because
 * that controller is entirely Socialite-driven and TikTok has no driver — see
 * Platform::usesDedicatedConnectionFlow(), which makes the generic route 404 for
 * TikTok so the two can never both claim it.
 *
 * State is carried in the `state` parameter and verified against the session on
 * the way back: TikTok rejects redirect URIs with query parameters, so there is
 * nowhere else to put it.
 */
class TikTokConnectionController extends Controller
{
    private const string SESSION_KEY = 'accounts.tiktok.oauth_state';

    public function __construct(
        private readonly TikTokOAuthConnector $connector,
        private readonly AccountConnectionService $connections,
    ) {}

    public function redirect(Request $request): RedirectResponse
    {
        $this->guardConnectable($request);

        $state = Str::random(64);
        $request->session()->put(self::SESSION_KEY, $state);

        return redirect()->away($this->connector->authorizationUrl($state, $this->callbackUrl()));
    }

    public function callback(Request $request): RedirectResponse
    {
        $this->guardConnectable($request);

        // TikTok bounces back with an error rather than a code when the user
        // cancels on the consent screen.
        if ($request->filled('error')) {
            Log::warning('TikTok OAuth returned an error.', [
                'error' => $request->query('error'),
                'error_description' => $request->query('error_description'),
            ]);

            return $this->failed($request->query('error') === 'access_denied'
                ? 'You declined to connect your TikTok account.'
                : "TikTok couldn't complete the connection. Please try again.");
        }

        $expected = $request->session()->pull(self::SESSION_KEY);
        $state = (string) $request->query('state', '');

        // Single-use: pull() above already consumed it, so a replayed callback
        // cannot pass this check.
        if (! is_string($expected) || $expected === '' || ! hash_equals($expected, $state)) {
            return $this->failed('That TikTok connection link has expired. Please try again.');
        }

        $code = (string) $request->query('code', '');

        if ($code === '') {
            return $this->failed("TikTok didn't return an authorization code. Please try again.");
        }

        try {
            $tokens = $this->connector->exchangeCode($code, $this->callbackUrl());
            $profile = $this->connector->fetchUserInfo($tokens['accessToken']);
        } catch (Throwable $exception) {
            Log::warning('TikTok OAuth exchange failed.', [
                'exception' => $exception::class,
                'message' => $exception->getMessage(),
            ]);

            return $this->failed($this->failureMessage($exception));
        }

        // open_id identifies the user for this app specifically. Prefer the one
        // from the token response and fall back to the profile call, since either
        // may be blank depending on granted scopes.
        $remoteId = $tokens['openId'] !== '' ? $tokens['openId'] : $profile['openId'];

        if ($remoteId === '') {
            return $this->failed("We couldn't read your TikTok profile. Please try again.");
        }

        $this->connections->store(new ConnectedAccountData(
            platform: Platform::TikTok,
            remoteAccountId: $remoteId,
            handle: $profile['username'],
            displayName: $profile['displayName'],
            avatarUrl: $profile['avatarUrl'],
            authMethod: 'oauth',
            accessToken: $tokens['accessToken'],
            refreshToken: $tokens['refreshToken'],
            tokenExpiresAt: $tokens['expiresAt'],
        ), $request->user());

        return redirect()->route('accounts.index')->with('success', 'TikTok account connected.');
    }

    /**
     * 404 unless this instance can actually connect TikTok right now — mirroring
     * the gate OAuthConnectionController::resolveOAuthPlatform() applies to the
     * Socialite platforms, so an unlaunched or disabled TikTok is not reachable
     * by URL even though its route is always registered.
     */
    private function guardConnectable(Request $request): void
    {
        $request->user()->can('create', ConnectedAccount::class) ?: abort(403);

        if (
            ! Platform::TikTok->isConfigured()
            || ! Platform::TikTok->isLaunched()
            || ! app(InstanceSettings::class)->platformAvailable(Platform::TikTok)
        ) {
            abort(404);
        }
    }

    /**
     * TikTok only accepts redirect URIs registered in its portal, and they must be
     * static — so unlike the Socialite platforms (which derive the callback from
     * the current request host), this must match the configured value exactly.
     * Falls back to the route so a missing TIKTOK_REDIRECT_URI still works when
     * APP_URL is correct.
     */
    private function callbackUrl(): string
    {
        $configured = (string) config('services.tiktok.redirect');

        return $configured !== '' ? $configured : route('accounts.tiktok.callback');
    }

    private function failed(string $message): RedirectResponse
    {
        return redirect()->route('accounts.index')->with('error', $message);
    }

    private function failureMessage(Throwable $exception): string
    {
        $message = $exception->getMessage();

        return match (true) {
            str_contains($message, 'scope') => "Your TikTok app is missing a required permission. Check the app's configured scopes, then try again.",
            str_contains($message, 'redirect_uri') => "TikTok rejected this app's redirect URI. Make sure TIKTOK_REDIRECT_URI is registered in the TikTok developer portal exactly as configured.",
            str_contains($message, 'client_key'), str_contains($message, 'client_secret') => "TikTok rejected this app's credentials. Check TIKTOK_CLIENT_KEY and TIKTOK_CLIENT_SECRET.",
            default => "We couldn't connect your TikTok account. Please try again.",
        };
    }
}
