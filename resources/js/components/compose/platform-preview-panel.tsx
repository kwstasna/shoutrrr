import {
    BarChart3,
    CheckCircle2,
    Heart,
    MessageCircle,
    Repeat2,
} from 'lucide-react';

import { PlatformGlyph } from '@/components/common/platform-glyph';
import { Avatar, AvatarFallback, AvatarImage } from '@/components/ui/avatar';
import type {
    PlatformPreview,
    PlatformPreviewItem,
} from '@/lib/compose/platform-preview';
import { cn } from '@/lib/utils';
import type { PlatformName } from '@/types/compose';

const PLATFORM_LABELS: Record<PlatformName, string> = {
    x: 'X',
    bluesky: 'Bluesky',
    linkedin: 'LinkedIn',
};

const PLATFORM_GLYPH_CLASS: Record<PlatformName, string> = {
    x: 'text-foreground',
    bluesky: 'text-sky-500',
    linkedin: 'text-blue-600',
};

function initials(name: string): string {
    return name
        .split(/\s+/)
        .filter(Boolean)
        .map((part) => part[0])
        .join('')
        .slice(0, 2)
        .toUpperCase();
}

function platformActions(platform: PlatformName): string {
    if (platform === 'linkedin') {
        return 'Comment · Repost · Like · Analytics';
    }
    if (platform === 'bluesky') {
        return 'Reply · Repost · Like';
    }

    return 'Reply · Repost · Like · Views';
}

function previewSummary(preview: PlatformPreview): string {
    const label = PLATFORM_LABELS[preview.platform];

    if (preview.platform === 'linkedin') {
        return 'Posted as one LinkedIn update.';
    }

    if (preview.autoSplit && preview.items.length > 1) {
        return `Auto-split into a ${preview.items.length}-post ${label} thread.`;
    }

    return `Posted as one ${label} update unless you add more split markers.`;
}

function PlatformPreviewMedia({ item }: { item: PlatformPreviewItem }) {
    if (item.media.length === 0) {
        return null;
    }

    return (
        <div
            className={cn(
                'mt-3 grid overflow-hidden rounded-2xl border border-border bg-muted/40',
                item.media.length === 1 ? 'grid-cols-1' : 'grid-cols-2',
            )}
        >
            {item.media.slice(0, 4).map((media) => (
                <div
                    key={media.id}
                    className="relative aspect-video overflow-hidden bg-muted"
                >
                    {media.kind === 'video' ? (
                        <video
                            src={media.url}
                            className="size-full object-cover"
                            muted
                        />
                    ) : (
                        <img
                            src={media.url}
                            alt={media.alt_text ?? ''}
                            className="size-full object-cover"
                        />
                    )}
                </div>
            ))}
        </div>
    );
}

function PlatformPreviewPost({
    preview,
    item,
    isLast,
}: {
    preview: PlatformPreview;
    item: PlatformPreviewItem;
    isLast: boolean;
}) {
    const label = PLATFORM_LABELS[preview.platform];

    return (
        <article className="relative grid grid-cols-[40px_minmax(0,1fr)] gap-3">
            {!isLast && preview.platform !== 'linkedin' && (
                <div
                    className="absolute top-11 bottom-0 left-5 w-px bg-border"
                    aria-hidden
                />
            )}
            <Avatar className="z-10 size-10 bg-background">
                <AvatarImage src={preview.avatarUrl ?? undefined} />
                <AvatarFallback className="text-[11px] font-semibold">
                    {initials(preview.accountName)}
                </AvatarFallback>
            </Avatar>
            <div className="min-w-0 pb-5">
                <div className="flex min-w-0 items-center gap-1.5 text-[13px] leading-5">
                    <span className="truncate font-semibold text-foreground">
                        {preview.accountName}
                    </span>
                    {preview.platform !== 'linkedin' && (
                        <CheckCircle2
                            className="size-3.5 shrink-0 fill-sky-500 text-background"
                            aria-hidden
                        />
                    )}
                    <span className="truncate text-muted-foreground">
                        {preview.accountHandle} · now
                    </span>
                </div>
                <p
                    className={cn(
                        'mt-0.5 text-[14px] leading-5 wrap-anywhere whitespace-pre-wrap text-foreground',
                        item.overLimit && 'text-destructive',
                    )}
                >
                    {item.text ||
                        `Start writing to preview your ${label} post.`}
                </p>
                <PlatformPreviewMedia item={item} />
                <div className="mt-3 flex items-center gap-4 text-muted-foreground">
                    <span className="inline-flex items-center gap-1 text-[12px]">
                        <MessageCircle className="size-3.5" />
                        Preview
                    </span>
                    <Repeat2 className="size-3.5" aria-hidden />
                    <Heart className="size-3.5" aria-hidden />
                    {preview.platform !== 'bluesky' && (
                        <BarChart3 className="size-3.5" aria-hidden />
                    )}
                    <span className="sr-only">
                        {platformActions(preview.platform)}
                    </span>
                </div>
                {item.overLimit && (
                    <p className="mt-2 text-[12px] font-medium text-destructive">
                        {item.count}/{preview.limit} characters
                    </p>
                )}
            </div>
        </article>
    );
}

export function PlatformPreviewPanel({
    preview,
}: {
    preview: PlatformPreview | null;
}) {
    const platform = preview?.platform ?? 'x';
    const label = PLATFORM_LABELS[platform];

    return (
        <aside className="rounded-xl border bg-card text-card-foreground shadow-sm">
            <div className="flex items-center gap-2 border-b border-border px-4 py-3">
                <span
                    className={cn(
                        'grid size-5 place-items-center rounded-md border bg-background',
                        PLATFORM_GLYPH_CLASS[platform],
                    )}
                >
                    <PlatformGlyph platform={platform} size={11} />
                </span>
                <div className="min-w-0">
                    <h2 className="truncate text-[13px] font-semibold tracking-tight">
                        How it lands on {label}
                    </h2>
                    <p className="text-[12px] text-muted-foreground">
                        Live preview from the current draft
                    </p>
                </div>
            </div>

            {!preview ? (
                <div className="px-4 py-10 text-center text-[13px] text-muted-foreground">
                    Select a destination to see its live platform preview.
                </div>
            ) : (
                <div className="p-4">
                    <div className="rounded-3xl border border-border bg-background px-4 pt-4 shadow-xs">
                        {preview.items.map((item, index) => (
                            <PlatformPreviewPost
                                key={item.id}
                                preview={preview}
                                item={item}
                                isLast={index === preview.items.length - 1}
                            />
                        ))}
                    </div>
                    <p className="mt-3 text-[12px] leading-5 text-muted-foreground">
                        {previewSummary(preview)}
                    </p>
                </div>
            )}
        </aside>
    );
}
