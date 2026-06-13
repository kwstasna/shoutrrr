import { describe, expect, it } from 'vitest';

import { monthRange, parseYm, weekRange, ymKey } from '@/lib/datetime/dayjs';

describe('datetime helpers', () => {
    it('ymKey formats a YYYY-MM key', () => {
        expect(ymKey(parseYm('2026-06')!)).toBe('2026-06');
    });

    it('parseYm rejects garbage', () => {
        expect(parseYm('not-a-month')).toBeNull();
    });

    it('monthRange returns a 42-day Sunday-first grid', () => {
        const { days } = monthRange(parseYm('2026-06')!);
        expect(days).toHaveLength(42);
        expect(days[0].day()).toBe(0); // Sunday
        // June 2026 starts on a Monday, so the grid's first cell is May 31.
        expect(days[0].format('YYYY-MM-DD')).toBe('2026-05-31');
    });

    it('weekRange returns 7 Sunday-first days', () => {
        const { days } = weekRange(parseYm('2026-06')!);
        expect(days).toHaveLength(7);
        expect(days[0].day()).toBe(0);
    });
});
