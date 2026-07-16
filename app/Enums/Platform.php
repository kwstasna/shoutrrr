<?php

declare(strict_types=1);

namespace App\Enums;

use App\Support\InstanceSettings;

enum Platform: string
{
    case X = 'x';
    case Bluesky = 'bluesky';
    case LinkedIn = 'linkedin';
    case Facebook = 'facebook';
    case Instagram = 'instagram';
    case Threads = 'threads';
    case Discord = 'discord';
    case TikTok = 'tiktok';

    public function label(): string
    {
        return match ($this) {
            self::X => 'X',
            self::Bluesky => 'Bluesky',
            self::LinkedIn => 'LinkedIn',
            self::Facebook => 'Facebook',
            self::Instagram => 'Instagram',
            self::Threads => 'Threads',
            self::Discord => 'Discord',
            self::TikTok => 'TikTok',
        };
    }

    public function socialiteDriver(): ?string
    {
        return match ($this) {
            self::X => 'x',
            self::LinkedIn => 'linkedin-openid',
            self::Bluesky => null,
            self::Facebook, self::Instagram => 'facebook',
            self::Threads => 'threads',
            self::Discord => null,
            // TikTok is an OAuth platform with no first-party Socialite driver, and
            // its endpoints take `client_key` rather than the conventional
            // `client_id`. It authenticates through TikTokConnectionController
            // instead — see usesDedicatedConnectionFlow().
            self::TikTok => null,
        };
    }

    /**
     * @return list<string>
     */
    public function scopes(): array
    {
        return match ($this) {
            // `users.email` is required because Socialite's X driver always
            // requests the `confirmed_email` field from /2/users/me; without the
            // scope that call 403s ("Missing required OAuth2 scopes: users.email").
            // `media.write` is required to upload media to the v2 /2/media/upload
            // endpoint (the v1.1 endpoint was deprecated 2025-03-31).
            self::X => ['users.read', 'users.email', 'tweet.read', 'tweet.write', 'media.write', 'offline.access'],
            self::LinkedIn => ['openid', 'profile', 'email', 'w_member_social'],
            self::Bluesky => [],
            self::Facebook => ['pages_show_list', 'pages_read_engagement', 'pages_manage_posts', 'pages_read_user_content', 'pages_manage_engagement', 'read_insights', 'business_management'],
            // instagram_manage_messages: receive + read story replies (delivered as
            // Direct Messages) for the Engagement inbox. pages_manage_metadata:
            // subscribe the linked Page to this app (POST /{page-id}/subscribed_apps)
            // so Meta actually delivers the account's webhooks.
            self::Instagram => ['instagram_basic', 'instagram_content_publish', 'instagram_manage_comments', 'instagram_manage_insights', 'instagram_manage_messages', 'pages_show_list', 'pages_manage_metadata', 'business_management'],
            self::Threads => ['threads_basic', 'threads_content_publish', 'threads_manage_replies', 'threads_manage_insights'],
            self::Discord => [],
            // Deliberately minimal. TikTok's app review requires a written
            // justification for every requested scope and rejects ones the app
            // does not visibly use, so we ask only for what ships today:
            // `video.publish` (Direct Post) + `video.upload` (draft to inbox) —
            // both, because the composer lets the user pick per post — plus
            // `user.info.basic` (open_id/avatar/display_name) and
            // `user.info.profile` (username, which is the account handle; it is
            // NOT covered by user.info.basic). `user.info.stats` and `video.list`
            // are intentionally omitted until the metrics connector lands; adding
            // them later costs a reconnect, which is the deliberate trade.
            self::TikTok => ['user.info.basic', 'user.info.profile', 'video.publish', 'video.upload'],
        };
    }

    public function configKey(): ?string
    {
        return match ($this) {
            self::X => 'services.x',
            self::LinkedIn => 'services.linkedin-openid',
            self::Bluesky => null,
            self::Facebook, self::Instagram => 'services.facebook',
            self::Threads => 'services.threads',
            self::Discord => null,
            self::TikTok => 'services.tiktok',
        };
    }

    /**
     * Whether this platform connects via an OAuth authorization-code flow.
     *
     * Socialite-backed platforms derive this from their driver. TikTok is OAuth
     * too but ships no Socialite driver, so it must be named explicitly rather
     * than inferred — otherwise it would advertise itself as non-OAuth.
     */
    public function supportsOAuth(): bool
    {
        return $this->socialiteDriver() !== null || $this === self::TikTok;
    }

    /**
     * Whether a dedicated controller owns this platform's connect flow instead of
     * the generic Socialite-driven OAuthConnectionController. Facebook/Instagram
     * need a Page-selection step (MetaConnectionController); TikTok has no
     * Socialite driver and authenticates with `client_key`
     * (TikTokConnectionController). The generic controller must 404 these.
     */
    public function usesDedicatedConnectionFlow(): bool
    {
        return match ($this) {
            self::Facebook, self::Instagram, self::TikTok => true,
            self::X, self::Bluesky, self::LinkedIn, self::Threads, self::Discord => false,
        };
    }

    public function supportsAppPassword(): bool
    {
        return $this === self::Bluesky;
    }

    public function supportsWebhook(): bool
    {
        return $this === self::Discord;
    }

    /**
     * Whether this platform can read replies/mentions for the engagement inbox.
     * Discord webhooks are write-only — they can't receive replies — so Discord
     * has no engagement connector and must never be scheduled for reply fetching
     * (see the gate in InstanceSettings::engagementPollingEnabled, Task 6).
     *
     * TikTok exposes no API for reading comments on a creator's own organic posts
     * (the Display API returns video metadata only), so it has no engagement
     * connector either.
     */
    public function supportsEngagement(): bool
    {
        return $this !== self::Discord && $this !== self::TikTok;
    }

    /**
     * Whether this platform's metrics connector returns real post-level metrics.
     * LinkedIn's post-metrics connector returns `unsupported`, so it has no
     * post-metrics polling to configure.
     *
     * TikTok is unsupported pending a spike: the Content Posting API returns
     * `publicaly_available_post_id`, and nothing in TikTok's docs states that it
     * is the same identifier `/v2/video/query/` accepts as `filters.video_ids`.
     * Until that correlation is proven against a real published post, a post-
     * metrics connector would be built on an inference.
     */
    public function supportsPostMetrics(): bool
    {
        return $this !== self::LinkedIn && $this !== self::TikTok;
    }

    /**
     * Whether this platform's metrics connector returns real account-level
     * metrics. LinkedIn and Discord return `unsupported` (LinkedIn has no
     * account-metrics API here; a Discord webhook cannot read server stats).
     *
     * TikTok's follower/likes counts are documented and available, but they need
     * the `user.info.stats` scope, which scopes() deliberately does not request
     * yet — so the connector reports `unsupported` until metrics ships.
     */
    public function supportsAccountMetrics(): bool
    {
        return $this !== self::LinkedIn && $this !== self::Discord && $this !== self::TikTok;
    }

    /**
     * Whether this platform participates in the given polling settings section.
     */
    public function supportsPollingSection(string $section): bool
    {
        return match ($section) {
            'engagement' => $this->supportsEngagement(),
            'post_metrics' => $this->supportsPostMetrics(),
            'account_metrics' => $this->supportsAccountMetrics(),
            default => false,
        };
    }

    /**
     * Launched platforms whose connectors back the given polling section, in
     * enum declaration order. Single source of truth for the polling settings
     * controller and its update request.
     *
     * @return list<self>
     */
    public static function pollingSectionPlatforms(string $section): array
    {
        return array_values(array_filter(
            self::cases(),
            fn (self $platform): bool => $platform->isLaunched() && $platform->supportsPollingSection($section),
        ));
    }

    public function isConfigured(): bool
    {
        if ($this->supportsAppPassword() || $this->supportsWebhook()) {
            return true;
        }

        $key = $this->configKey();

        // filled() so a blank value in .env isn't treated as configured.
        return $key !== null
            && filled(config($key.'.client_id'))
            && filled(config($key.'.client_secret'));
    }

    /**
     * Whether this platform's connect + publishing flow is fully implemented
     * and safe to expose. New platforms are registered in this enum (for limits,
     * branding, and phased rollout) before their connectors exist; until then
     * connecting must stay disabled even when credentials are configured. Flip a
     * platform to `true` when its publish/engagement/metrics connectors land.
     *
     * All eight platforms (X, Bluesky, LinkedIn, Facebook, Instagram, Threads,
     * Discord, TikTok) are launched.
     *
     * Launched is not the same as proven: TikTok's connectors are complete but
     * have only ever run against faked HTTP. An instance without TikTok
     * credentials still shows it as "Not set up" (see isConfigured), and an owner
     * can freeze it instance-wide via platformsEnabled — so this being true only
     * means "reachable once configured", not "verified against the live API".
     */
    public function isLaunched(): bool
    {
        return true;
    }

    /**
     * The subset of the Facebook-Login-driven Meta platforms (Facebook,
     * Instagram) that are launched — used to gate the shared
     * `MetaConnectionController` flow and to scope the Facebook Login
     * request to only the permissions a launched platform actually needs.
     *
     * @return list<self>
     */
    public static function launchedMetaGraphPlatforms(): array
    {
        return array_values(array_filter(
            [self::Facebook, self::Instagram],
            fn (self $platform): bool => $platform->isLaunched(),
        ));
    }

    /**
     * The launched Meta-Graph platforms (Facebook, Instagram) that are ALSO
     * enabled instance-wide — used to gate the shared Meta connect flow and the
     * per-asset platform list so an owner can freeze Facebook or Instagram
     * independently.
     *
     * @return list<self>
     */
    public static function availableMetaGraphPlatforms(): array
    {
        $enabled = app(InstanceSettings::class)->platformsEnabled();

        return array_values(array_filter(
            [self::Facebook, self::Instagram],
            fn (self $platform): bool => $platform->isLaunched() && ($enabled[$platform->value] ?? true),
        ));
    }

    /**
     * Facebook and Instagram share a single Facebook Login flow with a
     * Page/asset-selection step, driven by `MetaConnectionController`. The
     * generic per-platform `OAuthConnectionController` (a single-step
     * socialite-user-to-account mapping) must never handle them — even once
     * launched — because it has no notion of picking a Page.
     */
    public function usesMetaConnectionFlow(): bool
    {
        return $this === self::Facebook || $this === self::Instagram;
    }

    /**
     * @return list<array{platform: string, label: string, supportsOAuth: bool, supportsAppPassword: bool, supportsWebhook: bool, configured: bool, launched: bool, enabled: bool}>
     */
    public static function capabilities(): array
    {
        $enabled = app(InstanceSettings::class)->platformsEnabled();

        return array_map(fn (self $platform): array => [
            'platform' => $platform->value,
            'label' => $platform->label(),
            'supportsOAuth' => $platform->supportsOAuth(),
            'supportsAppPassword' => $platform->supportsAppPassword(),
            'supportsWebhook' => $platform->supportsWebhook(),
            'configured' => $platform->isConfigured(),
            'launched' => $platform->isLaunched(),
            'enabled' => $enabled[$platform->value] ?? true,
        ], self::cases());
    }

    /**
     * The primary length budget, in each platform's native counting unit
     * (X and TikTok: UTF-16 code units, Bluesky: graphemes; LinkedIn, Facebook,
     * Instagram, and Threads: characters via mb_strlen).
     */
    public function maxLength(): int
    {
        return match ($this) {
            self::X => 280,
            self::Bluesky => 300,
            self::LinkedIn => 3000,
            self::Facebook => 63_206,
            self::Instagram => 2_200,
            self::Threads => 500,
            self::Discord => 2000,
            // The video `post_info.title` budget, which TikTok counts in UTF-16
            // runes (see measure()). A photo post splits its text across a 90-rune
            // title and a 4000-rune description; those are enforced separately by
            // the TikTok options, not by this platform-wide budget.
            self::TikTok => 2_200,
        };
    }

    /**
     * Secondary byte budget (Bluesky only); null when the platform has none.
     */
    public function maxBytes(): ?int
    {
        return match ($this) {
            self::Bluesky => 3000,
            default => null,
        };
    }

    /**
     * Maximum number of posts a single draft may thread into; null = unlimited.
     *
     * NOTE: this match has a `default` arm, so a new platform inherits "unlimited"
     * silently — PHPStan cannot flag the omission. Any platform that publishes one
     * post per draft must be named explicitly here.
     */
    public function threadMax(): ?int
    {
        // TikTok publishes exactly one video or one photo carousel per post and has
        // no threading concept, so it must be pinned to 1. Inheriting the `default`
        // null would let auto_split fan a long caption out into several TikTok posts.
        return match ($this) {
            self::LinkedIn, self::Facebook, self::Instagram, self::TikTok => 1,
            default => null,
        };
    }

    public function maxMedia(): int
    {
        return match ($this) {
            self::X, self::Bluesky => 4,
            self::LinkedIn => 9,
            self::Facebook, self::Instagram, self::Threads => 10,
            self::Discord => 10,
            self::TikTok => 35, // photo-mode carousel cap; a video post is always single-media
        };
    }

    public function maxMediaBytes(): int
    {
        return match ($this) {
            self::Bluesky => 2_000_000,
            self::X => 5_242_880,
            self::LinkedIn => 8_388_608,
            self::Facebook => 4_194_304,
            self::Instagram, self::Threads => 8_388_608,
            self::Discord => 10_485_760, // 10 MiB (Discord's default webhook attachment cap)
            // TikTok documents "20 MB" per photo without ever specifying whether it
            // means MB or MiB. 20,000,000 is legal under either reading; 20,971,520
            // (20 MiB) would be over the cap if TikTok means decimal.
            self::TikTok => 20_000_000,
        };
    }

    /**
     * @return list<string>
     */
    public function allowedMime(): array
    {
        return match ($this) {
            self::X, self::Bluesky => ['image/jpeg', 'image/png', 'image/webp'],
            self::LinkedIn => ['image/jpeg', 'image/png', 'image/gif'],
            self::Facebook => ['image/jpeg', 'image/png', 'image/gif'],
            self::Instagram => ['image/jpeg'],
            self::Threads => ['image/jpeg', 'image/png'],
            self::Discord => ['image/jpeg', 'image/png', 'image/gif', 'image/webp'],
            // TikTok photo posts accept WebP and JPEG only. PNG/GIF uploads are
            // transcoded to JPEG before publish (see TikTokImageRendition).
            self::TikTok => ['image/jpeg', 'image/webp'],
        };
    }

    /**
     * @return array{width: int, height: int}
     */
    public function maxImageDimensions(): array
    {
        return match ($this) {
            self::Bluesky => ['width' => 2000, 'height' => 2000],
            self::X => ['width' => 8192, 'height' => 8192],
            self::LinkedIn => ['width' => 7680, 'height' => 4320],
            self::Facebook => ['width' => 8192, 'height' => 8192],
            self::Instagram, self::Threads => ['width' => 1440, 'height' => 1800],
            self::Discord => ['width' => 8192, 'height' => 8192],
            // TikTok says photos are "max 1080p" without defining whether that
            // bounds the long edge, the short edge, or the pixel count. This is the
            // portrait reading (1080×1920) and is advisory only — nothing enforces
            // it client- or server-side; TikTok downscales on its end.
            self::TikTok => ['width' => 1080, 'height' => 1920],
        };
    }

    /**
     * @return list<string>
     */
    public function allowedVideoMime(): array
    {
        // mp4 (H.264/AAC) is the common denominator all three accept directly.
        return ['video/mp4'];
    }

    public function maxVideoBytes(): int
    {
        return match ($this) {
            self::X => 536_870_912,        // 512 MB
            self::LinkedIn => 524_288_000, // 500 MB (organic feed)
            self::Bluesky => 100_000_000,
            self::Facebook, self::Instagram, self::Threads => 1_073_741_824,
            self::Discord => 10_485_760, // 10 MiB (Discord's default webhook attachment cap)
            // TikTok actually accepts up to 4 GB, but maxVideoBytesCeiling() is a
            // max() across every platform and gates the presigned-upload guard in
            // PostVideoUploadController and ReplyVideoUploadController. Declaring
            // 4 GB here would raise that ceiling for *all* platforms and let any
            // workspace presign 4 GB objects. Held at 1 GiB (matching the current
            // ceiling) until the guard is made per-platform; raise both together.
            self::TikTok => 1_073_741_824,
        };
    }

    public function maxVideoDurationSeconds(): int
    {
        return match ($this) {
            self::X => 140,
            self::LinkedIn => 1800,
            self::Bluesky => 180,
            self::Facebook => 1200,
            self::Instagram => 900,
            self::Threads => 300,
            self::Discord => 600,
            // The documented ceiling for video sent through the Content Posting
            // API. The real per-account limit comes from creator_info's
            // `max_video_post_duration_sec` and is enforced at publish, since it
            // varies by creator and can be lower than this.
            self::TikTok => 600,
        };
    }

    /**
     * Largest video byte cap across all platforms — the server-side upload ceiling.
     */
    public static function maxVideoBytesCeiling(): int
    {
        return max(array_map(fn (self $p): int => $p->maxVideoBytes(), self::cases()));
    }

    /**
     * Measure a string in this platform's native counting unit.
     */
    public function measure(string $text): int
    {
        return match ($this) {
            // UTF-16 code units: 2 bytes each in UTF-16LE. TikTok counts its title
            // budgets in "runes", which are UTF-16 code units — the same unit X
            // uses, so it shares this arm rather than reimplementing the count.
            self::X, self::TikTok => intdiv(strlen((string) mb_convert_encoding($text, 'UTF-16LE', 'UTF-8')), 2),
            self::Bluesky => grapheme_strlen($text) ?: 0,
            self::LinkedIn, self::Facebook, self::Instagram, self::Threads, self::Discord => mb_strlen($text),
        };
    }

    /**
     * @return array{platform: string, maxLength: int, maxBytes: int|null, maxMedia: int, maxMediaBytes: int, allowedMime: list<string>, threadMax: int|null, maxImageDimensions: array{width: int, height: int}, allowedVideoMime: list<string>, maxVideoBytes: int, maxVideoDurationSeconds: int}
     */
    public function limits(): array
    {
        return [
            'platform' => $this->value,
            'maxLength' => $this->maxLength(),
            'maxBytes' => $this->maxBytes(),
            'maxMedia' => $this->maxMedia(),
            'maxMediaBytes' => $this->maxMediaBytes(),
            'allowedMime' => $this->allowedMime(),
            'threadMax' => $this->threadMax(),
            'maxImageDimensions' => $this->maxImageDimensions(),
            'allowedVideoMime' => $this->allowedVideoMime(),
            'maxVideoBytes' => $this->maxVideoBytes(),
            'maxVideoDurationSeconds' => $this->maxVideoDurationSeconds(),
        ];
    }

    /**
     * @return list<array{platform: string, maxLength: int, maxBytes: int|null, maxMedia: int, maxMediaBytes: int, allowedMime: list<string>, threadMax: int|null, maxImageDimensions: array{width: int, height: int}, allowedVideoMime: list<string>, maxVideoBytes: int, maxVideoDurationSeconds: int}>
     */
    public static function allLimits(): array
    {
        return array_map(fn (self $platform): array => $platform->limits(), self::cases());
    }
}
