/** @vitest-environment jsdom */

import { act, createElement } from 'react';
import { createRoot, type Root } from 'react-dom/client';
import { afterEach, describe, expect, it, vi } from 'vitest';

import {
    type InstagramFormat,
    InstagramFormatToggle,
} from '@/components/compose/instagram-format-toggle';

let mountedRoot: Root | null = null;
let mountedContainer: HTMLDivElement | null = null;

afterEach(() => {
    if (mountedRoot) {
        act(() => mountedRoot?.unmount());
    }
    mountedContainer?.remove();
    mountedRoot = null;
    mountedContainer = null;
});

function render(
    value: InstagramFormat,
    onChange: (format: InstagramFormat) => void,
): HTMLDivElement {
    const container = document.createElement('div');
    document.body.append(container);
    mountedContainer = container;
    mountedRoot = createRoot(container);

    act(() => {
        mountedRoot?.render(
            createElement(InstagramFormatToggle, { value, onChange }),
        );
    });

    return container;
}

describe('instagram format toggle', () => {
    it('renders Post and Story options with the active one checked', () => {
        const container = render('feed', () => {});
        const radios = [...container.querySelectorAll('[role="radio"]')];

        expect(radios).toHaveLength(2);
        expect(radios.map((r) => r.textContent)).toEqual(['Post', 'Story']);
        expect(radios[0].getAttribute('aria-checked')).toBe('true');
        expect(radios[1].getAttribute('aria-checked')).toBe('false');
    });

    it('reflects the story value as checked', () => {
        const container = render('story', () => {});
        const radios = container.querySelectorAll('[role="radio"]');

        expect(radios[1].getAttribute('aria-checked')).toBe('true');
    });

    it('calls onChange with the picked format', () => {
        const onChange = vi.fn();
        const container = render('feed', onChange);
        const story = container.querySelectorAll('[role="radio"]')[1];

        act(() => {
            (story as HTMLButtonElement).click();
        });

        expect(onChange).toHaveBeenCalledWith('story');
    });
});
