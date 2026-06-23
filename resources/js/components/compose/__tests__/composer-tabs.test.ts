import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';

import { describe, expect, it } from 'vitest';

describe('composer platform tabs', () => {
    it('uses the section count chip for every platform', () => {
        const source = readFileSync(
            resolve(
                process.cwd(),
                'resources/js/components/compose/composer.tsx',
            ),
            'utf8',
        );

        expect(source).toContain(
            'return String(target?.sections.length ?? 1);',
        );
        expect(source).not.toContain("account.platform === 'linkedin'");
    });
});
