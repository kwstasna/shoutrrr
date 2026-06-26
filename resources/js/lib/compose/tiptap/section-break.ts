import { mergeAttributes, Node } from '@tiptap/core';

export function shouldConvertEmptyParagraphToSectionBreak({
    currentBlockType,
    currentText,
    previousBlockType,
}: {
    currentBlockType: string;
    currentText: string;
    previousBlockType: string | null;
}): boolean {
    return (
        currentBlockType === 'paragraph' &&
        currentText.length === 0 &&
        previousBlockType !== null &&
        previousBlockType !== 'sectionBreak'
    );
}

export function shouldDeleteSectionBreakWithTrailingEmptyParagraph({
    currentBlockType,
    currentText,
    previousBlockType,
}: {
    currentBlockType: string;
    currentText: string;
    previousBlockType: string | null;
}): boolean {
    return (
        currentBlockType === 'paragraph' &&
        currentText.length === 0 &&
        previousBlockType === 'sectionBreak'
    );
}

declare module '@tiptap/core' {
    interface Commands<ReturnType> {
        sectionBreak: {
            insertSectionBreak: () => ReturnType;
        };
    }
}

/**
 * Manual thread-break atom node. Renders as a non-editable `<div data-section-break>`
 * and serializes to the canonical `---` boundary the server-side PostSplitter splits on.
 */
export const SectionBreak = Node.create({
    name: 'sectionBreak',
    group: 'block',
    atom: true,
    selectable: true,

    parseHTML() {
        return [{ tag: 'div[data-section-break]' }];
    },

    renderHTML({ HTMLAttributes }) {
        return [
            'div',
            mergeAttributes(HTMLAttributes, {
                'data-section-break': '',
                class: 'section-marker',
                contenteditable: 'false',
                'aria-hidden': 'true',
            }),
            ['span', { class: 'sm-rule' }],
            [
                'span',
                { class: 'sm-chip' },
                ['span', { class: 'sm-num' }, 'Next post'],
            ],
            ['span', { class: 'sm-rule' }],
        ];
    },

    addCommands() {
        return {
            insertSectionBreak:
                () =>
                ({ commands }) =>
                    commands.insertContent({ type: this.name }),
        };
    },

    addKeyboardShortcuts() {
        return {
            Enter: () => {
                const { selection } = this.editor.state;
                if (!selection.empty) {
                    return false;
                }

                const { $from } = selection;
                const containerDepth = $from.depth - 1;
                const currentIndex = $from.index(containerDepth);
                const previousBlock =
                    currentIndex > 0
                        ? $from.node(containerDepth).child(currentIndex - 1)
                        : null;

                if (
                    !shouldConvertEmptyParagraphToSectionBreak({
                        currentBlockType: $from.parent.type.name,
                        currentText: $from.parent.textContent,
                        previousBlockType: previousBlock?.type.name ?? null,
                    })
                ) {
                    return false;
                }

                return this.editor
                    .chain()
                    .deleteRange({
                        from: $from.before($from.depth),
                        to: $from.after($from.depth),
                    })
                    .insertContent([{ type: this.name }, { type: 'paragraph' }])
                    .focus()
                    .run();
            },
            Backspace: () => {
                const { selection } = this.editor.state;
                if (!selection.empty) {
                    return false;
                }

                const { $from } = selection;
                const containerDepth = $from.depth - 1;
                const currentIndex = $from.index(containerDepth);
                const previousBlock =
                    currentIndex > 0
                        ? $from.node(containerDepth).child(currentIndex - 1)
                        : null;

                if (
                    !shouldDeleteSectionBreakWithTrailingEmptyParagraph({
                        currentBlockType: $from.parent.type.name,
                        currentText: $from.parent.textContent,
                        previousBlockType: previousBlock?.type.name ?? null,
                    })
                ) {
                    return false;
                }

                const currentStart = $from.before($from.depth);
                const previousStart =
                    currentStart - (previousBlock?.nodeSize ?? 0);

                return this.editor
                    .chain()
                    .deleteRange({
                        from: previousStart,
                        to: $from.after($from.depth),
                    })
                    .focus()
                    .run();
            },
            'Mod-Shift-Enter': () => this.editor.commands.insertSectionBreak(),
        };
    },
});
