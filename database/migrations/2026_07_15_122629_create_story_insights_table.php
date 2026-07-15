<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('story_insights', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('post_target_id')->constrained()->cascadeOnDelete();
            $table->timestamp('captured_at');
            // Instagram Story metric set (values available for media after 2024-07;
            // `impressions` is deprecated but still arrives for older media). Nullable
            // because Meta only sends the metrics the account is eligible for.
            $table->unsignedBigInteger('reach')->nullable();
            $table->unsignedBigInteger('impressions')->nullable();
            $table->unsignedBigInteger('replies')->nullable();
            $table->unsignedBigInteger('shares')->nullable();
            $table->unsignedBigInteger('total_interactions')->nullable();
            $table->unsignedBigInteger('profile_visits')->nullable();
            $table->unsignedBigInteger('follows')->nullable();
            $table->unsignedBigInteger('navigation')->nullable();
            $table->unsignedBigInteger('views')->nullable();
            // Full webhook value object, so newly-added Meta metrics survive without a
            // migration and remain queryable after the 24h story itself expires.
            $table->json('raw')->nullable();
            $table->timestamps();

            // One snapshot per (target, capture instant); webhook redeliveries upsert.
            $table->unique(['post_target_id', 'captured_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('story_insights');
    }
};
