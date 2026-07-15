<?php

declare(strict_types=1);

namespace App\Services\Media;

use App\Models\PostMedia;
use Illuminate\Support\Facades\Storage;
use Intervention\Image\Drivers\Gd\Driver as GdDriver;
use Intervention\Image\Format;
use Intervention\Image\ImageManager;
use Throwable;

/**
 * Resolves the public HTTPS URL Instagram fetches for an image, guaranteeing the
 * bytes at that URL are JPEG.
 *
 * Instagram's Content Publishing API accepts JPEG images only — a PNG/WebP is
 * rejected server-side with the opaque "Only photo or video can be accepted as
 * media type" (Graph code 36003). Unlike Facebook/X/LinkedIn (which upload bytes
 * and can re-encode inline), Instagram publishes by handing Meta a URL it fetches
 * itself, so a non-JPEG source must first be transcoded to a JPEG that lives at a
 * reachable URL.
 *
 * A JPEG source (or any video) passes straight through. A non-JPEG image is
 * decoded and re-encoded to a derived JPEG stored beside the original on the same
 * disk under a deterministic path, so retries of the async container flow reuse
 * the same rendition rather than transcoding repeatedly.
 */
class InstagramImageRendition
{
    private const int JPEG_QUALITY = 90;

    public function __construct(private readonly PublicMediaUrl $publicMediaUrl) {}

    public function urlFor(PostMedia $media): string
    {
        // Videos publish by URL untouched (Instagram accepts MP4/MOV); only still
        // images have the JPEG-only constraint this service exists to satisfy.
        if ($media->isVideo() || $media->mime === 'image/jpeg') {
            return $this->publicMediaUrl->for($media);
        }

        $disk = Storage::disk($media->disk);
        $derivedPath = $this->derivedPath($media);

        if (! $disk->exists($derivedPath)) {
            $jpeg = $this->transcodeToJpeg((string) $disk->get($media->path));

            // Fall back to the original URL if the bytes are undecodable — there is
            // nothing better to hand Meta, and it keeps the failure on Meta's side
            // with its own diagnostics rather than throwing here.
            if ($jpeg === null) {
                return $this->publicMediaUrl->for($media);
            }

            $disk->put($derivedPath, $jpeg);
        }

        return $this->publicMediaUrl->forStoredPath($media->disk, $derivedPath);
    }

    private function derivedPath(PostMedia $media): string
    {
        return 'derived/instagram/'.$media->id.'.jpg';
    }

    private function transcodeToJpeg(string $bytes): ?string
    {
        try {
            return (string) ImageManager::usingDriver(GdDriver::class)
                ->decodeBinary($bytes)
                ->encodeUsingFormat(Format::JPEG, quality: self::JPEG_QUALITY);
        } catch (Throwable) {
            return null;
        }
    }
}
