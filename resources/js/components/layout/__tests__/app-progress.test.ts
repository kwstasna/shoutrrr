import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';

import { describe, expect, it } from 'vitest';

describe('Inertia progress indicator color', () => {
    it('uses the same primary color token as default buttons', () => {
        const appSource = readFileSync(
            resolve(process.cwd(), 'resources/js/app.tsx'),
            'utf8',
        );
        const buttonSource = readFileSync(
            resolve(process.cwd(), 'resources/js/components/ui/button.tsx'),
            'utf8',
        );

        expect(buttonSource).toContain('default: "bg-primary');
        expect(appSource).toContain("color: 'var(--primary)'");
    });
});
