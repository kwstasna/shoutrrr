import { describe, expect, it } from 'vitest';

import { postCapabilities } from '@/lib/posts/capabilities';
import type { PostView } from '@/types/compose';

function post(partial: Partial<PostView>): PostView {
    return {
        id: 'p',
        base_text: '',
        status: 'draft',
        published_at: null,
        updated_at: '',
        scheduled_at: null,
        destination: { kind: 'all', id: null },
        targets: [],
        media: [],
        ...partial,
    } as PostView;
}

describe('postCapabilities', () => {
    it('draft: edit/schedule/delete', () => {
        const c = postCapabilities(post({ status: 'draft' }));
        expect(c).toMatchObject({
            canEdit: true,
            canSchedule: true,
            canDelete: true,
            canReschedule: false,
        });
    });
    it('scheduled: edit/reschedule/unschedule/delete', () => {
        const c = postCapabilities(post({ status: 'scheduled' }));
        expect(c).toMatchObject({
            canReschedule: true,
            canUnschedule: true,
            canDelete: true,
            canSchedule: false,
        });
    });
    it('failed with a failed target: delete + retry', () => {
        const c = postCapabilities(
            post({
                status: 'failed',
                targets: [{ status: 'failed' } as PostView['targets'][number]],
            }),
        );
        expect(c).toMatchObject({
            canDelete: true,
            canRetry: true,
            canEdit: false,
        });
    });
    it('publishing/deleted: nothing', () => {
        expect(postCapabilities(post({ status: 'publishing' })).canDelete).toBe(
            false,
        );
        expect(postCapabilities(post({ status: 'deleted' })).canDelete).toBe(
            false,
        );
    });
    it('tolerates a partial payload with no targets (canRetry false, no throw)', () => {
        const partial = { status: 'failed' } as unknown as PostView;
        expect(() => postCapabilities(partial)).not.toThrow();
        expect(postCapabilities(partial).canRetry).toBe(false);
    });
});
