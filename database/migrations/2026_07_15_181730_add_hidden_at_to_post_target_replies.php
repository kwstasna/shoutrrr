<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * When we hide an inbound comment on the platform (Instagram/Facebook comment
 * moderation), record when — so the inbox can reflect the hidden state and offer
 * to unhide. Null means visible.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('post_target_replies', function (Blueprint $table): void {
            $table->timestamp('hidden_at')->nullable()->after('liked_at');
        });
    }

    public function down(): void
    {
        Schema::table('post_target_replies', function (Blueprint $table): void {
            $table->dropColumn('hidden_at');
        });
    }
};
