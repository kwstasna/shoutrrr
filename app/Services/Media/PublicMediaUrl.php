<?php

declare(strict_types=1);

namespace App\Services\Media;

use App\Models\PostMedia;
use Illuminate\Support\Facades\Storage;

/**
 * Resolves a publicly reachable HTTPS URL for a stored media file, for
 * platforms (Instagram, Threads) that publish by handing Meta a URL it
 * fetches server-side rather than accepting an uploaded byte stream.
 *
 * Mirrors PostMedia::resolveUrl(): a public-visibility disk already serves
 * plain URLs; a private disk (e.g. a private S3 bucket) needs a signed,
 * expiring URL, given a long TTL here since container processing can be slow.
 */
class PublicMediaUrl
{
    public function for(PostMedia $media): string
    {
        return $this->forStoredPath($media->disk, $media->path);
    }

    /**
     * Resolve a publicly reachable HTTPS URL for an arbitrary path on a stored
     * disk — used for derived renditions (e.g. an Instagram-compatible JPEG
     * transcode) that live alongside the original but aren't their own PostMedia.
     */
    public function forStoredPath(string $disk, string $path): string
    {
        if (config("filesystems.disks.{$disk}.visibility") === 'public') {
            return $this->toAbsolute(Storage::disk($disk)->url($path));
        }

        return Storage::disk($disk)->temporaryUrl($path, now()->addHours(6));
    }

    /**
     * Meta (Instagram/Threads) fetches the media from its own servers, so a
     * host-relative public-disk URL like "/storage/…" — which a browser resolves
     * against the current origin but an external service cannot — is unreachable
     * and comes back as the opaque "Only photo or video can be accepted as media
     * type" (Graph 36003). Promote a relative URL to an absolute one rooted at the
     * app URL; already-absolute URLs (custom disk domain, signed S3) pass through.
     */
    private function toAbsolute(string $url): string
    {
        if (str_starts_with($url, 'http://') || str_starts_with($url, 'https://')) {
            return $url;
        }

        return rtrim((string) config('app.url'), '/').'/'.ltrim($url, '/');
    }
}
