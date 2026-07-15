<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('post_targets', function (Blueprint $table): void {
            // Meta webhooks (e.g. story_insights) arrive keyed by the published
            // media id, so we look targets up by (platform, remote_id). Index it
            // to keep that lookup off a full table scan.
            $table->index(['platform', 'remote_id']);
        });
    }

    public function down(): void
    {
        Schema::table('post_targets', function (Blueprint $table): void {
            $table->dropIndex(['platform', 'remote_id']);
        });
    }
};
