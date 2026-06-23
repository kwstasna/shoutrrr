<?php

namespace Database\Seeders;

use App\Enums\InstanceRole;
use App\Enums\WorkspaceRole;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceMembership;
use Illuminate\Database\Seeder;

class DefaultUserSeeder extends Seeder
{
    /**
     * Seed the default development user and workspace.
     */
    public function run(): void
    {
        $user = User::query()->firstOrCreate(
            ['email' => 'test@example.com'],
            [
                'name' => 'Test User',
                'password' => 'password',
                'email_verified_at' => now(),
                'instance_role' => InstanceRole::Owner,
            ],
        );

        if (! $user->isInstanceOwner()) {
            $user->forceFill(['instance_role' => InstanceRole::Owner])->save();
        }

        $workspace = Workspace::query()->firstOrCreate(
            ['slug' => 'test-workspace'],
            [
                'name' => 'Test Workspace',
                'owner_id' => $user->id,
                'timezone' => 'UTC',
            ],
        );

        WorkspaceMembership::query()->firstOrCreate(
            [
                'workspace_id' => $workspace->id,
                'user_id' => $user->id,
            ],
            ['role' => WorkspaceRole::Owner],
        );

        $user->forceFill(['current_workspace_id' => $workspace->id])->save();
    }
}
