<?php

namespace App\Http\Middleware;

use App\Enums\SocialProvider;
use App\Models\User;
use App\Models\WorkspaceMembership;
use Illuminate\Http\Request;
use Inertia\Middleware;
use Override;

class HandleInertiaRequests extends Middleware
{
    /**
     * The root template that's loaded on the first page visit.
     *
     * @see https://inertiajs.com/server-side-setup#root-template
     *
     * @var string
     */
    #[Override]
    protected $rootView = 'app';

    /**
     * Determines the current asset version.
     *
     * @see https://inertiajs.com/asset-versioning
     */
    #[Override]
    public function version(Request $request): ?string
    {
        return parent::version($request);
    }

    /**
     * Define the props that are shared by default.
     *
     * @see https://inertiajs.com/shared-data
     *
     * @return array<string, mixed>
     */
    #[Override]
    public function share(Request $request): array
    {
        return [
            ...parent::share($request),
            'name' => config('app.name'),
            'auth' => [
                'user' => $request->user(),
            ],
            'sidebarOpen' => ! $request->hasCookie('sidebar_state') || $request->cookie('sidebar_state') === 'true',
            'workspaces' => $this->workspacesData($request->user()),
            'socialite' => [
                'providers' => SocialProvider::enabledProviders(),
            ],
            'flash' => [
                'success' => $request->session()->get('success'),
                'error' => $request->session()->get('error'),
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function workspacesData(?User $user): array
    {
        $enabled = (bool) config('kit.workspaces.enabled');

        if (! $user) {
            return [
                'enabled' => $enabled,
                'all' => [],
                'current' => null,
                'canCreateWorkspaces' => (bool) config('kit.workspaces.can_create_workspaces'),
            ];
        }

        $memberships = $user->workspaceMemberships()->with('workspace.postingSchedule')->get();

        $all = $memberships->map(fn (WorkspaceMembership $m) => [
            'id' => $m->workspace->id,
            'name' => $m->workspace->name,
            'role' => $m->role->value,
            'logo' => $m->workspace->logo,
        ])->values()->all();

        $current = null;
        if ($user->current_workspace_id) {
            $membership = $memberships->firstWhere('workspace_id', $user->current_workspace_id);

            if ($membership) {
                $current = [
                    'id' => $membership->workspace->id,
                    'name' => $membership->workspace->name,
                    'role' => $membership->role->value,
                    'logo' => $membership->workspace->logo,
                    'permissions' => $membership->permissions,
                    'timezone' => $membership->workspace->postingSchedule->timezone ?? 'UTC',
                ];
            }
        }

        return [
            'enabled' => $enabled,
            'all' => $all,
            'current' => $current,
            'canCreateWorkspaces' => (bool) config('kit.workspaces.can_create_workspaces'),
        ];
    }
}
