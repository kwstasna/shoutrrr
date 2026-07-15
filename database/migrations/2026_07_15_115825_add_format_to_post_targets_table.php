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
            // Which surface to publish to on platforms that expose more than one
            // (currently only Instagram: 'feed' vs ephemeral 'story'). Every
            // existing and non-Instagram target is a plain feed post.
            $table->string('format')->default('feed')->after('sections');
        });
    }

    public function down(): void
    {
        Schema::table('post_targets', function (Blueprint $table): void {
            $table->dropColumn('format');
        });
    }
};
