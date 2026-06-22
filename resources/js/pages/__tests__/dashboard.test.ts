import { describe, expect, it } from 'vitest';

import { shouldShowDashboardPublishingSection } from '../dashboard';

describe('shouldShowDashboardPublishingSection', () => {
    it('hides the composer and recent posts until at least one account is connected', () => {
        expect(shouldShowDashboardPublishingSection([])).toBe(false);
        expect(
            shouldShowDashboardPublishingSection([{ id: 'account-1' }]),
        ).toBe(true);
    });
});
