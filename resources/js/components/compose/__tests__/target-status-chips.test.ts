import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';

import { describe, expect, it } from 'vitest';

const source = () =>
    readFileSync(
        resolve(
            process.cwd(),
            'resources/js/components/compose/target-status-chips.tsx',
        ),
        'utf8',
    );

describe('target status chips', () => {
    it('shows truncated failure messages in a readable hover tooltip', () => {
        const component = source();

        expect(component).toContain('<Tooltip>');
        expect(component).toContain('<TooltipTrigger asChild>');
        expect(component).toContain('max-w-80');
        expect(component).toContain('block max-w-80');
        expect(component).toContain('[--tooltip-bg:var(--popover)]');
        expect(component).toContain('text-popover-foreground');
        expect(component).toContain('whitespace-normal');
        expect(component).toContain('whitespace-nowrap');
        expect(component).toContain('cursor-help');
        expect(component).toContain('tabIndex={0}');
        expect(component).not.toContain('title={target.error_message}');
    });
});
