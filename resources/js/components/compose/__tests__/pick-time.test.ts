import { describe, expect, it } from 'vitest';

import { composePickedAt, partsInTz } from '../pick-time-popover';

describe('pick-time tz helpers', () => {
    it('composePickedAt interprets wall-clock in the given tz', () => {
        // 09:00 in Asia/Kolkata (UTC+5:30) === 03:30 UTC
        expect(composePickedAt('2026-06-20', 9, 0, 'Asia/Kolkata')).toBe(
            '2026-06-20T03:30:00Z',
        );
        // 09:00 UTC stays 09:00 UTC
        expect(composePickedAt('2026-06-20', 9, 0, 'UTC')).toBe(
            '2026-06-20T09:00:00Z',
        );
    });

    it('partsInTz round-trips composePickedAt', () => {
        const iso = composePickedAt('2026-06-20', 14, 30, 'Asia/Kolkata');
        expect(partsInTz(iso, 'Asia/Kolkata')).toEqual({
            dayIso: '2026-06-20',
            hour: 14,
            minute: 30,
        });
    });
});
