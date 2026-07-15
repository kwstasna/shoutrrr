<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Services\Metrics\StoryInsightsRecorder;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Records a single Meta `story_insights` webhook change off the request thread, so
 * the webhook endpoint can acknowledge Meta with a fast 200 (Meta retries slow or
 * failed deliveries). The signature was already verified before dispatch.
 */
class RecordStoryInsights implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 30;

    /**
     * @param  array<string, mixed>  $value  the webhook change `value` object
     */
    public function __construct(
        private readonly string $workspaceId,
        private readonly array $value,
    ) {}

    /**
     * @return array<int, int>
     */
    public function backoff(): array
    {
        return [10, 30, 60];
    }

    public function handle(StoryInsightsRecorder $recorder): void
    {
        $recorder->record($this->workspaceId, $this->value);
    }
}
