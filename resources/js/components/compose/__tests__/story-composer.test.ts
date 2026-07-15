/** @vitest-environment jsdom */

import { act, createElement } from 'react';
import { createRoot, type Root } from 'react-dom/client';
import { afterEach, describe, expect, it, vi } from 'vitest';

import { StoryComposer } from '@/components/compose/story-composer';
import type { MediaView } from '@/types/compose';

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

function imageMedia(overrides: Partial<MediaView> = {}): MediaView {
    return {
        id: 'm1',
        url: 'https://example.test/story.jpg',
        mime: 'image/jpeg',
        kind: 'image',
        duration_seconds: null,
        alt_text: null,
        position: 0,
        edit_settings: null,
        source_url: null,
        ...overrides,
    } as MediaView;
}

function render(props: Parameters<typeof StoryComposer>[0]): HTMLDivElement {
    const container = document.createElement('div');
    document.body.append(container);
    mountedContainer = container;
    mountedRoot = createRoot(container);

    act(() => {
        mountedRoot?.render(createElement(StoryComposer, props));
    });

    return container;
}

describe('story composer', () => {
    it('shows an upload dropzone and no media when empty', () => {
        const container = render({
            media: [],
            onAddFiles: () => {},
            onRemove: () => {},
        });

        expect(container.textContent).toContain('Upload a photo or video');
        expect(container.querySelector('img')).toBeNull();
    });

    it('renders the uploaded image with a remove control', () => {
        const onRemove = vi.fn();
        const container = render({
            media: [imageMedia()],
            onAddFiles: () => {},
            onRemove,
        });

        const img = container.querySelector('img');
        expect(img?.getAttribute('src')).toBe('https://example.test/story.jpg');

        const remove = container.querySelector('[aria-label="Remove media"]');
        act(() => {
            (remove as HTMLButtonElement).click();
        });

        expect(onRemove).toHaveBeenCalledWith('m1');
    });

    it('renders a video story with object-cover, muted playback', () => {
        const container = render({
            media: [imageMedia({ kind: 'video', mime: 'video/mp4' })],
            onAddFiles: () => {},
            onRemove: () => {},
        });

        const video = container.querySelector('video');
        expect(video).not.toBeNull();
        expect((video as HTMLVideoElement).muted).toBe(true);
    });

    it('hides the remove and upload controls when read-only', () => {
        const container = render({
            media: [imageMedia()],
            readOnly: true,
            onAddFiles: () => {},
            onRemove: () => {},
        });

        expect(
            container.querySelector('[aria-label="Remove media"]'),
        ).toBeNull();
        expect(container.textContent).not.toContain('Replace media');
    });
});
