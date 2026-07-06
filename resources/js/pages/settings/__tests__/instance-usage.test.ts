import { describe, expect, it } from 'vitest';

import { formatMoney, usageQuery, xUsageTotal } from '../instance-usage';

describe('instance usage filters', () => {
    it('omits cleared platform filters from the query', () => {
        expect(usageQuery(null, null)).toEqual({});
        expect(usageQuery('workspace-1', null)).toEqual({
            workspace: 'workspace-1',
        });
        expect(usageQuery(null, 'x')).toEqual({ platform: 'x' });
    });
});

describe('instance usage money formatting', () => {
    it('uses the configured currency code', () => {
        expect(formatMoney(1.23, 'EUR')).toContain('€');
        expect(formatMoney(1.23, 'USD')).toContain('$');
    });
});

describe('x usage response helpers', () => {
    it('uses project usage when available', () => {
        expect(xUsageTotal({ project_usage: 15420 })).toBe(15420);
    });

    it('falls back to summing daily project app usage', () => {
        expect(
            xUsageTotal({
                daily_project_usage: [
                    {
                        date: '2026-07-01',
                        usage: [
                            { app_id: '1', tweets_consumed: 10 },
                            { app_id: '2', tweets_consumed: 5 },
                        ],
                    },
                ],
            }),
        ).toBe(15);
    });
});
