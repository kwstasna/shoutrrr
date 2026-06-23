import { describe, expect, it } from 'vitest';

import { shouldFocusEditorOnMount } from '../editor-body';

describe('shouldFocusEditorOnMount', () => {
    it('focuses only when autofocus is requested and the editor is editable', () => {
        expect(shouldFocusEditorOnMount(true, true)).toBe(true);
        expect(shouldFocusEditorOnMount(false, true)).toBe(false);
        expect(shouldFocusEditorOnMount(true, false)).toBe(false);
    });
});
