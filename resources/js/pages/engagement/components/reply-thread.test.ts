import { createElement } from 'react';
import { renderToStaticMarkup } from 'react-dom/server';
import { describe, expect, it, vi } from 'vitest';

import type { ReplyItem } from '../types';
import { ReplyThread } from './reply-thread';

const platforms: ReplyItem['platform'][] = ['x', 'bluesky', 'linkedin'];

function reply(overrides: Partial<ReplyItem> = {}): ReplyItem {
    return {
        id: 'r1',
        platform: 'instagram',
        remote_reply_id: 'C1',
        author_handle: 'fan',
        author_name: null,
        author_avatar_url: null,
        text: 'nice',
        remote_created_at: '2026-07-01T12:00:00+00:00',
        is_read: true,
        is_liked: false,
        is_hidden: false,
        can_hide: false,
        is_ours: false,
        send_status: null,
        status: 'pending',
        post_target_id: 't1',
        post_id: 'p1',
        post_remote_id: 'MEDIA1',
        post_excerpt: null,
        account_handle: '@me',
        account_max_text_length: 2200,
        account_disabled: false,
        ...overrides,
    };
}

function render(
    platform: ReplyItem['platform'],
    thread: ReplyItem[] = [],
): string {
    return renderToStaticMarkup(
        createElement(ReplyThread, {
            postExcerpt: 'hello, this is just a test',
            postUrl: 'https://example.com/post',
            platform,
            thread,
            loading: false,
            onToggleLike: vi.fn(),
            onToggleHide: vi.fn(),
            onDelete: vi.fn(),
        }),
    );
}

describe('ReplyThread', () => {
    it('labels the source post link with the platform name', () => {
        expect(render('x')).toContain('Open post on X');
        expect(render('bluesky')).toContain('Open post on Bluesky');
        expect(render('linkedin')).toContain('Open post on LinkedIn');
    });

    it.each(platforms)(
        'does not render the generic open post label for %s',
        (platform) => {
            expect(render(platform)).not.toContain('>Open post<');
        },
    );

    it('offers a Hide control on a moderatable inbound comment', () => {
        const markup = render('instagram', [reply({ can_hide: true })]);

        expect(markup).toContain('Hide comment');
        expect(markup).not.toContain('Hidden');
    });

    it('shows a Hidden badge and an Unhide control once hidden', () => {
        const markup = render('instagram', [
            reply({ can_hide: true, is_hidden: true }),
        ]);

        expect(markup).toContain('Unhide comment');
        expect(markup).toContain('Hidden');
    });

    it('omits the Hide control where the platform cannot moderate comments', () => {
        const markup = render('x', [reply({ platform: 'x', can_hide: false })]);

        expect(markup).not.toContain('Hide comment');
    });
});
