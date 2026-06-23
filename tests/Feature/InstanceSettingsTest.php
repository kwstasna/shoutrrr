<?php

use App\Models\User;
use App\Settings\InstanceSettings;
use Inertia\Testing\AssertableInertia;

test('instance owner can view instance settings', function () {
    $owner = User::factory()->instanceOwner()->create();

    $this->actingAs($owner)
        ->get(route('instance-settings.edit'))
        ->assertOk();
});

test('regular users cannot view instance settings', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->get(route('instance-settings.edit'))
        ->assertForbidden();
});

test('instance owner can update instance settings', function () {
    $owner = User::factory()->instanceOwner()->create();

    $this->actingAs($owner)
        ->put(route('instance-settings.update'), [
            'registrations_enabled' => false,
            'workspace_creation_enabled' => false,
        ])
        ->assertRedirect();

    expect(app(InstanceSettings::class)->registrationsEnabled())->toBeFalse()
        ->and(app(InstanceSettings::class)->workspaceCreationEnabled())->toBeFalse();
});

test('workspace creation setting is disabled when workspaces are globally disabled', function () {
    config(['kit.workspaces.enabled' => false]);

    $owner = User::factory()->instanceOwner()->create();
    app(InstanceSettings::class)->update([
        'workspace_creation_enabled' => true,
    ]);

    $this->actingAs($owner)
        ->get(route('instance-settings.edit'))
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('workspaces_enabled', false)
            ->where('settings.workspace_creation_enabled', false));
});

test('workspace creation setting cannot be enabled when workspaces are globally disabled', function () {
    config(['kit.workspaces.enabled' => false]);

    $owner = User::factory()->instanceOwner()->create();

    $this->actingAs($owner)
        ->put(route('instance-settings.update'), [
            'registrations_enabled' => true,
            'workspace_creation_enabled' => true,
        ])
        ->assertRedirect();

    expect(app(InstanceSettings::class)->workspaceCreationEnabled())->toBeFalse();
});
