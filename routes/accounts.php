<?php

declare(strict_types=1);

use App\Http\Controllers\ConnectedAccounts\BlueskyConnectionController;
use App\Http\Controllers\ConnectedAccounts\BlueskyOAuthController;
use App\Http\Controllers\ConnectedAccounts\ConnectedAccountController;
use App\Http\Controllers\ConnectedAccounts\DiscordConnectionController;
use App\Http\Controllers\ConnectedAccounts\MetaConnectionController;
use App\Http\Controllers\ConnectedAccounts\OAuthConnectionController;
use App\Http\Controllers\ConnectedAccounts\TikTokConnectionController;
use App\Http\Controllers\ConnectedAccounts\TikTokCreatorInfoController;
use App\Http\Controllers\OAuth\BlueskyClientMetadataController;
use App\Models\ConnectedAccount;
use Illuminate\Support\Facades\Route;

Route::get('oauth/bluesky/client-metadata.json', BlueskyClientMetadataController::class)
    ->name('oauth.bluesky.metadata');

Route::get('oauth/bluesky/jwks.json', [BlueskyClientMetadataController::class, 'jwks'])
    ->name('oauth.bluesky.jwks');

// Route-model binding runs in SubstituteBindings, which executes before the
// appended WorkspaceMiddleware sets the workspace Context — so the model's global
// workspace scope is a no-op here. Scope the lookup to the authenticated user's
// current workspace explicitly so a foreign id 404s (rather than leaking existence).
Route::bind('account', fn (string $value): ConnectedAccount => ConnectedAccount::query()
    ->where('workspace_id', request()->user()?->current_workspace_id)
    ->whereKey($value)
    ->firstOrFail());

Route::middleware(['auth', 'verified'])->group(function (): void {
    Route::get('accounts', [ConnectedAccountController::class, 'index'])->name('accounts.index');

    Route::get('accounts/connect/bluesky/oauth', [BlueskyOAuthController::class, 'redirect'])
        ->middleware('throttle:10,1')
        ->name('accounts.bluesky.oauth');

    Route::get('accounts/callback/bluesky/oauth', [BlueskyOAuthController::class, 'callback'])
        ->middleware('throttle:10,1')
        ->name('accounts.bluesky.oauth.callback');

    Route::post('accounts/connect/bluesky', [BlueskyConnectionController::class, 'store'])
        ->middleware('throttle:10,1')
        ->name('accounts.bluesky.store');

    Route::post('accounts/connect/discord', [DiscordConnectionController::class, 'store'])
        ->middleware('throttle:10,1')
        ->name('accounts.discord.store');

    // These bespoke `accounts/{connect,callback}/meta` routes must be registered
    // before the generic `{platform}` wildcard routes below — Laravel matches
    // routes in registration order, and Platform::tryFrom('meta') is null, so
    // the wildcard route's resolveOAuthPlatform() would otherwise 404 first.
    Route::get('accounts/connect/meta', [MetaConnectionController::class, 'redirect'])
        ->middleware('throttle:10,1')
        ->name('accounts.meta.redirect');

    Route::get('accounts/callback/meta', [MetaConnectionController::class, 'callback'])
        ->middleware('throttle:10,1')
        ->name('accounts.meta.callback');

    // The stateless asset-selection screen. `callback` redirects here (PRG) after
    // consuming the single-use OAuth state, so reloading the selection page never
    // re-hits the callback with an already-consumed nonce.
    Route::get('accounts/select/meta', [MetaConnectionController::class, 'select'])
        ->middleware('throttle:10,1')
        ->name('accounts.meta.select');

    Route::post('accounts/connect/meta', [MetaConnectionController::class, 'store'])
        ->middleware('throttle:10,1')
        ->name('accounts.meta.store');

    // Registered before the `{platform}` wildcard for the same reason as the Meta
    // routes above: TikTok has no Socialite driver, so it must reach its own
    // controller rather than the generic one (which 404s it — see
    // Platform::usesDedicatedConnectionFlow). Unlike Meta, the callback path here
    // is literally `/accounts/callback/tiktok`, which WOULD match the wildcard,
    // so this ordering is load-bearing rather than merely tidy.
    Route::get('accounts/connect/tiktok', [TikTokConnectionController::class, 'redirect'])
        ->middleware('throttle:10,1')
        ->name('accounts.tiktok.redirect');

    Route::get('accounts/callback/tiktok', [TikTokConnectionController::class, 'callback'])
        ->middleware('throttle:10,1')
        ->name('accounts.tiktok.callback');

    // The composer calls this before rendering the TikTok options, per TikTok's
    // requirement that the posting UI is built from fresh creator info rather
    // than a cached or hardcoded list. Throttled generously because it fires on
    // tab activation, not on keystrokes; TikTok allows 20/min per account.
    Route::get('accounts/{account}/tiktok/creator-info', TikTokCreatorInfoController::class)
        ->middleware('throttle:20,1')
        ->name('accounts.tiktok.creator-info');

    Route::get('accounts/connect/{platform}', [OAuthConnectionController::class, 'redirect'])
        ->middleware('throttle:10,1')
        ->name('accounts.connect');

    Route::get('accounts/callback/{platform}', [OAuthConnectionController::class, 'callback'])
        ->middleware('throttle:10,1')
        ->name('accounts.callback');

    Route::post('accounts/{account}/reconnect', [ConnectedAccountController::class, 'reconnect'])
        ->middleware('throttle:10,1')
        ->name('accounts.reconnect');

    Route::post('accounts/{account}/default', [ConnectedAccountController::class, 'makeDefault'])
        ->name('accounts.default');

    Route::patch('accounts/{account}/toggle', [ConnectedAccountController::class, 'toggle'])
        ->middleware('throttle:30,1')
        ->name('accounts.toggle');

    Route::delete('accounts/{account}', [ConnectedAccountController::class, 'destroy'])
        ->name('accounts.destroy');
});
