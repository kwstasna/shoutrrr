import type { PlatformName } from '@/types/compose';

/**
 * Pure, DOM-free mirror of the server-side `PostSplitter` packing algorithm.
 * The composer's inline thread markers and the server publish path MUST agree on
 * where one section ends and the next begins; keeping the logic here (free of
 * tiptap/ProseMirror imports) lets a parity test exercise it against the PHP
 * implementation. See `tests/Fixtures/post-splitter-parity.json`.
 */

/**
 * Client-side mirror of the active platform's length measure. X counts UTF-16
 * code units (JS string length); the others approximate with code points. The
 * server's grapheme/byte count remains authoritative.
 */
export function measure(text: string, platform: PlatformName): number {
    // oxlint-disable-next-line no-misused-spread -- intentional code-point count
    return platform === 'x' ? text.length : [...text].length;
}

export interface Section {
    /** Indices (into the input paragraphs) of the paragraphs in this section. */
    paraIndices: number[];
    /** The section's text — its paragraphs joined by a single newline. */
    text: string;
}

/**
 * Paragraph-aware greedy split. Each paragraph either joins the current section
 * (when `current + "\n" + para` fits in `limit`) or starts a fresh one. A
 * paragraph that alone exceeds `limit` becomes a single overflowing section — we
 * never split mid-paragraph, so markers always sit on paragraph boundaries.
 *
 * Paragraphs are joined with a single "\n" to match the canonical base text
 * (blocks serialize newline-separated) and the server-side `PostSplitter`, so
 * the preview's section boundaries are exactly what gets published.
 */
export function packSections(
    paragraphs: string[],
    platform: PlatformName,
    limit: number,
): Section[] {
    const sections: Section[] = [];

    let cur = '';
    let curParas: number[] = [];

    for (let i = 0; i < paragraphs.length; i++) {
        const para = paragraphs[i] ?? '';
        const joined = cur === '' ? para : `${cur}\n${para}`;

        if (cur === '' || measure(joined, platform) <= limit) {
            cur = joined;
            curParas.push(i);

            continue;
        }

        sections.push({ paraIndices: curParas, text: cur });
        cur = para;
        curParas = [i];
    }

    sections.push({ paraIndices: curParas, text: cur });

    return sections;
}

/**
 * Split canonical base text into the section strings the composer previews —
 * the analogue of the server's `PostSplitter::split(...).sections` for the
 * common case where no single paragraph exceeds the limit.
 */
export function manualSegments(text: string): string[] {
    const segments = text
        .split(/^\s*---\s*$/gm)
        .map((segment) => segment.trim())
        .filter((segment) => segment !== '');

    return segments.length > 0 ? segments : [''];
}

export function previewSections(
    text: string,
    platform: PlatformName,
    limit: number,
): string[] {
    return manualSegments(text).flatMap((segment) =>
        packSections(segment.split('\n'), platform, limit).map(
            (section) => section.text,
        ),
    );
}
