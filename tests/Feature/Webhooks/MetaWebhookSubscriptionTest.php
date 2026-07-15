<?php

use App\Enums\Platform;
use App\Enums\WorkspaceRole;
use App\Events\ConnectedAccountConnected;
use App\Models\ConnectedAccount;
use App\Models\ConnectedAccountSecret;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceMembership;
use App\Services\Webhooks\MetaWebhookSubscriber;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    config()->set('services.facebook.graph_version', 'v25.0');
});

function subscribableIgAccount(Workspace $workspace, string $pageId = 'PAGE1', string $token = 'PAGE-TOKEN'): ConnectedAccount
{
    $account = ConnectedAccount::factory()->create([
        'workspace_id' => $workspace->id,
        'platform' => Platform::Instagram->value,
        'capabilities' => ['page_id' => $pageId],
    ]);
    ConnectedAccountSecret::factory()->create(['connected_account_id' => $account->id, 'access_token' => $token]);

    return $account->fresh(['secret']);
}

test('subscribing posts to the page subscribed_apps edge with the instagram fields and page token', function () {
    Http::fake(['*/PAGE1/subscribed_apps' => Http::response(['success' => true])]);

    $account = subscribableIgAccount(Workspace::factory()->create());

    expect(app(MetaWebhookSubscriber::class)->subscribe($account))->toBeTrue();

    Http::assertSent(fn ($request): bool => str_contains($request->url(), '/v25.0/PAGE1/subscribed_apps')
        && $request['access_token'] === 'PAGE-TOKEN'
        && str_contains((string) $request['subscribed_fields'], 'story_insights')
        && str_contains((string) $request['subscribed_fields'], 'comments')
        && str_contains((string) $request['subscribed_fields'], 'messages'));
});

test('subscribing returns false and makes no call for a non-instagram account', function () {
    Http::fake();

    $account = ConnectedAccount::factory()->create(['platform' => Platform::Facebook->value]);

    expect(app(MetaWebhookSubscriber::class)->subscribe($account))->toBeFalse();
    Http::assertNothingSent();
});

test('subscribing returns false when the page id or token is missing', function () {
    Http::fake();

    $account = ConnectedAccount::factory()->create([
        'platform' => Platform::Instagram->value,
        'capabilities' => [],
    ]);

    expect(app(MetaWebhookSubscriber::class)->subscribe($account))->toBeFalse();
    Http::assertNothingSent();
});

test('a failed subscription (no success flag) reports false', function () {
    Http::fake(['*/PAGE1/subscribed_apps' => Http::response(['error' => ['message' => 'nope']], 400)]);

    $account = subscribableIgAccount(Workspace::factory()->create());

    expect(app(MetaWebhookSubscriber::class)->subscribe($account))->toBeFalse();
});

test('connecting an instagram account subscribes it to webhooks via the listener', function () {
    Http::fake(['*/PAGE1/subscribed_apps' => Http::response(['success' => true])]);

    $account = subscribableIgAccount(Workspace::factory()->create());

    ConnectedAccountConnected::dispatch($account);

    Http::assertSent(fn ($request): bool => str_contains($request->url(), '/PAGE1/subscribed_apps'));
});

function subscriptionOwner(): array
{
    $user = User::factory()->create(['email_verified_at' => now()]);
    $workspace = Workspace::factory()->create(['owner_id' => $user->id]);
    WorkspaceMembership::factory()->create([
        'workspace_id' => $workspace->id,
        'user_id' => $user->id,
        'role' => WorkspaceRole::Owner,
    ]);
    $user->forceFill(['current_workspace_id' => $workspace->id])->save();
    test()->actingAs($user);

    return [$user, $workspace];
}

test('the subscribe action wires every active instagram account and reports the count', function () {
    Http::fake(['*/subscribed_apps' => Http::response(['success' => true])]);

    [, $workspace] = subscriptionOwner();
    subscribableIgAccount($workspace, 'PAGE-A', 'TOK-A');
    subscribableIgAccount($workspace, 'PAGE-B', 'TOK-B');

    test()->post(route('settings.workspace.webhooks.subscribe'))
        ->assertRedirect()
        ->assertSessionHas('success', '2 Instagram accounts subscribed to webhooks.');

    Http::assertSent(fn ($request): bool => str_contains($request->url(), '/PAGE-A/subscribed_apps'));
    Http::assertSent(fn ($request): bool => str_contains($request->url(), '/PAGE-B/subscribed_apps'));
});

test('the subscribe action reports when there are no instagram accounts', function () {
    Http::fake();
    subscriptionOwner();

    test()->post(route('settings.workspace.webhooks.subscribe'))
        ->assertRedirect()
        ->assertSessionHas('error', fn (string $message): bool => str_contains($message, 'No connected Instagram accounts'));
});

test('the subscribe action is forbidden for a workspace member', function () {
    $user = User::factory()->create(['email_verified_at' => now()]);
    $workspace = Workspace::factory()->create(['owner_id' => $user->id]);
    WorkspaceMembership::factory()->create([
        'workspace_id' => $workspace->id,
        'user_id' => $user->id,
        'role' => WorkspaceRole::Member,
    ]);
    $user->forceFill(['current_workspace_id' => $workspace->id])->save();

    test()->actingAs($user)->post(route('settings.workspace.webhooks.subscribe'))->assertForbidden();
});
