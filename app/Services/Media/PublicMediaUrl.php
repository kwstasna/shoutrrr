<?php

declare(strict_types=1);

namespace App\Services\Media;

use App\Models\PostMedia;
use App\Support\FileStorage;

/**
 * Resolves a publicly reachable HTTPS URL for a stored media file, for
 * platforms (Instagram, Threads) that publish by handing Meta a URL it
 * fetches server-side rather than accepting an uploaded byte stream.
 *
 * Uses the shared storage URL resolver: public disks receive plain URLs while
 * private disks receive signed URLs with enough time for container processing.
 */
class PublicMediaUrl
{
    public function for(PostMedia $media): string
    {
        return FileStorage::url($media->path, $media->disk);
    }
}
