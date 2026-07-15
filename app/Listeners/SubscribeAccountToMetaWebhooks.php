<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Enums\Platform;
use App\Events\ConnectedAccountConnected;
use App\Services\Webhooks\MetaWebhookSubscriber;
use Illuminate\Contracts\Queue\ShouldQueue;

/**
 * When an Instagram account is connected, subscribe its Page to this app so Meta
 * begins delivering the account's webhooks (comments, story_insights, story
 * replies). Queued so the outbound Graph call never blocks the connect response.
 */
class SubscribeAccountToMetaWebhooks implements ShouldQueue
{
    public function __construct(private readonly MetaWebhookSubscriber $subscriber) {}

    public function handle(ConnectedAccountConnected $event): void
    {
        if ($event->account->platform === Platform::Instagram) {
            $this->subscriber->subscribe($event->account);
        }
    }
}
