<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('workspace_webhooks', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('workspace_id')->constrained()->cascadeOnDelete();
            // The provider this webhook receives events for. Kept as a column so the
            // model can grow beyond Meta without a schema change.
            $table->string('provider')->default('meta');
            // Unguessable path segment for this workspace's callback URL
            // (/api/v1/webhooks/meta/{endpoint_token}); lets one instance route Meta
            // deliveries to the right workspace even behind a single shared Meta app.
            $table->string('endpoint_token')->unique();
            // Shared secret echoed during Meta's GET handshake. Encrypted at rest but
            // shown to the workspace owner so they can paste it into the App Dashboard.
            $table->text('verify_token');
            // Optional per-workspace app secret (for operators running a separate Meta
            // app per workspace). Null falls back to services.facebook.client_secret.
            $table->text('signing_secret')->nullable();
            $table->timestamp('last_received_at')->nullable();
            $table->string('last_event')->nullable();
            $table->unsignedBigInteger('received_count')->default(0);
            $table->timestamps();

            // One webhook config per workspace.
            $table->unique('workspace_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('workspace_webhooks');
    }
};
