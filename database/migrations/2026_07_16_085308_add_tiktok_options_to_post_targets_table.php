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
            // TikTok is the only platform with per-post publishing options today,
            // so these ride on post_targets alongside `format` rather than in a
            // satellite table — the same shape the Instagram feed/story switch
            // uses. Every column is defaulted or nullable, so targets on the other
            // seven platforms are unaffected and existing rows need no backfill.

            // Direct post (goes live unattended) vs inbox draft (the creator
            // finishes it in the TikTok app). Different endpoints, different scopes.
            $table->string('tiktok_post_mode')->default('direct_post')->after('format');

            // Deliberately nullable with NO default. TikTok's content-sharing
            // guidelines require the privacy dropdown to start with nothing
            // pre-selected, so "the creator has not chosen yet" must be a
            // representable state rather than something we quietly pick for them.
            // Only a direct post uses it; an inbox draft leaves it null.
            $table->string('tiktok_privacy_level')->nullable()->after('tiktok_post_mode');

            // Interaction settings, stored in TikTok's own polarity (disable_*) so
            // the column matches the wire field exactly. The composer presents them
            // as "Allow comments/Duet/Stitch" and negates at the boundary.
            //
            // They default to TRUE — i.e. interactions OFF — and that is the whole
            // point rather than an accident. TikTok's guidelines require the
            // visible toggles to start unchecked, and an unchecked "Allow comments"
            // means disable_comment = true. Defaulting these to false would hydrate
            // the composer with every box already ticked (allow = !disable) and
            // publish the exact opposite of what the audit demands.
            $table->boolean('tiktok_disable_comment')->default(true)->after('tiktok_privacy_level');
            $table->boolean('tiktok_disable_duet')->default(true)->after('tiktok_disable_comment');
            $table->boolean('tiktok_disable_stitch')->default(true)->after('tiktok_disable_duet');

            // Commercial disclosure. brand_organic = "Your brand" (promotes the
            // creator's own business), brand_content = "Branded content" (a paid
            // partnership). Both default off, per the guidelines.
            $table->boolean('tiktok_brand_content_toggle')->default(false)->after('tiktok_disable_stitch');
            $table->boolean('tiktok_brand_organic_toggle')->default(false)->after('tiktok_brand_content_toggle');

            // Which frame/photo becomes the cover.
            $table->unsignedInteger('tiktok_video_cover_timestamp_ms')->nullable()->after('tiktok_brand_organic_toggle');
            $table->unsignedTinyInteger('tiktok_photo_cover_index')->nullable()->after('tiktok_video_cover_timestamp_ms');

            // Photo posts only: let TikTok add a soundtrack.
            $table->boolean('tiktok_auto_add_music')->default(false)->after('tiktok_photo_cover_index');

            // A photo post carries two text fields (a 90-rune title and a
            // 4000-rune description) where a video carries one. The post's own
            // segments supply the description, so only the short title has nowhere
            // else to live. Null for video targets.
            $table->string('tiktok_photo_title')->nullable()->after('tiktok_auto_add_music');
        });
    }

    public function down(): void
    {
        Schema::table('post_targets', function (Blueprint $table): void {
            $table->dropColumn([
                'tiktok_post_mode',
                'tiktok_privacy_level',
                'tiktok_disable_comment',
                'tiktok_disable_duet',
                'tiktok_disable_stitch',
                'tiktok_brand_content_toggle',
                'tiktok_brand_organic_toggle',
                'tiktok_video_cover_timestamp_ms',
                'tiktok_photo_cover_index',
                'tiktok_auto_add_music',
                'tiktok_photo_title',
            ]);
        });
    }
};
