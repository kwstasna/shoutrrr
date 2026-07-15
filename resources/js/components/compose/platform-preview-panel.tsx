import {
    BarChart3,
    CheckCircle2,
    Eye,
    Heart,
    ImageIcon,
    MessageCircle,
    Repeat2,
    Send,
    X,
} from 'lucide-react';

import { PlatformGlyph } from '@/components/common/platform-glyph';
import { Avatar, AvatarFallback, AvatarImage } from '@/components/ui/avatar';
import type {
    PlatformPreview,
    PlatformPreviewItem,
} from '@/lib/compose/platform-preview';
import { LinkedText } from '@/lib/linked-text';
import { cn } from '@/lib/utils';
import type { PlatformName } from '@/types/compose';

const PLATFORM_LABELS: Record<PlatformName, string> = {
    x: 'X',
    bluesky: 'Bluesky',
    linkedin: 'LinkedIn',
    facebook: 'Facebook',
    instagram: 'Instagram',
    threads: 'Threads',
    discord: 'Discord',
};

const PLATFORM_GLYPH_CLASS: Record<PlatformName, string> = {
    x: 'text-foreground',
    bluesky: 'text-sky-500',
    linkedin: 'text-blue-600',
    facebook: 'text-[#1877F2]',
    instagram: 'text-[#E4405F]',
    threads: 'text-foreground',
    discord: 'text-[#5865F2]',
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

/**
 * A 9:16 Instagram Story frame (1080×1920). Renders the single photo/video
 * full-bleed with the real story chrome — top progress bar + author header, bottom
 * reply bar — so the user can see what Instagram overlays and keep key content out
 * of the top/bottom safe zones (~250px each on a 1920px-tall canvas ≈ 13%).
 */
function StoryPreview({ preview }: { preview: PlatformPreview }) {
    const media = preview.items[0]?.media[0] ?? null;

    return (
        <div className="p-4">
            <div className="mx-auto w-full max-w-[248px]">
                <div className="relative aspect-[9/16] overflow-hidden rounded-2xl bg-neutral-900 text-white shadow-sm ring-1 ring-border">
                    {media ? (
                        media.kind === 'video' ? (
                            <video
                                src={media.url}
                                className="absolute inset-0 size-full object-cover"
                                muted
                            />
                        ) : (
                            <img
                                src={media.url}
                                alt={media.alt_text ?? ''}
                                className="absolute inset-0 size-full object-cover"
                            />
                        )
                    ) : (
                        <div className="absolute inset-0 grid place-items-center bg-gradient-to-b from-neutral-700 to-neutral-900 text-center">
                            <div className="space-y-1.5 px-4 text-white/70">
                                <ImageIcon className="mx-auto size-6" />
                                <p className="text-[12px] leading-4">
                                    Add one photo or video to preview your story
                                </p>
                            </div>
                        </div>
                    )}

                    {/* Top scrim + progress bar + author (top safe zone) */}
                    <div className="absolute inset-x-0 top-0 bg-gradient-to-b from-black/45 to-transparent px-3 pt-2.5 pb-6">
                        <div className="h-0.5 w-full overflow-hidden rounded-full bg-white/40">
                            <div className="h-full w-1/3 rounded-full bg-white" />
                        </div>
                        <div className="mt-2.5 flex items-center gap-2">
                            <Avatar className="size-6 ring-1 ring-white/70">
                                <AvatarImage
                                    src={preview.avatarUrl ?? undefined}
                                />
                                <AvatarFallback className="text-[9px] font-semibold text-foreground">
                                    {initials(preview.accountName)}
                                </AvatarFallback>
                            </Avatar>
                            <span className="truncate text-[12px] font-semibold drop-shadow">
                                {preview.accountName}
                            </span>
                            <span className="text-[11px] text-white/80 drop-shadow">
                                now
                            </span>
                            <X className="ml-auto size-4 text-white/90 drop-shadow" />
                        </div>
                    </div>

                    {/* Bottom scrim + reply bar (bottom safe zone) */}
                    <div className="absolute inset-x-0 bottom-0 flex items-center gap-2 bg-gradient-to-t from-black/45 to-transparent px-3 pt-6 pb-2.5">
                        <span className="flex-1 rounded-full border border-white/60 px-3 py-1 text-[11px] text-white/90">
                            Send message
                        </span>
                        <Heart className="size-4 text-white/90 drop-shadow" />
                        <Send className="size-4 text-white/90 drop-shadow" />
                    </div>
                </div>
                <p className="mt-3 text-center text-[12px] leading-5 text-muted-foreground">
                    9:16 · 1080×1920 · keep key content clear of the top and
                    bottom bars. Captions aren&apos;t shown on stories.
                </p>
            </div>
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
                    <LinkedText
                        text={item.text}
                        platform={preview.platform}
                        linkExclusions={item.linkExclusions}
                        emptyFallback={`Start writing to preview your ${label} post.`}
                    />
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
    const label = preview ? PLATFORM_LABELS[preview.platform] : null;

    return (
        <aside className="rounded-xl border bg-card text-card-foreground shadow-sm">
            {/* h-[45px] matches the composer's top bar so the two cards' headers
            line up when shown side by side. */}
            <div className="flex h-[45px] items-center gap-2 border-b border-border px-4">
                {preview ? (
                    <span
                        className={cn(
                            'grid size-5 place-items-center rounded-md border bg-background',
                            PLATFORM_GLYPH_CLASS[preview.platform],
                        )}
                    >
                        <PlatformGlyph platform={preview.platform} size={11} />
                    </span>
                ) : (
                    <span className="grid size-5 place-items-center rounded-md border bg-background text-muted-foreground">
                        <Eye className="size-3" />
                    </span>
                )}
                <div className="min-w-0">
                    <h2 className="truncate text-[13px] leading-tight font-semibold tracking-tight">
                        {preview
                            ? preview.format === 'story'
                                ? 'How it lands as an Instagram story'
                                : `How it lands on ${label}`
                            : 'Post preview'}
                    </h2>
                    <p className="truncate text-[12px] leading-tight text-muted-foreground">
                        {preview
                            ? 'Live preview from the current draft'
                            : 'See how your draft lands on each platform'}
                    </p>
                </div>
            </div>

            {!preview ? (
                <div className="flex flex-col items-center gap-3 px-4 py-12 text-center">
                    <span className="grid size-10 place-items-center rounded-full bg-muted text-muted-foreground">
                        <Eye className="size-4" />
                    </span>
                    <div className="space-y-1">
                        <p className="text-[13px] font-medium text-foreground">
                            Nothing to preview yet
                        </p>
                        <p className="text-[12px] leading-5 text-muted-foreground">
                            Connect an account to see how your post will appear.
                        </p>
                    </div>
                </div>
            ) : preview.format === 'story' ? (
                <StoryPreview preview={preview} />
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
