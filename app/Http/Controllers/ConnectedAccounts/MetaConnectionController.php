<?php

declare(strict_types=1);

namespace App\Http\Controllers\ConnectedAccounts;

use App\Dto\ConnectedAccount\ConnectedAccountData;
use App\Enums\Platform;
use App\Http\Controllers\Controller;
use App\Models\ConnectedAccount;
use App\Services\ConnectedAccounts\AccountConnectionService;
use App\Services\ConnectedAccounts\Meta\MetaAssetEnumerator;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\AbstractProvider;
use Laravel\Socialite\Two\User as SocialiteUser;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * Runs a single Facebook Login flow shared by every Meta Graph platform
 * (Facebook, Instagram), enumerates the user's Pages/linked IG assets, and
 * lets them pick which assets to connect as which platform.
 *
 * The OAuth handshake is deliberately stateless: instead of relying on Socialite's
 * session-bound `state` (which can be lost across the redirect to Facebook behind a
 * TLS-terminating proxy / under Octane, yielding InvalidStateException even though
 * the login session itself is intact), we mint our own single-use, user-bound
 * `state` nonce in the cache and validate it on return. The enumerated assets are
 * likewise stashed in the cache between the callback and the "pick account" POST, so
 * the whole flow is independent of per-request session writes surviving the trip.
 */
class MetaConnectionController extends Controller
{
    /** How long the OAuth handshake + asset selection stay valid, in minutes. */
    private const int FLOW_TTL_MINUTES = 15;

    public function __construct(
        private readonly MetaAssetEnumerator $enumerator,
        private readonly AccountConnectionService $connections,
    ) {}

    public function redirect(Request $request): Response
    {
        $this->abortUnlessMetaFlowAvailable();

        $request->user()->can('create', ConnectedAccount::class) ?: abort(403);

        // Mint a single-use state nonce bound to this user and stash it in the cache
        // (not the session) so the handshake survives the round-trip to Facebook.
        $state = Str::random(40);
        Cache::put(self::stateKey($state), (string) $request->user()->id, now()->addMinutes(self::FLOW_TTL_MINUTES));

        return $this->driver()
            ->stateless()
            ->scopes($this->scopes())
            ->redirectUrl(route('accounts.meta.callback'))
            ->with(['state' => $state])
            ->redirect();
    }

    /**
     * The user-facing entry point (`redirect`) is gated here so the Meta flow is
     * never advertised until Facebook is configured and launched. `callback` and
     * `store` are deliberately NOT gated on `isLaunched()`: they must stay
     * exercisable by tests while Facebook is built-but-unlaunched, and `store`
     * independently blocks any account creation for a non-launched platform
     * (`Rule::in(launchedMetaGraphPlatforms)`), so reaching them early can stash
     * tokens in the caller's own session but can never mutate data. The gate is
     * re-evaluated on the launch task, when it becomes a no-op anyway.
     */
    private function abortUnlessMetaFlowAvailable(): void
    {
        if (! Platform::Facebook->isConfigured() || Platform::availableMetaGraphPlatforms() === []) {
            abort(404);
        }
    }

    public function callback(Request $request): RedirectResponse|InertiaResponse
    {
        $request->user()->can('create', ConnectedAccount::class) ?: abort(403);

        if ($request->filled('error')) {
            Log::warning('Meta OAuth provider returned an error.', [
                'error' => $request->query('error'),
                'error_description' => $request->query('error_description'),
            ]);

            return $this->failed('You declined to connect your Facebook account.');
        }

        // Validate our own cache-backed state nonce (single-use, bound to this user)
        // instead of Socialite's session-bound state.
        $state = (string) $request->query('state', '');

        if ($state === '' || Cache::pull(self::stateKey($state)) !== (string) $request->user()->id) {
            Log::warning('Meta OAuth state validation failed.', [
                'has_state' => $state !== '',
            ]);

            return $this->failed('Your Facebook connection expired before finishing. Please try again.');
        }

        try {
            $oauthUser = $this->driver()
                ->stateless()
                ->redirectUrl(route('accounts.meta.callback'))
                ->user();
        } catch (Throwable $exception) {
            Log::warning('Meta OAuth callback failed.', [
                'exception' => $exception::class,
                'message' => $exception->getMessage(),
            ]);

            return $this->failed("We couldn't connect your Facebook account. Please try again.");
        }

        if (! $oauthUser instanceof SocialiteUser) {
            return $this->failed("We couldn't read your Facebook profile. Please try again.");
        }

        try {
            $longLived = $this->enumerator->exchangeForLongLivedToken((string) $oauthUser->token);
            $assets = $this->enumerator->listPages($longLived['token']);
        } catch (Throwable $exception) {
            Log::warning('Meta Graph API call failed.', [
                'exception' => $exception::class,
                'message' => $exception->getMessage(),
            ]);

            return $this->failed("We couldn't retrieve your Facebook Pages. Please try again.");
        }

        $stashedAssets = [];
        foreach ($assets as $asset) {
            $stashedAssets[$asset->pageId] = [
                'pageId' => $asset->pageId,
                'pageName' => $asset->pageName,
                'pageAccessToken' => $asset->pageAccessToken,
                'igUserId' => $asset->igUserId,
                'igUsername' => $asset->igUsername,
                'igAvatarUrl' => $asset->igAvatarUrl,
            ];
        }

        Cache::put(self::assetsKey((string) $request->user()->id), [
            'assets' => $stashedAssets,
            'userTokenExpiresAt' => $longLived['expiresAt']?->toIso8601String(),
        ], now()->addMinutes(self::FLOW_TTL_MINUTES));

        return Inertia::render('accounts/connect-meta', [
            'assets' => $this->projectAssets($stashedAssets),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $request->user()->can('create', ConnectedAccount::class) ?: abort(403);

        $stash = Cache::get(self::assetsKey((string) $request->user()->id));
        /** @var array<string, array{pageId: string, pageName: string, pageAccessToken: string, igUserId: ?string, igUsername: ?string, igAvatarUrl: ?string}> $stashedAssets */
        $stashedAssets = is_array($stash) ? ($stash['assets'] ?? []) : [];

        $launchedPlatforms = array_map(
            fn (Platform $platform): string => $platform->value,
            Platform::launchedMetaGraphPlatforms(),
        );

        $validated = $request->validate([
            'selected' => ['required', 'array', 'min:1'],
            'selected.*.assetKey' => ['required', 'string', Rule::in(array_keys($stashedAssets))],
            'selected.*.platform' => ['required', 'string', Rule::in($launchedPlatforms)],
        ]);

        $created = 0;

        foreach ($validated['selected'] as $selection) {
            $asset = $stashedAssets[$selection['assetKey']];
            $platform = Platform::from($selection['platform']);

            // The asset must actually support the chosen platform — a linked IG
            // account is required for Instagram. The frontend only offers the
            // valid pairs (`availablePlatformsFor`), but a crafted request could
            // pick `instagram` for a Page with no linked IG account, which would
            // otherwise persist a ghost account with an empty remote id.
            if (! in_array($platform->value, $this->availablePlatformsFor($asset), true)) {
                throw ValidationException::withMessages([
                    'selected' => "{$platform->label()} is not available for the selected Page.",
                ]);
            }

            $this->connections->store(self::buildAccountData($asset, $platform), $request->user());
            $created++;
        }

        Cache::forget(self::assetsKey((string) $request->user()->id));

        return redirect()->route('accounts.index')->with(
            'success',
            $created === 1 ? '1 account connected.' : "{$created} accounts connected.",
        );
    }

    private static function stateKey(string $state): string
    {
        return 'meta-oauth:state:'.$state;
    }

    private static function assetsKey(string $userId): string
    {
        return 'meta-oauth:assets:'.$userId;
    }

    /**
     * Pure mapping from a stashed Meta asset to the DTO AccountConnectionService
     * persists. Extracted as a static method so the Facebook/Instagram mapping
     * can be unit-tested directly, without going through the gated HTTP route
     * (launchedMetaGraphPlatforms() is empty — and the whole flow inert — until
     * Facebook launches).
     *
     * @param  array{pageId: string, pageName: string, pageAccessToken: string, igUserId: ?string, igUsername: ?string, igAvatarUrl: ?string}  $stashedAsset
     */
    public static function buildAccountData(array $stashedAsset, Platform $platform): ConnectedAccountData
    {
        if ($platform === Platform::Instagram) {
            return new ConnectedAccountData(
                platform: Platform::Instagram,
                remoteAccountId: (string) $stashedAsset['igUserId'],
                handle: '@'.$stashedAsset['igUsername'],
                displayName: $stashedAsset['igUsername'],
                avatarUrl: $stashedAsset['igAvatarUrl'],
                authMethod: 'oauth',
                // IG publishing/comments/insights all authenticate with the
                // linked Page's token — the IG user id is just the target node.
                accessToken: $stashedAsset['pageAccessToken'],
                capabilities: ['page_id' => $stashedAsset['pageId']],
            );
        }

        return new ConnectedAccountData(
            platform: Platform::Facebook,
            remoteAccountId: $stashedAsset['pageId'],
            handle: $stashedAsset['pageName'],
            displayName: $stashedAsset['pageName'],
            avatarUrl: null,
            authMethod: 'oauth',
            accessToken: $stashedAsset['pageAccessToken'],
            // Page tokens minted from a long-lived user token don't expire.
            tokenExpiresAt: null,
        );
    }

    /**
     * The union of the launched Meta platforms' scopes, deduped, so a single
     * Facebook Login only asks for the permissions a launched platform
     * actually needs.
     *
     * @return list<string>
     */
    private function scopes(): array
    {
        $scopes = [];

        foreach (Platform::availableMetaGraphPlatforms() as $platform) {
            array_push($scopes, ...$platform->scopes());
        }

        return array_values(array_unique($scopes));
    }

    /**
     * @param  array<string, array{pageId: string, pageName: string, pageAccessToken: string, igUserId: ?string, igUsername: ?string, igAvatarUrl: ?string}>  $stashedAssets
     * @return list<array{key: string, pageId: string, pageName: string, igUserId: ?string, igUsername: ?string, igAvatarUrl: ?string, platforms: list<string>}>
     */
    private function projectAssets(array $stashedAssets): array
    {
        $projected = [];

        foreach ($stashedAssets as $key => $asset) {
            $projected[] = [
                'key' => $key,
                'pageId' => $asset['pageId'],
                'pageName' => $asset['pageName'],
                'igUserId' => $asset['igUserId'],
                'igUsername' => $asset['igUsername'],
                'igAvatarUrl' => $asset['igAvatarUrl'],
                'platforms' => $this->availablePlatformsFor($asset),
            ];
        }

        return $projected;
    }

    /**
     * @param  array{igUserId: ?string}  $asset
     * @return list<string>
     */
    private function availablePlatformsFor(array $asset): array
    {
        $available = Platform::availableMetaGraphPlatforms();
        $platforms = [];

        if (in_array(Platform::Facebook, $available, true)) {
            $platforms[] = Platform::Facebook->value;
        }

        if (in_array(Platform::Instagram, $available, true) && $asset['igUserId'] !== null) {
            $platforms[] = Platform::Instagram->value;
        }

        return $platforms;
    }

    private function failed(string $message): RedirectResponse
    {
        return redirect()->route('accounts.index')->with('error', $message);
    }

    private function driver(): AbstractProvider
    {
        $driver = Socialite::driver('facebook');

        if (! $driver instanceof AbstractProvider) {
            abort(404);
        }

        return $driver;
    }
}
