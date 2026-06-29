<?php

use App\Enums\ConnectedAccountStatus;
use App\Enums\Platform;
use App\Models\ConnectedAccount;
use App\Models\ConnectedAccountSecret;
use Illuminate\Support\Facades\Http;

test('it proactively refreshes accounts expiring within six hours', function () {
    $account = ConnectedAccount::factory()->create([
        'platform' => Platform::X->value,
        'token_expires_at' => now()->addHours(5),
    ]);
    ConnectedAccountSecret::factory()->create([
        'connected_account_id' => $account->id,
        'refresh_token' => 'r',
    ]);

    Http::fake([
        'https://api.twitter.com/2/oauth2/token' => Http::response(['access_token' => 'fresh', 'expires_in' => 7200]),
    ]);

    $this->artisan('accounts:refresh-tokens')->assertExitCode(0);

    expect($account->fresh()->secret->access_token)->toBe('fresh')
        ->and($account->fresh()->refresh_failed_at)->toBeNull()
        ->and($account->fresh()->refresh_failure_reason)->toBeNull();
});

test('it flips status to needs attention on refresh failure and keeps going', function () {
    $account = ConnectedAccount::factory()->create([
        'platform' => Platform::X->value,
        'token_expires_at' => now()->addMinutes(5),
    ]);
    ConnectedAccountSecret::factory()->create(['connected_account_id' => $account->id, 'refresh_token' => 'r']);

    Http::fake(['https://api.twitter.com/2/oauth2/token' => Http::response([], 400)]);

    $this->artisan('accounts:refresh-tokens')->assertExitCode(0);

    expect($account->fresh()->status)->toBe(ConnectedAccountStatus::NeedsAttention)
        ->and($account->fresh()->refresh_failed_at)->not->toBeNull()
        ->and($account->fresh()->refresh_failure_reason)->toContain('400');
});

test('it skips accounts that expire outside the proactive refresh window', function () {
    ConnectedAccount::factory()->create([
        'platform' => Platform::X->value,
        'token_expires_at' => now()->addHours(7),
    ])->secret()->create([
        'access_token' => 'still-good',
        'refresh_token' => 'r',
    ]);

    Http::fake([
        'https://api.twitter.com/2/oauth2/token' => Http::response(['access_token' => 'fresh', 'expires_in' => 7200]),
    ]);

    $this->artisan('accounts:refresh-tokens')->assertExitCode(0);

    Http::assertNothingSent();
});

test('token refresh health check is scheduled every fifteen minutes', function () {
    expect(file_get_contents(base_path('routes/console.php')))
        ->toContain('Schedule::command(RefreshExpiringTokens::class)->everyFifteenMinutes()->withoutOverlapping();');
});
