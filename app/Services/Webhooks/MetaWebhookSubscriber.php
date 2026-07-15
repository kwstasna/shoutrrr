<?php

declare(strict_types=1);

namespace App\Services\Webhooks;

use App\Enums\Platform;
use App\Models\ConnectedAccount;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Support\Facades\Log;

/**
 * Subscribes a connected Instagram account's linked Facebook Page to this app, so
 * Meta actually delivers that account's webhooks (comments, story_insights, story
 * replies) to the workspace's callback URL.
 *
 * Configuring the callback URL + fields in the App Dashboard is necessary but not
 * sufficient: Instagram (Graph API via Facebook Login) only sends an account's
 * events once its Page is subscribed to the app via the Page-node `subscribed_apps`
 * edge (POST /{page-id}/subscribed_apps), authenticated with that Page's token.
 * Without this step no real events arrive, only manual dashboard tests.
 *
 * @see https://developers.facebook.com/docs/instagram-platform/webhooks
 */
class MetaWebhookSubscriber
{
    /**
     * The Instagram webhook fields the receiver consumes. `messages` covers story
     * replies (delivered as Direct Messages).
     *
     * @var list<string>
     */
    private const array SUBSCRIBED_FIELDS = ['comments', 'story_insights', 'messages'];

    public function __construct(private readonly HttpFactory $http) {}

    /**
     * Subscribe the account's Page to this app. Returns true on a confirmed
     * subscription. Best-effort: a failure is logged and reported, never thrown, so
     * it can't break the connect flow.
     */
    public function subscribe(ConnectedAccount $account): bool
    {
        if ($account->platform !== Platform::Instagram) {
            return false;
        }

        $pageId = $account->capabilities['page_id'] ?? null;
        $token = $account->secret?->access_token;

        if (! is_string($pageId) || $pageId === '' || ! is_string($token) || $token === '') {
            Log::warning('Cannot subscribe Instagram account to webhooks: missing page id or token.', [
                'connected_account_id' => $account->id,
            ]);

            return false;
        }

        try {
            $response = $this->http->asForm()->post(
                sprintf('https://graph.facebook.com/%s/%s/subscribed_apps', $this->apiVersion(), $pageId),
                [
                    'subscribed_fields' => implode(',', self::SUBSCRIBED_FIELDS),
                    'access_token' => $token,
                ],
            );
        } catch (ConnectionException $exception) {
            Log::warning('Meta webhook subscription could not reach Graph.', [
                'connected_account_id' => $account->id,
                'message' => $exception->getMessage(),
            ]);

            return false;
        }

        if ($response->failed() || $response->json('success') !== true) {
            Log::warning('Meta webhook subscription failed.', [
                'connected_account_id' => $account->id,
                'status' => $response->status(),
                'error' => $response->json('error.message') ?? $response->body(),
            ]);

            return false;
        }

        Log::info('Subscribed Instagram account to Meta webhooks.', [
            'connected_account_id' => $account->id,
            'fields' => self::SUBSCRIBED_FIELDS,
        ]);

        return true;
    }

    private function apiVersion(): string
    {
        return (string) config('services.facebook.graph_version');
    }
}
