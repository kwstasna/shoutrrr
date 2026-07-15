<?php

declare(strict_types=1);

namespace App\Models;

use Carbon\CarbonImmutable;
use Database\Factories\WorkspaceWebhookFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;
use Override;

/**
 * A workspace's Meta (Instagram) webhook receiver configuration. Each workspace
 * gets its own unguessable callback URL and verify token, so a single instance
 * can route Meta deliveries to the right workspace and verify them independently.
 *
 * @property string $id
 * @property string $workspace_id
 * @property string $provider
 * @property string $endpoint_token
 * @property string $verify_token
 * @property string|null $signing_secret
 * @property CarbonImmutable|null $last_received_at
 * @property string|null $last_event
 * @property int $received_count
 */
#[Fillable([
    'workspace_id',
    'provider',
    'endpoint_token',
    'verify_token',
    'signing_secret',
    'last_received_at',
    'last_event',
    'received_count',
])]
class WorkspaceWebhook extends Model
{
    /** @use HasFactory<WorkspaceWebhookFactory> */
    use HasFactory, HasUuids;

    protected $attributes = ['provider' => 'meta', 'received_count' => 0];

    /**
     * @return array<string, string>
     */
    #[Override]
    protected function casts(): array
    {
        return [
            'verify_token' => 'encrypted',
            'signing_secret' => 'encrypted',
            'last_received_at' => 'immutable_datetime',
            'received_count' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<Workspace, $this>
     */
    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    /**
     * The app secret used to verify inbound payload signatures: the workspace's own
     * (for a dedicated Meta app) or the instance-wide Facebook app secret.
     */
    public function effectiveSigningSecret(): string
    {
        return $this->signing_secret ?: (string) config('services.facebook.client_secret');
    }

    /**
     * The public callback URL to register in the Meta App Dashboard.
     */
    public function callbackUrl(): string
    {
        return url('/api/v1/webhooks/meta/'.$this->endpoint_token);
    }

    public static function freshEndpointToken(): string
    {
        return Str::random(48);
    }

    public static function freshVerifyToken(): string
    {
        return Str::random(40);
    }
}
