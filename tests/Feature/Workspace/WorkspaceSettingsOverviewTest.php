<?php

use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceMembership;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

test('owner can view and update workspace', function () {
    $this->withoutVite();

    $workspace = Workspace::factory()->create(['name' => 'Old']);
    $owner = User::factory()->create(['current_workspace_id' => $workspace->id]);
    WorkspaceMembership::factory()->owner()->create(['workspace_id' => $workspace->id, 'user_id' => $owner->id]);

    $this->actingAs($owner)->get(route('settings.workspace'))->assertOk();

    $this->actingAs($owner)->patch(route('settings.workspace.update'), ['name' => 'New'])
        ->assertRedirect();

    $this->assertSame('New', $workspace->fresh()->name);
});

test('owner can upload a workspace photo', function () {
    config(['filesystems.default' => 's3']);
    Storage::fake('s3');

    $workspace = Workspace::factory()->create(['name' => 'Old']);
    $owner = User::factory()->create(['current_workspace_id' => $workspace->id]);
    WorkspaceMembership::factory()->owner()->create(['workspace_id' => $workspace->id, 'user_id' => $owner->id]);
    $photo = UploadedFile::fake()->image('workspace.jpg');

    $this->actingAs($owner)->patch(route('settings.workspace.update'), [
        'name' => 'New',
        'photo' => $photo,
    ])->assertSessionHasNoErrors()->assertRedirect();

    $workspace->refresh();

    expect($workspace->getRawOriginal('logo'))->toStartWith('workspace-photos/');
    expect($workspace->logo)->toContain($workspace->getRawOriginal('logo'));
    Storage::disk('s3')->assertExists($workspace->getRawOriginal('logo'));
});

test('workspace photo must be an image', function () {
    Storage::fake('public');

    $workspace = Workspace::factory()->create(['name' => 'Old']);
    $owner = User::factory()->create(['current_workspace_id' => $workspace->id]);
    WorkspaceMembership::factory()->owner()->create(['workspace_id' => $workspace->id, 'user_id' => $owner->id]);

    $this->actingAs($owner)
        ->from(route('settings.workspace'))
        ->patch(route('settings.workspace.update'), [
            'name' => 'Old',
            'photo' => UploadedFile::fake()->create('workspace.txt', 1, 'text/plain'),
        ])
        ->assertSessionHasErrors('photo')
        ->assertRedirect(route('settings.workspace'));

    expect($workspace->refresh()->getRawOriginal('logo'))->toBeNull();
});

test('member cannot update workspace', function () {
    $workspace = Workspace::factory()->create(['name' => 'Old']);
    $member = User::factory()->create(['current_workspace_id' => $workspace->id]);
    WorkspaceMembership::factory()->create(['workspace_id' => $workspace->id, 'user_id' => $member->id]);

    $this->actingAs($member)->patch(route('settings.workspace.update'), ['name' => 'New'])
        ->assertForbidden();

    $this->assertSame('Old', $workspace->fresh()->name);
});
