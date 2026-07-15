<?php

/**
 * The single SQLite file is written by Octane web workers, the queue worker(s),
 * and the scheduler at once. Without WAL + a busy timeout, parallel writes throw
 * "database is locked" (e.g. two publish jobs reserving rows in `jobs`). Pin the
 * concurrency-friendly defaults so they can't silently revert to null.
 */
test('the sqlite connection defaults to WAL with a busy timeout for concurrency', function () {
    expect(config('database.connections.sqlite.journal_mode'))->toBe('WAL')
        ->and(config('database.connections.sqlite.synchronous'))->toBe('NORMAL')
        ->and((int) config('database.connections.sqlite.busy_timeout'))->toBeGreaterThanOrEqual(2000);
});
