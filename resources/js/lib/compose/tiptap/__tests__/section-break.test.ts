import { getSchema } from '@tiptap/core';
import type { Node as PMNode } from '@tiptap/pm/model';
import { describe, expect, it } from 'vitest';

import { measureSectionAfterBreak } from '@/lib/compose/tiptap/section-break';
import { composerExtensions } from '@/lib/compose/tiptap/setup';

const schema = getSchema(composerExtensions());

function para(text: string) {
    return text === ''
        ? { type: 'paragraph' }
        : { type: 'paragraph', content: [{ type: 'text', text }] };
}

function docFrom(blocks: object[]): PMNode {
    return schema.nodeFromJSON({ type: 'doc', content: blocks });
}

/** Absolute position immediately before the top-level child at `index`. */
function posBeforeChild(doc: PMNode, index: number): number {
    let pos = 0;
    for (let i = 0; i < index; i++) {
        pos += doc.child(i).nodeSize;
    }

    return pos;
}

describe('measureSectionAfterBreak', () => {
    it('counts the paragraphs that follow the break up to the next break', () => {
        const doc = docFrom([
            para('first post'),
            { type: 'sectionBreak' },
            para('second'),
            para('post'),
            { type: 'sectionBreak' },
            para('third'),
        ]);

        // "second\npost" → 11 chars.
        expect(
            measureSectionAfterBreak(doc, posBeforeChild(doc, 1), 'bluesky'),
        ).toBe(11);
        // "third" → 5 chars.
        expect(
            measureSectionAfterBreak(doc, posBeforeChild(doc, 4), 'bluesky'),
        ).toBe(5);
    });

    it('returns 0 for a trailing break with no following paragraphs', () => {
        const doc = docFrom([para('only post'), { type: 'sectionBreak' }]);

        expect(
            measureSectionAfterBreak(doc, posBeforeChild(doc, 1), 'bluesky'),
        ).toBe(0);
    });
});
