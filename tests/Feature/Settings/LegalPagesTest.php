<?php

declare(strict_types=1);

use App\Models\LegalPage;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceMembership;
use Carbon\CarbonImmutable;

/**
 * @return array{0: Workspace, 1: User}
 */
function legalWorkspaceOwner(): array
{
    $workspace = Workspace::factory()->create();
    $owner = User::factory()->create(['current_workspace_id' => $workspace->id]);
    WorkspaceMembership::factory()->owner()->create([
        'workspace_id' => $workspace->id,
        'user_id' => $owner->id,
    ]);

    return [$workspace, $owner];
}

test('owner can view the legal settings page', function (): void {
    [, $owner] = legalWorkspaceOwner();

    $this->actingAs($owner)
        ->get(route('settings.workspace.legal'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('settings/workspace/legal'));
});

test('a plain member cannot view the legal settings page', function (): void {
    $workspace = Workspace::factory()->create();
    $member = User::factory()->create(['current_workspace_id' => $workspace->id]);
    WorkspaceMembership::factory()->create(['workspace_id' => $workspace->id, 'user_id' => $member->id]);

    $this->actingAs($member)
        ->get(route('settings.workspace.legal'))
        ->assertForbidden();
});

test('a plain member cannot update the legal pages', function (): void {
    $workspace = Workspace::factory()->create();
    $member = User::factory()->create(['current_workspace_id' => $workspace->id]);
    WorkspaceMembership::factory()->create(['workspace_id' => $workspace->id, 'user_id' => $member->id]);

    $this->actingAs($member)
        ->put(route('settings.workspace.legal.update'), [
            'slug' => 'member-legal',
            'terms_body' => 'Terms content',
            'privacy_body' => 'Privacy content',
            'terms_published' => true,
            'privacy_published' => true,
        ])
        ->assertForbidden();

    $this->assertDatabaseCount('legal_pages', 0);
});

test('owner can create the legal page with a slug and both documents published', function (): void {
    [$workspace, $owner] = legalWorkspaceOwner();

    $this->actingAs($owner)
        ->from(route('settings.workspace.legal'))
        ->put(route('settings.workspace.legal.update'), [
            'slug' => 'company-legal',
            'terms_body' => "# Terms\n\nBy using this you agree.",
            'privacy_body' => "# Privacy\n\nWe protect your data.",
            'terms_published' => true,
            'privacy_published' => true,
        ])
        ->assertSessionHasNoErrors()
        ->assertRedirect();

    $this->assertDatabaseHas('legal_pages', [
        'workspace_id' => $workspace->id,
        'slug' => 'company-legal',
    ]);

    $page = LegalPage::withoutGlobalScopes()->where('workspace_id', $workspace->id)->first();

    expect($page)->not->toBeNull()
        ->and($page->slug)->toBe('company-legal')
        ->and($page->terms_published_at)->not->toBeNull()
        ->and($page->privacy_published_at)->not->toBeNull();
});

test('publishing a document with a blank body fails validation on that field', function (): void {
    [, $owner] = legalWorkspaceOwner();

    $this->actingAs($owner)
        ->putJson(route('settings.workspace.legal.update'), [
            'slug' => 'company-legal',
            'terms_body' => '',
            'terms_published' => true,
            'privacy_body' => 'Privacy content',
            'privacy_published' => false,
        ])
        ->assertStatus(422)
        ->assertJsonValidationErrors('terms_body');
});

test('unpublishing a document clears its publish timestamp', function (): void {
    [$workspace, $owner] = legalWorkspaceOwner();

    LegalPage::factory()->create([
        'workspace_id' => $workspace->id,
        'slug' => 'company-legal',
    ]);

    $this->actingAs($owner)
        ->put(route('settings.workspace.legal.update'), [
            'slug' => 'company-legal',
            'terms_body' => 'Terms content',
            'privacy_body' => 'Privacy content',
            'terms_published' => false,
            'privacy_published' => false,
        ])
        ->assertSessionHasNoErrors()
        ->assertRedirect();

    $page = LegalPage::withoutGlobalScopes()->where('workspace_id', $workspace->id)->first();

    expect($page->terms_published_at)->toBeNull()
        ->and($page->privacy_published_at)->toBeNull();
});

test('a slug already owned by another workspace is rejected', function (): void {
    [, $owner] = legalWorkspaceOwner();

    $otherWorkspace = Workspace::factory()->create();
    LegalPage::factory()->create([
        'workspace_id' => $otherWorkspace->id,
        'slug' => 'taken-legal',
    ]);

    $this->actingAs($owner)
        ->putJson(route('settings.workspace.legal.update'), [
            'slug' => 'taken-legal',
            'terms_body' => 'Terms content',
            'privacy_body' => 'Privacy content',
            'terms_published' => false,
            'privacy_published' => false,
        ])
        ->assertStatus(422)
        ->assertJsonValidationErrors('slug');
});

test('reserved and malformed slugs are rejected', function (string $slug): void {
    [, $owner] = legalWorkspaceOwner();

    $this->actingAs($owner)
        ->putJson(route('settings.workspace.legal.update'), [
            'slug' => $slug,
            'terms_body' => 'Terms content',
            'privacy_body' => 'Privacy content',
            'terms_published' => false,
            'privacy_published' => false,
        ])
        ->assertStatus(422)
        ->assertJsonValidationErrors('slug');
})->with([
    'reserved word' => ['settings'],
    'reserved terms' => ['terms'],
    'uppercase and punctuation' => ['Bad Slug!'],
    'too short' => ['ab'],
]);

test('re-saving a published document preserves its original publish timestamp', function (): void {
    [$workspace, $owner] = legalWorkspaceOwner();

    $original = CarbonImmutable::create(2026, 1, 1, 12, 0, 0);

    LegalPage::factory()->create([
        'workspace_id' => $workspace->id,
        'slug' => 'company-legal',
        'terms_published_at' => $original,
        'privacy_published_at' => $original,
    ]);

    $this->travelTo(CarbonImmutable::create(2026, 6, 15, 8, 30, 0));

    $this->actingAs($owner)
        ->put(route('settings.workspace.legal.update'), [
            'slug' => 'company-legal',
            'terms_body' => 'Updated terms content',
            'privacy_body' => 'Updated privacy content',
            'terms_published' => true,
            'privacy_published' => true,
        ])
        ->assertSessionHasNoErrors()
        ->assertRedirect();

    $this->travelBack();

    $page = LegalPage::withoutGlobalScopes()->where('workspace_id', $workspace->id)->first();

    expect($page->terms_published_at->format('Y-m-d H:i:s'))->toBe('2026-01-01 12:00:00')
        ->and($page->privacy_published_at->format('Y-m-d H:i:s'))->toBe('2026-01-01 12:00:00');
});
