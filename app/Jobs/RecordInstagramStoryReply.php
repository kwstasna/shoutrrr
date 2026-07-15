<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Services\Engagement\InstagramStoryReplyRecorder;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Records a single Instagram story reply (a `messaging` event delivered by the Meta
 * webhook) off the request thread, so the endpoint can acknowledge Meta with a fast
 * 200. The signature was already verified before dispatch.
 */
class RecordInstagramStoryReply implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 30;

    /**
     * @param  array<string, mixed>  $messaging  a single `entry[].messaging[]` event
     */
    public function __construct(
        private readonly string $workspaceId,
        private readonly array $messaging,
    ) {}

    /**
     * @return array<int, int>
     */
    public function backoff(): array
    {
        return [10, 30, 60];
    }

    public function handle(InstagramStoryReplyRecorder $recorder): void
    {
        $recorder->record($this->workspaceId, $this->messaging);
    }
}
