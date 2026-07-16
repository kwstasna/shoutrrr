<?php

declare(strict_types=1);

namespace App\Dto\Publishing;

use InvalidArgumentException;

/**
 * How a video's bytes are split across TikTok's chunked FILE_UPLOAD.
 *
 * Kept as a pure value object because the arithmetic is the risky part: TikTok
 * specifies the constraints in prose ("at least 5 MB but no greater than 64 MB,
 * except for the final chunk, which can be greater than chunk_size (up to
 * 128 MB)") and *never says whether MB means 10^6 or 2^20*. The chunk size must
 * therefore be legal under both readings at once:
 *
 *   floor   >= max(5_000_000, 5_242_880)  = 5_242_880   (survives the MiB reading)
 *   ceiling <= min(64_000_000, 67_108_864) = 64_000_000  (survives the decimal reading)
 *
 * That leaves [5_242_880, 64_000_000]. CHUNK_SIZE sits inside it, so it is valid
 * whichever TikTok meant. The final chunk absorbs the remainder and is therefore
 * always < 2 * CHUNK_SIZE, which stays far under 128 MB under either reading —
 * this is why CHUNK_SIZE must never be raised past 64_000_000 even though 64 MiB
 * "looks" allowed.
 *
 * The plan is persisted alongside the upload and rehydrated on resume via
 * fromBlob(): a deploy that tunes CHUNK_SIZE mid-upload must not re-slice a file
 * that TikTok has already half-received.
 */
final readonly class TikTokChunkPlan
{
    /**
     * 10 MiB. Inside the safe window above, and a deliberate compromise: large
     * enough to keep the chunk count low (a 1 GiB video is ~103 chunks against
     * TikTok's 1000-chunk cap), small enough that a queue worker holds only a
     * modest buffer and a retry re-sends at most 10 MiB.
     */
    public const int CHUNK_SIZE = 10 * 1024 * 1024;

    /** TikTok rejects an upload split into more than 1000 chunks. */
    public const int MAX_CHUNKS = 1000;

    private function __construct(
        public int $totalBytes,
        public int $chunkSize,
        public int $totalChunks,
    ) {}

    /**
     * Slice a file of $totalBytes using the current CHUNK_SIZE.
     *
     * TikTok derives the chunk count by integer division and lets the *last*
     * chunk carry the remainder, so a 25 MiB file at a 10 MiB chunk size is two
     * chunks (10 MiB + 15 MiB), not three.
     */
    public static function for(int $totalBytes): self
    {
        if ($totalBytes <= 0) {
            throw new InvalidArgumentException('A TikTok upload needs at least one byte.');
        }

        // A file smaller than one chunk is sent whole: chunk_size == total_bytes
        // and chunk_count == 1. TikTok explicitly permits this, and it is the one
        // case where chunk_size may legitimately fall below the 5 MB floor.
        // Deriving the count by division here would yield 0 and be rejected.
        if ($totalBytes <= self::CHUNK_SIZE) {
            return new self($totalBytes, $totalBytes, 1);
        }

        $totalChunks = intdiv($totalBytes, self::CHUNK_SIZE);

        if ($totalChunks > self::MAX_CHUNKS) {
            throw new InvalidArgumentException(
                "A {$totalBytes}-byte video needs {$totalChunks} chunks, over TikTok's ".self::MAX_CHUNKS.'-chunk limit.'
            );
        }

        return new self($totalBytes, self::CHUNK_SIZE, $totalChunks);
    }

    /**
     * Rebuild a plan from its persisted form. Resume MUST go through this rather
     * than re-deriving with for(): the byte ranges TikTok already accepted were
     * computed from the chunk size in force when the upload began, and re-slicing
     * a part-sent file against a different size would corrupt it silently.
     *
     * @param  array<string, mixed>  $blob
     */
    public static function fromBlob(array $blob): ?self
    {
        $totalBytes = (int) ($blob['total_bytes'] ?? 0);
        $chunkSize = (int) ($blob['chunk_size'] ?? 0);
        $totalChunks = (int) ($blob['total_chunks'] ?? 0);

        if ($totalBytes <= 0 || $chunkSize <= 0 || $totalChunks <= 0) {
            return null;
        }

        return new self($totalBytes, $chunkSize, $totalChunks);
    }

    /**
     * @return array<string, int>
     */
    public function toBlob(): array
    {
        return [
            'total_bytes' => $this->totalBytes,
            'chunk_size' => $this->chunkSize,
            'total_chunks' => $this->totalChunks,
        ];
    }

    /**
     * The byte range for chunk $index, zero-based. The last chunk runs to the end
     * of the file, absorbing any remainder.
     *
     * @return array{offset: int, length: int, lastByte: int}
     */
    public function range(int $index): array
    {
        if ($index < 0 || $index >= $this->totalChunks) {
            throw new InvalidArgumentException("Chunk {$index} is outside this plan's {$this->totalChunks} chunks.");
        }

        $offset = $index * $this->chunkSize;

        $length = $index === $this->totalChunks - 1
            ? $this->totalBytes - $offset
            : $this->chunkSize;

        return [
            'offset' => $offset,
            'length' => $length,
            // Content-Range's last byte is inclusive.
            'lastByte' => $offset + $length - 1,
        ];
    }

    /**
     * The `Content-Range` header value for chunk $index, e.g. "bytes 0-10485759/26214400".
     */
    public function contentRange(int $index): string
    {
        $range = $this->range($index);

        return "bytes {$range['offset']}-{$range['lastByte']}/{$this->totalBytes}";
    }
}
