<?php

namespace App\Providers;

use App\Enums\Platform;
use App\Listeners\BindWorkspaceToAccessToken;
use App\Listeners\SetCurrentWorkspaceOnLogin;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Events\Login;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;
use Laravel\Passport\Events\AccessTokenCreated;
use Laravel\Passport\Passport;
use Override;
use RuntimeException;
use Symfony\Component\HttpFoundation\Response;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    #[Override]
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->configureDefaults();

        // OAuth tokens issued for the MCP/API integration. Without explicit
        // lifetimes Passport defaults to ~1 year, so a leaked bearer is
        // effectively permanent.
        Passport::tokensExpireIn(now()->addHours(8));
        Passport::refreshTokensExpireIn(now()->addDays(30));
        Passport::personalAccessTokensExpireIn(now()->addDays(30));

        RateLimiter::for('mcp', fn ($request) => Limit::perMinute(60)
            ->by($request->user()?->id ?: $request->ip()));

        // Per-platform throttle for outbound metrics-capture jobs so a large
        // account/post list can't trip the platforms' own rate limits.
        foreach (Platform::cases() as $platform) {
            RateLimiter::for("metrics-{$platform->value}", fn (): Limit => Limit::perMinute(30));
        }

        Gate::before(function (User $user, string $ability): ?bool {
            if (! str_starts_with($ability, 'workspace.')) {
                return null;
            }

            return $user->hasAllPermissions([$ability], Context::get('workspace_id'));
        });

        Event::listen(Login::class, SetCurrentWorkspaceOnLogin::class);
        Event::listen(AccessTokenCreated::class, BindWorkspaceToAccessToken::class);

        Passport::authorizationView(
            /** @param array<string, mixed> $parameters */
            function (array $parameters): Response {
                $user = request()->user();

                return response()->view('oauth.authorize', array_merge($parameters, [
                    'workspaces' => $user
                        ? $user->workspaceMemberships()->with('workspace')->get()
                            ->pluck('workspace')->filter()->values()
                        : collect(),
                ]));
            }
        );

    }

    /**
     * Configure default behaviors for production-ready applications.
     */
    protected function configureDefaults(): void
    {
        $this->guardAgainstProductionDebug();

        Date::use(CarbonImmutable::class);

        DB::prohibitDestructiveCommands(
            app()->isProduction(),
        );

        Password::defaults(fn (): ?Password => app()->isProduction()
            ? Password::min(12)
                ->mixedCase()
                ->letters()
                ->numbers()
                ->symbols()
                ->uncompromised()
            : null,
        );
    }

    /**
     * Refuse to boot a production app with debug mode on — it would expose full
     * stack traces and Ignition to the public.
     */
    public function guardAgainstProductionDebug(): void
    {
        if (app()->isProduction() && config('app.debug')) {
            throw new RuntimeException('APP_DEBUG must be false in production. Refusing to boot.');
        }
    }
}
