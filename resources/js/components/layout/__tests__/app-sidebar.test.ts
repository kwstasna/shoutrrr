import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';

import { describe, expect, it } from 'vitest';

import { workspaceSettingsLabel } from '../app-sidebar';

describe('workspaceSettingsLabel', () => {
    it('identifies the sidebar destination as workspace settings', () => {
        expect(workspaceSettingsLabel).toBe('Workspace settings');
    });
});

describe('sidebar nav click targets', () => {
    it('lets sidebar links receive clicks from their SVG icons', () => {
        const source = readFileSync(
            resolve(process.cwd(), 'resources/js/components/ui/sidebar.tsx'),
            'utf8',
        );

        expect(source).toContain('[&_svg]:pointer-events-none');
    });

    it('keeps collapsed invisible group labels from covering nearby icons', () => {
        const source = readFileSync(
            resolve(process.cwd(), 'resources/js/components/ui/sidebar.tsx'),
            'utf8',
        );

        expect(source).toContain(
            'group-data-[collapsible=icon]:pointer-events-none group-data-[collapsible=icon]:-mt-8 group-data-[collapsible=icon]:opacity-0',
        );
    });
});
