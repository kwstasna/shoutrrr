import { describe, expect, it } from 'vitest';

import {
    computeMonthDrop,
    shouldOpenEmptyMonthDay,
} from '@/components/posts/calendar/month-grid';
import { computeWeekDrop } from '@/components/posts/calendar/week-grid';

describe('calendar day activation', () => {
    it('does not open an empty month day in the past', () => {
        expect(shouldOpenEmptyMonthDay(true, true)).toBe(false);
    });

    it('opens only empty future or current month days', () => {
        expect(shouldOpenEmptyMonthDay(true, false)).toBe(true);
        expect(shouldOpenEmptyMonthDay(false, false)).toBe(false);
    });
});

describe('calendar drop math', () => {
    it('month drop keeps the time-of-day, swaps the date', () => {
        const out = computeMonthDrop(
            '2026-06-15T09:30:00Z',
            '2026-06-20',
            'UTC',
        );
        expect(out).toBe('2026-06-20T09:30:00Z');
    });

    it('week drop snaps to day @ hour:00 by default', () => {
        const out = computeWeekDrop('2026-06-20', 14, 'UTC');
        expect(out).toBe('2026-06-20T14:00:00Z');
    });

    it('week drop applies a sub-hour minute offset', () => {
        expect(computeWeekDrop('2026-06-20', 14, 'UTC', 30)).toBe(
            '2026-06-20T14:30:00Z',
        );
        expect(computeWeekDrop('2026-06-20', 9, 'UTC', 45)).toBe(
            '2026-06-20T09:45:00Z',
        );
    });
});
