<?php

declare(strict_types=1);

namespace App\Http\Controllers\ConnectedAccounts;

use App\Enums\Platform;
use App\Enums\UsageCategory;
use App\Http\Controllers\Controller;
use App\Models\ConnectedAccount;
use App\Services\Publishing\Connectors\TikTokErrorMap;
use App\Services\Publishing\TokenManager;
use App\Services\Usage\Concerns\TracksUsage;
use App\Support\UsageOperation;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Throwable;

/**
 * Serves TikTok's Query Creator Info to the composer.
 *
 * TikTok's content-sharing guidelines require an app to call this before
 * rendering its posting UI, and to build the privacy selector from exactly what
 * comes back — so the composer cannot use a cached or hardcoded list, and this
 * is proxied through our server rather than called from the browser (the access
 * token must never reach the client).
 */
class TikTokCreatorInfoController extends Controller
{
    use TracksUsage;

    private const string ENDPOINT = 'https://open.tiktokapis.com/v2/post/publish/creator_info/query/';

    public function __construct(
        private readonly HttpFactory $http,
        private readonly TokenManager $tokens,
    ) {}

    public function __invoke(Request $request, ConnectedAccount $account): JsonResponse
    {
        $request->user()->can('update', $account) ?: abort(403);

        abort_unless($account->platform === Platform::TikTok, 404);

        try {
            $credentials = $this->tokens->fresh($account);
        } catch (Throwable) {
            return $this->failed('This TikTok account needs reconnecting.');
        }

        $token = (string) ($credentials['access_token'] ?? '');

        if ($token === '') {
            return $this->failed('This TikTok account needs reconnecting.');
        }

        try {
            $response = $this->http->withToken($token)->post(self::ENDPOINT);
        } catch (Throwable) {
            return $this->failed("Couldn't reach TikTok. Try again in a moment.");
        }

        $this->meter(UsageCategory::ExternalApi, UsageOperation::CREATOR_INFO_QUERY, $account, $response);

        $code = $response->json('error.code');
        $code = is_string($code) ? $code : '';

        // creator_info reports the spam/cap conditions as HTTP 200 with the code
        // in the body, so checking the status alone would read a hard block as a
        // successful empty response.
        if ($response->failed() || ! TikTokErrorMap::isOk($code)) {
            $fallback = $response->json('error.message');

            return $this->failed(TikTokErrorMap::message($code, is_string($fallback) ? $fallback : ''));
        }

        /** @var array<int, string> $privacyOptions */
        $privacyOptions = (array) ($response->json('data.privacy_level_options') ?? []);

        return response()->json([
            'status' => 'ready',
            'info' => [
                'nickname' => (string) ($response->json('data.creator_nickname') ?? $account->display_name ?? $account->handle),
                'username' => (string) ($response->json('data.creator_username') ?? ''),
                // TikTok's avatar URL expires after ~2 hours, which is why it is
                // fetched per compose rather than stored on the account.
                'avatarUrl' => $response->json('data.creator_avatar_url'),
                'privacyOptions' => array_values($privacyOptions),
                'commentDisabled' => (bool) ($response->json('data.comment_disabled') ?? false),
                'duetDisabled' => (bool) ($response->json('data.duet_disabled') ?? false),
                'stitchDisabled' => (bool) ($response->json('data.stitch_disabled') ?? false),
                'maxVideoPostDurationSec' => (int) ($response->json('data.max_video_post_duration_sec') ?? 0),
            ],
        ]);
    }

    private function failed(string $message): JsonResponse
    {
        // 200 with an error body, not a 4xx: this is a rendering hint for the
        // composer, and a failed creator_info lookup is not a failed request.
        return response()->json(['status' => 'error', 'message' => $message]);
    }
}
