import type { PostView } from '@/pages/compose/types';

export interface PostCapabilities {
    canEdit: boolean;
    canSchedule: boolean;
    canReschedule: boolean;
    canUnschedule: boolean;
    canDelete: boolean;
    canRetry: boolean;
}

const NONE: PostCapabilities = {
    canEdit: false,
    canSchedule: false,
    canReschedule: false,
    canUnschedule: false,
    canDelete: false,
    canRetry: false,
};

export function postCapabilities(post: PostView): PostCapabilities {
    // Tolerate partial Inertia payloads that omit targets (e.g. lighter feed rows).
    const hasFailedTarget = (post.targets ?? []).some(
        (t) => t.status === 'failed',
    );
    switch (post.status) {
        case 'draft':
            return {
                ...NONE,
                canEdit: true,
                canSchedule: true,
                canDelete: true,
            };
        case 'scheduled':
            return {
                ...NONE,
                canEdit: true,
                canReschedule: true,
                canUnschedule: true,
                canDelete: true,
            };
        case 'published':
        case 'partial':
        case 'failed':
            return { ...NONE, canDelete: true, canRetry: hasFailedTarget };
        default:
            return NONE;
    }
}
