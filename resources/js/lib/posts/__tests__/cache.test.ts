import { beforeEach, describe, expect, it, vi } from 'vitest';

import { invalidatePostCaches } from '../cache';

const inertia = vi.hoisted(() => ({
    flushAll: vi.fn(),
}));

vi.mock('@inertiajs/react', () => ({
    router: inertia,
}));

describe('invalidatePostCaches', () => {
    beforeEach(() => {
        inertia.flushAll.mockClear();
    });

    it('flushes cached Inertia pages after post mutations', () => {
        invalidatePostCaches();

        expect(inertia.flushAll).toHaveBeenCalledOnce();
    });
});
