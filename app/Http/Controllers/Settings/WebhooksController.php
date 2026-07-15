<?php

declare(strict_types=1);

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceWebhook;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Manages a workspace's Meta (Instagram) webhook receiver configuration from
 * Workspace Settings. One config per workspace: create it, view its callback URL +
 * verify token to paste into the Meta App Dashboard, roll its tokens, send a live
 * self-test, or delete it.
 */
class WebhooksController extends Controller
{
    public function index(Request $request): Response
    {
        /** @var User $user */
        $user = $request->user();
        $workspace = $user->currentWorkspace;
        abort_if($workspace === null, 404);
        $this->authorizeManage($user, $workspace->id);

        return Inertia::render('settings/workspace/webhooks', [
            'webhook' => $this->present($workspace),
            'globalSecretConfigured' => filled(config('services.facebook.client_secret')),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();
        $workspace = $user->currentWorkspace;
        abort_if($workspace === null, 404);
        $this->authorizeManage($user, $workspace->id);

        $validated = $request->validate([
            'signing_secret' => ['nullable', 'string', 'max:255'],
        ]);

        WorkspaceWebhook::query()->updateOrCreate(
            ['workspace_id' => $workspace->id],
            [
                'provider' => 'meta',
                'endpoint_token' => WorkspaceWebhook::freshEndpointToken(),
                'verify_token' => WorkspaceWebhook::freshVerifyToken(),
                'signing_secret' => ($validated['signing_secret'] ?? null) ?: null,
            ],
        );

        return back()->with('success', 'Webhook created.');
    }

    public function regenerate(Request $request): RedirectResponse
    {
        $webhook = $this->ownedWebhook($request);

        $webhook->forceFill([
            'endpoint_token' => WorkspaceWebhook::freshEndpointToken(),
            'verify_token' => WorkspaceWebhook::freshVerifyToken(),
        ])->save();

        return back()->with('success', 'Webhook URL and verify token regenerated. Update them in the Meta App Dashboard.');
    }

    public function test(Request $request): RedirectResponse
    {
        $webhook = $this->ownedWebhook($request);

        $secret = $webhook->effectiveSigningSecret();

        if ($secret === '') {
            return back()->with('error', 'No app secret is configured. Set FACEBOOK_CLIENT_SECRET or a per-workspace signing secret first.');
        }

        // Loop back through the real public callback URL with a signed sample event,
        // proving the endpoint is reachable and the signature verifies end-to-end,
        // exactly as a Meta delivery would. The synthetic media id matches no post,
        // so nothing is written.
        $payload = [
            'object' => 'instagram',
            'entry' => [[
                'id' => '0',
                'time' => 0,
                'changes' => [[
                    'field' => 'story_insights',
                    'value' => ['media_id' => 'webhook-self-test'],
                ]],
            ]],
        ];
        $raw = (string) json_encode($payload);
        $signature = 'sha256='.hash_hmac('sha256', $raw, $secret);

        try {
            $response = Http::timeout(10)
                ->withHeaders(['X-Hub-Signature-256' => $signature])
                ->withBody($raw, 'application/json')
                ->post($webhook->callbackUrl());
        } catch (ConnectionException $e) {
            return back()->with('error', 'Could not reach the callback URL: '.$e->getMessage());
        }

        if ($response->successful()) {
            return back()->with('success', 'Success — your endpoint is publicly reachable and the signature verified (HTTP '.$response->status().').');
        }

        // A 403 here almost always means the effective app secret does not match the
        // one Meta signs with; surface that specifically.
        $hint = $response->status() === 403
            ? ' The signature was rejected — check that your app secret matches the Meta app.'
            : '';

        return back()->with('error', 'The endpoint responded with HTTP '.$response->status().'.'.$hint);
    }

    public function destroy(Request $request): RedirectResponse
    {
        $this->ownedWebhook($request)->delete();

        return back()->with('success', 'Webhook deleted.');
    }

    /**
     * @return array{endpoint_token: string, callback_url: string, verify_token: string, has_custom_secret: bool, last_received_at: string|null, last_event: string|null, received_count: int}|null
     */
    private function present(Workspace $workspace): ?array
    {
        $webhook = $workspace->webhook;

        if ($webhook === null) {
            return null;
        }

        return [
            'endpoint_token' => $webhook->endpoint_token,
            'callback_url' => $webhook->callbackUrl(),
            'verify_token' => $webhook->verify_token,
            'has_custom_secret' => $webhook->signing_secret !== null,
            'last_received_at' => $webhook->last_received_at?->toIso8601String(),
            'last_event' => $webhook->last_event,
            'received_count' => $webhook->received_count,
        ];
    }

    private function ownedWebhook(Request $request): WorkspaceWebhook
    {
        /** @var User $user */
        $user = $request->user();
        $workspace = $user->currentWorkspace;
        abort_if($workspace === null, 404);
        $this->authorizeManage($user, $workspace->id);

        $webhook = $workspace->webhook;
        abort_if($webhook === null, 404);

        return $webhook;
    }

    private function authorizeManage(User $user, string $workspaceId): void
    {
        abort_unless($user->hasAllPermissions(['workspace.settings.manage'], $workspaceId), 403);
    }
}
