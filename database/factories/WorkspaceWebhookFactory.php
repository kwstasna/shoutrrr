<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Workspace;
use App\Models\WorkspaceWebhook;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<WorkspaceWebhook>
 */
class WorkspaceWebhookFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'workspace_id' => Workspace::factory(),
            'provider' => 'meta',
            'endpoint_token' => WorkspaceWebhook::freshEndpointToken(),
            'verify_token' => WorkspaceWebhook::freshVerifyToken(),
            'signing_secret' => null,
            'received_count' => 0,
        ];
    }

    public function withSigningSecret(string $secret = 'workspace-secret'): static
    {
        return $this->state(fn (array $attributes): array => [
            'signing_secret' => $secret,
        ]);
    }
}
