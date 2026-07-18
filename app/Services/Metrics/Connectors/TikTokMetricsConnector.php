<?php

declare(strict_types=1);

namespace App\Services\Metrics\Connectors;

use App\Dto\Metrics\AccountMetricsResult;
use App\Dto\Metrics\PostMetricsResult;
use App\Models\ConnectedAccount;
use App\Models\PostTarget;
use App\Services\Metrics\Contracts\MetricsConnector;

/**
 * TikTok metrics are not wired up yet. Reports `unsupported` (terminal) so the
 * poller never burns calls here, mirroring LinkedInMetricsConnector.
 *
 * Post metrics are blocked on an unresolved question rather than on effort: the
 * Content Posting API hands back `publicaly_available_post_id` (a list of int64),
 * while the Display API's /v2/video/query/ takes `filters.video_ids` (a list of
 * strings). No TikTok documentation page states that these are the same
 * identifier, and the two namespaces never cross-reference each other. The shape
 * matches and it is the only id TikTok surfaces, so they are probably the same
 * space — but that is an inference, and building a connector on it would mean
 * silently reporting the wrong post's numbers if it is wrong. It needs verifying
 * against a real published post first.
 *
 * Account metrics (follower/likes/video counts) are documented and would work,
 * but they require the `user.info.stats` scope, which Platform::scopes()
 * deliberately does not request yet — TikTok's app review rejects scopes the app
 * cannot demonstrate using. Both land together once the correlation is proven.
 */
class TikTokMetricsConnector implements MetricsConnector
{
    private const string REASON = 'TikTok metrics are not available yet.';

    public function fetchPost(ConnectedAccount $account, PostTarget $target, array $credentials): PostMetricsResult
    {
        return PostMetricsResult::unsupported(self::REASON);
    }

    public function fetchAccount(ConnectedAccount $account, array $credentials): AccountMetricsResult
    {
        return AccountMetricsResult::unsupported(self::REASON);
    }
}
