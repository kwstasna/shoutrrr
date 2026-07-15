<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Services\Engagement\InstagramCommentRecorder;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Records a single Meta `comments` webhook change into the Engagement inbox off the
 * request thread, so the webhook endpoint can acknowledge Meta with a fast 200. The
 * signature was already verified before dispatch.
 */
class RecordInstagramComment implements ShouldQueue
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

    public function handle(InstagramCommentRecorder $recorder): void
    {
        $recorder->record($this->workspaceId, $this->value);
    }
}
