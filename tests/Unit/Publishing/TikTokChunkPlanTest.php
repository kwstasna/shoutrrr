<?php

declare(strict_types=1);

use App\Dto\Publishing\TikTokChunkPlan;

/** Mebibytes, the unit CHUNK_SIZE is expressed in. */
function tiktokMib(int $count): int
{
    return $count * 1024 * 1024;
}

test('a file smaller than one chunk is a single chunk sized to the file', function (): void {
    // The special case that exists because the general formula breaks here:
    // intdiv(2048, CHUNK_SIZE) is 0, and TikTok 400s on total_chunk_count = 0.
    // A sub-chunk file must be sent whole, with chunk_size == total_bytes — the
    // one case where chunk_size may legitimately sit below TikTok's 5 MB floor.
    $plan = TikTokChunkPlan::for(2048);

    expect($plan->totalChunks)->toBe(1)
        ->and($plan->totalBytes)->toBe(2048)
        ->and($plan->chunkSize)->toBe(2048)
        ->and($plan->chunkSize)->toBe($plan->totalBytes)
        ->and($plan->range(0))->toBe(['offset' => 0, 'length' => 2048, 'lastByte' => 2047]);
});

test('a file of exactly one chunk is still a single whole chunk', function (): void {
    $plan = TikTokChunkPlan::for(TikTokChunkPlan::CHUNK_SIZE);

    expect($plan->totalChunks)->toBe(1)
        ->and($plan->chunkSize)->toBe(TikTokChunkPlan::CHUNK_SIZE);
});

test('the last chunk absorbs the remainder rather than becoming an extra chunk', function (): void {
    // TikTok's own worked example: 25 MiB at a 10 MiB chunk size is TWO chunks of
    // 10 MiB + 15 MiB, not three of 10 + 10 + 5. Deriving the count by rounding up
    // would produce a plan TikTok rejects.
    $plan = TikTokChunkPlan::for(tiktokMib(25));

    expect($plan->totalChunks)->toBe(2)
        ->and($plan->chunkSize)->toBe(TikTokChunkPlan::CHUNK_SIZE)
        ->and($plan->range(0)['length'])->toBe(tiktokMib(10))
        ->and($plan->range(1)['length'])->toBe(tiktokMib(15));
});

test('chunk count is the integer division of the total by the chunk size', function (int $totalBytes, int $expectedChunks): void {
    expect(TikTokChunkPlan::for($totalBytes)->totalChunks)->toBe($expectedChunks);
})->with([
    'one byte over a chunk stays one chunk' => [TikTokChunkPlan::CHUNK_SIZE + 1, 1],
    'one byte under two chunks stays one chunk' => [2 * TikTokChunkPlan::CHUNK_SIZE - 1, 1],
    'exactly two chunks' => [2 * TikTokChunkPlan::CHUNK_SIZE, 2],
    'two chunks plus a sliver' => [2 * TikTokChunkPlan::CHUNK_SIZE + 5, 2],
    'three chunks plus a sliver' => [3 * TikTokChunkPlan::CHUNK_SIZE + 5, 3],
    'the 1000-chunk ceiling' => [TikTokChunkPlan::MAX_CHUNKS * TikTokChunkPlan::CHUNK_SIZE, TikTokChunkPlan::MAX_CHUNKS],
]);

test('chunk ranges are contiguous and cover exactly the whole file', function (int $totalBytes): void {
    $plan = TikTokChunkPlan::for($totalBytes);

    $covered = 0;
    $expectedOffset = 0;

    for ($index = 0; $index < $plan->totalChunks; $index++) {
        $range = $plan->range($index);

        // No gap and no overlap: each chunk starts on the byte after the previous
        // one ended. A drift either way silently corrupts the assembled video.
        expect($range['offset'])->toBe($expectedOffset)
            ->and($range['length'])->toBeGreaterThan(0)
            ->and($range['lastByte'])->toBe($range['offset'] + $range['length'] - 1);

        $covered += $range['length'];
        $expectedOffset = $range['lastByte'] + 1;
    }

    // Every byte accounted for, and the plan ends exactly on the last byte.
    expect($covered)->toBe($totalBytes)
        ->and($plan->range($plan->totalChunks - 1)['lastByte'])->toBe($totalBytes - 1)
        ->and($plan->range(0)['offset'])->toBe(0);
})->with([
    'a single byte' => [1],
    'sub-chunk' => [2048],
    'one byte under a chunk' => [TikTokChunkPlan::CHUNK_SIZE - 1],
    'exactly a chunk' => [TikTokChunkPlan::CHUNK_SIZE],
    'one byte over a chunk' => [TikTokChunkPlan::CHUNK_SIZE + 1],
    'the 25 MiB example' => [tiktokMib(25)],
    'a lumpy multi-chunk file' => [7 * TikTokChunkPlan::CHUNK_SIZE + 12_345],
]);

test('contentRange is a bytes range whose last byte is inclusive', function (): void {
    $plan = TikTokChunkPlan::for(tiktokMib(25));

    // 25 MiB = 26_214_400. Chunk 0 ends at 10_485_759 (not …760): Content-Range's
    // last byte is inclusive, so an off-by-one here overlaps the next chunk.
    expect($plan->contentRange(0))->toBe('bytes 0-10485759/26214400')
        ->and($plan->contentRange(1))->toBe('bytes 10485760-26214399/26214400')
        ->and($plan->contentRange(1))->toMatch('/^bytes \d+-\d+\/\d+$/');

    expect(TikTokChunkPlan::for(2048)->contentRange(0))->toBe('bytes 0-2047/2048');
});

test('CHUNK_SIZE stays inside the window that is legal under both readings of MB', function (): void {
    // TikTok documents the bounds in prose ("at least 5 MB but no greater than
    // 64 MB") and never says whether MB is 10^6 or 2^20. To be valid whichever
    // they meant, the floor must clear the *binary* reading (5 MiB = 5_242_880)
    // and the ceiling must stay under the *decimal* one (64 MB = 64_000_000).
    // Raising CHUNK_SIZE to 64 MiB would "look" allowed and be over the cap.
    expect(TikTokChunkPlan::CHUNK_SIZE)->toBeGreaterThanOrEqual(5_242_880)
        ->and(TikTokChunkPlan::CHUNK_SIZE)->toBeLessThanOrEqual(64_000_000);
});

test('the final chunk never reaches twice the chunk size', function (int $totalBytes): void {
    // The remainder-absorbing last chunk is the only one allowed to exceed
    // CHUNK_SIZE, and it is bounded by 2 * CHUNK_SIZE - 1 (~20 MiB). That keeps it
    // under TikTok's 128 MB final-chunk allowance under either the decimal
    // (128_000_000) or the binary (134_217_728) reading.
    $plan = TikTokChunkPlan::for($totalBytes);

    $final = $plan->range($plan->totalChunks - 1);

    expect($final['length'])->toBeLessThan(2 * TikTokChunkPlan::CHUNK_SIZE)
        ->and($final['length'])->toBeLessThan(128_000_000);
})->with([
    'sub-chunk' => [2048],
    'exactly a chunk' => [TikTokChunkPlan::CHUNK_SIZE],
    'one byte under two chunks' => [2 * TikTokChunkPlan::CHUNK_SIZE - 1],
    'the 25 MiB example' => [tiktokMib(25)],
    'a lumpy multi-chunk file' => [7 * TikTokChunkPlan::CHUNK_SIZE + 12_345],
    'a file at the chunk ceiling' => [TikTokChunkPlan::MAX_CHUNKS * TikTokChunkPlan::CHUNK_SIZE + TikTokChunkPlan::CHUNK_SIZE - 1],
]);

test('a plan survives a toBlob/fromBlob round trip', function (): void {
    // Resume rehydrates through the blob rather than re-deriving with for(), so a
    // deploy that retunes CHUNK_SIZE mid-upload cannot re-slice a part-sent file.
    $plan = TikTokChunkPlan::for(tiktokMib(25));

    $restored = TikTokChunkPlan::fromBlob($plan->toBlob());

    expect($restored)->toBeInstanceOf(TikTokChunkPlan::class);
    assert($restored instanceof TikTokChunkPlan);

    expect($restored->totalBytes)->toBe($plan->totalBytes)
        ->and($restored->chunkSize)->toBe($plan->chunkSize)
        ->and($restored->totalChunks)->toBe($plan->totalChunks)
        ->and($restored->contentRange(1))->toBe($plan->contentRange(1));

    expect($plan->toBlob())->toBe([
        'total_bytes' => tiktokMib(25),
        'chunk_size' => TikTokChunkPlan::CHUNK_SIZE,
        'total_chunks' => 2,
    ]);
});

test('fromBlob honours a persisted chunk size that differs from the current constant', function (): void {
    // The whole point of the blob: an upload begun under a 5 MiB chunk size keeps
    // slicing at 5 MiB even after CHUNK_SIZE is changed to 10 MiB.
    $restored = TikTokChunkPlan::fromBlob([
        'total_bytes' => tiktokMib(25),
        'chunk_size' => tiktokMib(5),
        'total_chunks' => 5,
    ]);

    assert($restored instanceof TikTokChunkPlan);

    expect($restored->chunkSize)->toBe(tiktokMib(5))
        ->and($restored->totalChunks)->toBe(5)
        ->and($restored->contentRange(0))->toBe('bytes 0-5242879/26214400');
});

test('fromBlob rejects a malformed or empty blob', function (array $blob): void {
    expect(TikTokChunkPlan::fromBlob($blob))->toBeNull();
})->with([
    'empty' => [[]],
    'missing chunk size' => [['total_bytes' => 100, 'total_chunks' => 1]],
    'missing total bytes' => [['chunk_size' => 100, 'total_chunks' => 1]],
    'zero total bytes' => [['total_bytes' => 0, 'chunk_size' => 100, 'total_chunks' => 1]],
    'zero chunk count' => [['total_bytes' => 100, 'chunk_size' => 100, 'total_chunks' => 0]],
    'negative chunk count' => [['total_bytes' => 100, 'chunk_size' => 100, 'total_chunks' => -1]],
    'non-numeric junk' => [['total_bytes' => 'abc', 'chunk_size' => null, 'total_chunks' => 'nope']],
]);

test('range rejects a chunk index outside the plan', function (int $index): void {
    $plan = TikTokChunkPlan::for(tiktokMib(25));

    expect(fn (): array => $plan->range($index))->toThrow(InvalidArgumentException::class);
})->with([
    'negative' => [-1],
    'one past the end' => [2],
    'far past the end' => [99],
]);

test('for rejects a file with no bytes', function (int $totalBytes): void {
    expect(fn (): TikTokChunkPlan => TikTokChunkPlan::for($totalBytes))
        ->toThrow(InvalidArgumentException::class);
})->with([
    'empty' => [0],
    'negative' => [-1],
]);

test('for rejects a file needing more chunks than TikTok allows', function (): void {
    $tooBig = (TikTokChunkPlan::MAX_CHUNKS + 1) * TikTokChunkPlan::CHUNK_SIZE;

    expect(fn (): TikTokChunkPlan => TikTokChunkPlan::for($tooBig))
        ->toThrow(InvalidArgumentException::class);
});
