<?php

use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceWebhook;
use Illuminate\Support\Facades\Http;

/**
 * @return array{0: User, 1: Workspace}
 */
function ownerInWorkspaceForWebhooks(): array
{
    $user = User::factory()->create();
    $workspace = Workspace::factory()->create();
    $workspace->members()->create(['user_id' => $user->id, 'role' => 'owner']);
    $user->forceFill(['current_workspace_id' => $workspace->id])->save();

    return [$user, $workspace];
}

test('the webhooks settings page renders with no webhook yet', function () {
    [$user] = ownerInWorkspaceForWebhooks();

    $this->actingAs($user)->get('/settings/workspace/webhooks')
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('settings/workspace/webhooks')->where('webhook', null));
});

test('an owner can create a webhook with a unique endpoint token and verify token', function () {
    [$user, $workspace] = ownerInWorkspaceForWebhooks();

    $this->actingAs($user)->post('/settings/workspace/webhooks')->assertRedirect();

    $webhook = WorkspaceWebhook::where('workspace_id', $workspace->id)->first();
    expect($webhook)->not->toBeNull()
        ->and($webhook->endpoint_token)->not->toBeEmpty()
        ->and($webhook->verify_token)->not->toBeEmpty();
});

test('creating a webhook twice keeps one row per workspace', function () {
    [$user, $workspace] = ownerInWorkspaceForWebhooks();

    $this->actingAs($user)->post('/settings/workspace/webhooks')->assertRedirect();
    $this->actingAs($user)->post('/settings/workspace/webhooks')->assertRedirect();

    expect(WorkspaceWebhook::where('workspace_id', $workspace->id)->count())->toBe(1);
});

test('regenerate rolls the endpoint and verify tokens', function () {
    [$user, $workspace] = ownerInWorkspaceForWebhooks();
    $webhook = WorkspaceWebhook::factory()->create(['workspace_id' => $workspace->id]);
    $oldEndpoint = $webhook->endpoint_token;
    $oldVerify = $webhook->verify_token;

    $this->actingAs($user)->post('/settings/workspace/webhooks/regenerate')->assertRedirect();

    $webhook->refresh();
    expect($webhook->endpoint_token)->not->toBe($oldEndpoint)
        ->and($webhook->verify_token)->not->toBe($oldVerify);
});

test('a member without settings.manage cannot manage webhooks', function () {
    $user = User::factory()->create();
    $workspace = Workspace::factory()->create();
    $workspace->members()->create(['user_id' => $user->id, 'role' => 'member']);
    $user->forceFill(['current_workspace_id' => $workspace->id])->save();

    $this->actingAs($user)->get('/settings/workspace/webhooks')->assertForbidden();
    $this->actingAs($user)->post('/settings/workspace/webhooks')->assertForbidden();
});

test('the test button reports success when the loopback endpoint returns 200', function () {
    config()->set('services.facebook.client_secret', 'app-secret');
    [$user, $workspace] = ownerInWorkspaceForWebhooks();
    WorkspaceWebhook::factory()->create(['workspace_id' => $workspace->id]);

    Http::fake(['*' => Http::response(['status' => 'ok'], 200)]);

    $this->actingAs($user)->post('/settings/workspace/webhooks/test')
        ->assertRedirect()
        ->assertSessionHas('success');
});

test('the test button reports an error when no app secret is configured', function () {
    config()->set('services.facebook.client_secret', null);
    [$user, $workspace] = ownerInWorkspaceForWebhooks();
    WorkspaceWebhook::factory()->create(['workspace_id' => $workspace->id, 'signing_secret' => null]);

    $this->actingAs($user)->post('/settings/workspace/webhooks/test')
        ->assertRedirect()
        ->assertSessionHas('error');
});

test('an owner can delete the webhook', function () {
    [$user, $workspace] = ownerInWorkspaceForWebhooks();
    WorkspaceWebhook::factory()->create(['workspace_id' => $workspace->id]);

    $this->actingAs($user)->delete('/settings/workspace/webhooks')->assertRedirect();

    expect(WorkspaceWebhook::where('workspace_id', $workspace->id)->exists())->toBeFalse();
});
