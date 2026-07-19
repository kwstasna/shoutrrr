import {
    ChevronLeft,
    ChevronRight,
    Clapperboard,
    Heart,
    ImageIcon,
    Send,
    Share2,
    ThumbsUp,
    X,
} from 'lucide-react';
import { useRef, useState } from 'react';

import { Avatar, AvatarFallback, AvatarImage } from '@/components/ui/avatar';
import type { PlatformPreview } from '@/lib/compose/platform-preview';
import { cn } from '@/lib/utils';

import {
    clampIndex,
    handleName,
    previewInitials,
    previewMedia,
} from './helpers';
import { PreviewVideo } from './preview-video';

/** Horizontal drag past this many pixels advances to the next/previous segment. */
const SWIPE_THRESHOLD = 40;

type StoryPlatform = 'instagram' | 'facebook';

/**
 * A 9:16 story preview. Every attachment becomes its own segment — the way
 * Instagram and Facebook publish a multi-item story: one 9:16 frame per photo or
 * video, tapped through in order. The top shows one progress bar per segment, and
 * segments are navigable by swipe, the hover arrows, or the progress bar. Video
 * segments autoplay muted with the shared mute/unmute control.
 */
export function StoryFrame({
    preview,
    platform,
}: {
    preview: PlatformPreview;
    platform: StoryPlatform;
}) {
    const media = previewMedia(preview);
    const [index, setIndex] = useState(0);
    const dragStartX = useRef<number | null>(null);
    const safeIndex = clampIndex(index, media.length);
    const current = media[safeIndex] ?? null;
    const segments = Math.max(media.length, 1);
    const many = media.length > 1;
    const name =
        platform === 'facebook' ? preview.accountName : handleName(preview);

    const go = (delta: number) => {
        setIndex((previous) => clampIndex(previous + delta, media.length));
    };

    return (
        <div className="mx-auto w-full max-w-[248px]">
            <div
                className="group relative aspect-[9/16] touch-pan-y overflow-hidden rounded-2xl bg-neutral-900 text-white shadow-sm ring-1 ring-border select-none"
                onPointerDown={
                    many
                        ? (event) => {
                              dragStartX.current = event.clientX;
                          }
                        : undefined
                }
                onPointerUp={
                    many
                        ? (event) => {
                              if (dragStartX.current === null) {
                                  return;
                              }
                              const dx = event.clientX - dragStartX.current;
                              dragStartX.current = null;
                              if (dx <= -SWIPE_THRESHOLD) {
                                  go(1);
                              } else if (dx >= SWIPE_THRESHOLD) {
                                  go(-1);
                              }
                          }
                        : undefined
                }
                onPointerCancel={() => {
                    dragStartX.current = null;
                }}
            >
                {current ? (
                    current.kind === 'video' ? (
                        <PreviewVideo
                            key={current.id}
                            src={current.url}
                            className="absolute inset-0 size-full"
                            buttonClassName="bottom-14 right-3"
                        />
                    ) : (
                        <img
                            key={current.id}
                            src={current.url}
                            alt={current.alt_text ?? ''}
                            draggable={false}
                            className="absolute inset-0 size-full object-cover"
                        />
                    )
                ) : (
                    <div
                        className={cn(
                            'absolute inset-0 grid place-items-center bg-gradient-to-b text-center',
                            platform === 'facebook'
                                ? 'from-[#1b2a4a] to-neutral-900'
                                : 'from-neutral-700 to-neutral-900',
                        )}
                    >
                        <div className="space-y-1.5 px-4 text-white/70">
                            {platform === 'facebook' ? (
                                <Clapperboard
                                    className="mx-auto size-6"
                                    aria-hidden
                                />
                            ) : (
                                <ImageIcon
                                    className="mx-auto size-6"
                                    aria-hidden
                                />
                            )}
                            <p className="text-[12px] leading-4">
                                Add one photo or video to preview your story
                            </p>
                        </div>
                    </div>
                )}

                {/* Top scrim: one progress segment per media, then the author. */}
                <div className="absolute inset-x-0 top-0 bg-gradient-to-b from-black/45 to-transparent px-3 pt-2.5 pb-6">
                    <div className="flex gap-1">
                        {Array.from({ length: segments }).map((_, segment) => (
                            <div
                                key={segment}
                                className="h-0.5 flex-1 overflow-hidden rounded-full bg-white/40"
                            >
                                <div
                                    className={cn(
                                        'h-full rounded-full bg-white',
                                        segment < safeIndex
                                            ? 'w-full'
                                            : segment === safeIndex
                                              ? 'w-[45%]'
                                              : 'w-0',
                                    )}
                                />
                            </div>
                        ))}
                    </div>
                    <div className="mt-2.5 flex items-center gap-2">
                        <Avatar className="size-6 ring-1 ring-white/70">
                            <AvatarImage src={preview.avatarUrl ?? undefined} />
                            <AvatarFallback className="text-[9px] font-semibold text-foreground">
                                {previewInitials(preview.accountName)}
                            </AvatarFallback>
                        </Avatar>
                        <span className="truncate text-[12px] font-semibold drop-shadow">
                            {name}
                        </span>
                        <span className="text-[11px] text-white/80 drop-shadow">
                            now
                        </span>
                        <X className="ml-auto size-4 text-white/90 drop-shadow" />
                    </div>
                </div>

                {many && (
                    <>
                        {safeIndex > 0 && (
                            <button
                                type="button"
                                aria-label="Previous story"
                                onClick={() => go(-1)}
                                className="absolute top-1/2 left-1.5 z-10 grid size-6 -translate-y-1/2 place-items-center rounded-full bg-white/85 text-neutral-800 opacity-0 shadow-sm transition-opacity group-hover:opacity-100 focus-visible:opacity-100"
                            >
                                <ChevronLeft className="size-4" aria-hidden />
                            </button>
                        )}
                        {safeIndex < media.length - 1 && (
                            <button
                                type="button"
                                aria-label="Next story"
                                onClick={() => go(1)}
                                className="absolute top-1/2 right-1.5 z-10 grid size-6 -translate-y-1/2 place-items-center rounded-full bg-white/85 text-neutral-800 opacity-0 shadow-sm transition-opacity group-hover:opacity-100 focus-visible:opacity-100"
                            >
                                <ChevronRight className="size-4" aria-hidden />
                            </button>
                        )}
                    </>
                )}

                {/* Bottom scrim: reply bar with the platform's quick actions. */}
                <div className="absolute inset-x-0 bottom-0 flex items-center gap-2 bg-gradient-to-t from-black/45 to-transparent px-3 pt-6 pb-2.5">
                    <span className="flex-1 rounded-full border border-white/60 px-3 py-1 text-[11px] text-white/90">
                        Send message
                    </span>
                    {platform === 'facebook' ? (
                        <>
                            <span className="grid size-6 place-items-center rounded-full bg-[#1877F2]">
                                <ThumbsUp
                                    className="size-3.5 text-white"
                                    aria-hidden
                                />
                            </span>
                            <Share2
                                className="size-4 text-white/90 drop-shadow"
                                aria-hidden
                            />
                        </>
                    ) : (
                        <>
                            <Heart
                                className="size-4 text-white/90 drop-shadow"
                                aria-hidden
                            />
                            <Send
                                className="size-4 text-white/90 drop-shadow"
                                aria-hidden
                            />
                        </>
                    )}
                </div>
            </div>
            <p className="mt-3 text-center text-[12px] leading-5 text-muted-foreground">
                {many
                    ? `${media.length} attachments → ${media.length} story segments, each posted as its own 9:16 frame. `
                    : ''}
                9:16 · 1080×1920 · keep key content clear of the top and bottom
                bars.
            </p>
        </div>
    );
}
