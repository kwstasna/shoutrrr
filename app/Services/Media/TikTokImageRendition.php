<?php

declare(strict_types=1);

namespace App\Services\Media;

use App\Enums\Platform;
use App\Models\PostMedia;
use Illuminate\Support\Facades\Storage;
use Intervention\Image\Drivers\Gd\Driver as GdDriver;
use Intervention\Image\Format;
use Intervention\Image\ImageManager;
use Throwable;

/**
 * Resolves the public HTTPS URL TikTok fetches for a carousel photo, guaranteeing
 * the bytes at that URL are a format TikTok accepts and are under its size cap.
 *
 * TikTok's photo posts are PULL_FROM_URL only — there is no byte-upload path for
 * photos the way there is for video — so, exactly as with Instagram, a source in
 * the wrong format must first be transcoded to a file that lives at a reachable
 * URL. TikTok accepts JPEG and WebP; a PNG or GIF must be re-encoded.
 *
 * Mirrors InstagramImageRendition (same deterministic derived path, so retries of
 * the async flow reuse a rendition rather than transcoding repeatedly), with two
 * deliberate differences:
 *
 *  - WebP passes through untouched. Instagram is JPEG-only; TikTok is not.
 *  - The 20 MB per-photo cap is enforced here. Instagram's rendition has no byte
 *    ceiling to honour, but TikTok rejects oversize photos, and an image that is
 *    the right format can still be too large.
 */
class TikTokImageRendition
{
    private const int JPEG_QUALITY = 90;

    /**
     * Quality steps used to squeeze an oversize photo under TikTok's cap. Each is
     * tried in turn; the first that fits wins.
     */
    private const array COMPRESSION_QUALITIES = [80, 65, 50];

    public function __construct(private readonly PublicMediaUrl $publicMediaUrl) {}

    public function urlFor(PostMedia $media): string
    {
        // Only still images reach a photo post; a video never goes through here
        // (it uploads its bytes directly). Guard anyway so a mixed target can't
        // trip the decoder on an MP4.
        if ($media->isVideo()) {
            return $this->publicMediaUrl->for($media);
        }

        $acceptable = in_array($media->mime, Platform::TikTok->allowedMime(), true);

        if ($acceptable && $media->size_bytes <= Platform::TikTok->maxMediaBytes()) {
            return $this->publicMediaUrl->for($media);
        }

        $disk = Storage::disk($media->disk);
        $derivedPath = $this->derivedPath($media);

        if (! $disk->exists($derivedPath)) {
            $jpeg = $this->renditionFor((string) $disk->get($media->path));

            // Undecodable bytes: hand TikTok the original rather than throwing.
            // There is nothing better to send, and it keeps the diagnosis on
            // TikTok's side with its own error code.
            if ($jpeg === null) {
                return $this->publicMediaUrl->for($media);
            }

            $disk->put($derivedPath, $jpeg);
        }

        return $this->publicMediaUrl->forStoredPath($media->disk, $derivedPath);
    }

    private function derivedPath(PostMedia $media): string
    {
        return 'derived/tiktok/'.$media->id.'.jpg';
    }

    /**
     * Re-encode to JPEG, stepping quality down until the result fits TikTok's
     * per-photo byte cap. Returns null when the bytes cannot be decoded at all.
     */
    private function renditionFor(string $bytes): ?string
    {
        $max = Platform::TikTok->maxMediaBytes();

        try {
            $image = ImageManager::usingDriver(GdDriver::class)->decodeBinary($bytes);
        } catch (Throwable) {
            return null;
        }

        try {
            $encoded = (string) $image->encodeUsingFormat(Format::JPEG, quality: self::JPEG_QUALITY);

            foreach (self::COMPRESSION_QUALITIES as $quality) {
                if (strlen($encoded) <= $max) {
                    return $encoded;
                }

                $encoded = (string) $image->encodeUsingFormat(Format::JPEG, quality: $quality);
            }

            // Still over the cap at the lowest quality. Return it anyway: TikTok's
            // own rejection names the real problem (an enormous source image),
            // which is more useful than us failing here with a generic message.
            return $encoded;
        } catch (Throwable) {
            return null;
        }
    }
}
