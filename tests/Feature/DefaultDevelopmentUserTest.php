<?php

use App\Enums\WorkspaceRole;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceMembership;
use Database\Seeders\DatabaseSeeder;

it('seeds the default user with a current workspace in development', function (): void {
    $this->app->detectEnvironment(fn () => 'local');

    $this->seed(DatabaseSeeder::class);

    $user = User::query()->where('email', 'test@example.com')->firstOrFail();

    expect($user->name)->toBe('Test User')
        ->and($user->isInstanceOwner())->toBeTrue()
        ->and($user->current_workspace_id)->not->toBeNull()
        ->and(Workspace::query()->whereKey($user->current_workspace_id)->exists())->toBeTrue()
        ->and(WorkspaceMembership::query()
            ->where('workspace_id', $user->current_workspace_id)
            ->where('user_id', $user->id)
            ->where('role', WorkspaceRole::Owner)
            ->exists())->toBeTrue();
});

it('does not seed the default user outside development', function (): void {
    $this->app->detectEnvironment(fn () => 'production');

    $this->artisan('db:seed', [
        '--class' => DatabaseSeeder::class,
        '--force' => true,
    ])->assertExitCode(0);

    expect(User::query()->where('email', 'test@example.com')->exists())->toBeFalse();
});
