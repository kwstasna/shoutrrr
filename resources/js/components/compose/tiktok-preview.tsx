import {
    Bookmark,
    Heart,
    ImageIcon,
    MessageCircle,
    Music2,
    Pause,
    Play,
    Plus,
    Share2,
    Volume2,
    VolumeX,
} from 'lucide-react';
import { useCallback, useEffect, useRef, useState } from 'react';

import { Avatar, AvatarFallback, AvatarImage } from '@/components/ui/avatar';
import type { PlatformPreview } from '@/lib/compose/platform-preview';
import { cn } from '@/lib/utils';
import type { MediaView } from '@/types/compose';

/** How long each photo shows before advancing, matching TikTok's photo mode. */
const PHOTO_ADVANCE_MS = 2500;

/** A horizontal drag beyond this many pixels counts as a swipe, not a tap. */
const SWIPE_THRESHOLD_PX = 40;

type Props = {
    preview: PlatformPreview;
};

/**
 * A 9:16 TikTok frame (1080×1920) rendering the draft the way TikTok will.
 *
 * Goes further than a static poster frame deliberately: a TikTok post is
 * *motion*, so a frozen frame would not tell the user what they are actually
 * publishing. Video autoplays muted and loops, and a photo post plays as the
 * auto-advancing carousel TikTok calls "photo mode" — both exactly as a viewer
 * would meet them.
 *
 * The chrome (right rail, caption, music ticker) is reproduced so the user can
 * see what TikTok overlays and keep key content out of the safe zones. Engagement
 * counts render as 0 rather than as plausible-looking sample numbers: the post
 * does not exist yet, and inventing "12.3K likes" would be showing the user
 * fabricated data about their own content.
 */
export function TikTokPreview({ preview }: Props) {
    const item = preview.items[0] ?? null;
    const media = item?.media ?? [];
    const caption = item?.text ?? '';

    const video = media.find((m) => m.kind === 'video') ?? null;
    // The composer already prevents mixing video and images on one post, so a
    // video means "video post" and anything else is a photo carousel.
    const photos = video ? [] : media;

    return (
        <div className="p-4">
            <div className="mx-auto w-full max-w-[248px]">
                <div className="relative aspect-[9/16] overflow-hidden rounded-2xl bg-black text-white shadow-sm ring-1 ring-border">
                    {video ? (
                        <TikTokVideoStage media={video} />
                    ) : photos.length > 0 ? (
                        <TikTokPhotoStage photos={photos} />
                    ) : (
                        <EmptyStage />
                    )}

                    <FeedHeader />

                    <TikTokChrome
                        preview={preview}
                        caption={caption}
                        photoCount={photos.length}
                    />
                </div>

                <p className="mt-3 text-center text-[12px] leading-5 text-muted-foreground">
                    {video
                        ? '9:16 · 1080×1920 · plays muted, like a real TikTok. Keep key content clear of the caption and the right-hand buttons.'
                        : photos.length > 1
                          ? `9:16 · photo mode · ${photos.length} photos auto-advance; swipe to step through them.`
                          : '9:16 · 1080×1920 · keep key content clear of the caption and the right-hand buttons.'}
                </p>
            </div>
        </div>
    );
}

/**
 * The video stage: autoplay + loop + muted, tap anywhere to pause, with a mute
 * toggle and a scrub bar. Muted autoplay is the one form browsers allow without
 * a gesture, and it is also what TikTok itself does.
 */
function TikTokVideoStage({ media }: { media: MediaView }) {
    const videoRef = useRef<HTMLVideoElement | null>(null);
    const [playing, setPlaying] = useState(true);
    const [muted, setMuted] = useState(true);
    const [progress, setProgress] = useState(0);

    // Drive muted through the DOM property rather than the React attribute, which
    // applies unreliably — the same reason VideoEditor does it this way. Re-runs
    // on src change so a newly mounted element always starts muted (an unmuted
    // autoplay would be blocked outright).
    useEffect(() => {
        if (videoRef.current) {
            videoRef.current.muted = muted;
        }
    }, [muted, media.url]);

    // A fresh clip starts over rather than inheriting the previous one's state.
    useEffect(() => {
        setPlaying(true);
        setMuted(true);
        setProgress(0);
    }, [media.url]);

    const toggle = useCallback(() => {
        const el = videoRef.current;
        if (!el) {
            return;
        }

        if (el.paused) {
            void el.play();
        } else {
            el.pause();
        }
    }, []);

    return (
        <>
            <video
                ref={videoRef}
                src={media.url}
                className="absolute inset-0 size-full object-cover"
                autoPlay
                loop
                muted
                playsInline
                preload="metadata"
                onPlay={() => setPlaying(true)}
                onPause={() => setPlaying(false)}
                onTimeUpdate={(event) => {
                    const el = event.currentTarget;
                    if (Number.isFinite(el.duration) && el.duration > 0) {
                        setProgress((el.currentTime / el.duration) * 100);
                    }
                }}
            />

            {/* The whole frame is the play/pause target, as on TikTok. */}
            <button
                type="button"
                onClick={toggle}
                aria-label={playing ? 'Pause preview' : 'Play preview'}
                className="absolute inset-0 grid place-items-center focus-visible:ring-2 focus-visible:ring-white/70 focus-visible:outline-none"
            >
                {/* Only the paused affordance shows; a persistent overlay would
                    hide the very content being previewed. */}
                {!playing && (
                    <span className="grid size-12 place-items-center rounded-full bg-black/45 backdrop-blur-sm">
                        <Play className="size-6 translate-x-0.5 fill-white text-white" />
                    </span>
                )}
            </button>

            <button
                type="button"
                onClick={() => setMuted((m) => !m)}
                aria-label={muted ? 'Unmute preview' : 'Mute preview'}
                className="absolute top-11 right-2.5 z-10 grid size-7 place-items-center rounded-full bg-black/45 text-white backdrop-blur-sm transition-colors hover:bg-black/65 focus-visible:ring-2 focus-visible:ring-white/70 focus-visible:outline-none"
            >
                {muted ? (
                    <VolumeX className="size-3.5" />
                ) : (
                    <Volume2 className="size-3.5" />
                )}
            </button>

            {/* Playback progress, where TikTok puts it: hard against the bottom. */}
            <div className="absolute inset-x-0 bottom-0 z-10 h-0.5 bg-white/25">
                <div
                    className="h-full bg-white/90"
                    style={{ width: `${progress}%` }}
                />
            </div>

            <PausedBadge playing={playing} />
        </>
    );
}

/** A small hint that the preview is stopped, so a paused frame isn't mistaken for a still image. */
function PausedBadge({ playing }: { playing: boolean }) {
    if (playing) {
        return null;
    }

    return (
        <span className="absolute top-11 left-2.5 z-10 flex items-center gap-1 rounded-full bg-black/45 px-2 py-1 text-[10px] font-medium backdrop-blur-sm">
            <Pause className="size-2.5" />
            Paused
        </span>
    );
}

/**
 * The photo-mode stage: TikTok cycles a photo post's images automatically and
 * loops, and lets the viewer swipe between them. Both are reproduced, so the
 * preview shows the real pacing rather than a static first frame.
 */
function TikTokPhotoStage({ photos }: { photos: MediaView[] }) {
    const [index, setIndex] = useState(0);
    const touchStartX = useRef<number | null>(null);

    const count = photos.length;

    // Clamp when photos are removed from the draft while the carousel is open.
    useEffect(() => {
        setIndex((current) => (current >= count ? 0 : current));
    }, [count]);

    const go = useCallback(
        (next: number) => {
            setIndex(((next % count) + count) % count);
        },
        [count],
    );

    useEffect(() => {
        if (count < 2) {
            return;
        }

        // Honour the OS "reduce motion" setting: auto-advance is exactly the kind
        // of unrequested movement it exists to stop. The carousel stays fully
        // usable by swipe and keyboard.
        const reduced = window.matchMedia(
            '(prefers-reduced-motion: reduce)',
        ).matches;

        if (reduced) {
            return;
        }

        const timer = window.setTimeout(() => {
            setIndex((current) => (current + 1) % count);
        }, PHOTO_ADVANCE_MS);

        // Keyed on `index`, so a manual swipe restarts the dwell rather than
        // advancing again a moment later.
        return () => window.clearTimeout(timer);
    }, [index, count]);

    const current = photos[index];

    return (
        <>
            {photos.map((photo, i) => (
                <img
                    key={photo.id}
                    src={photo.url}
                    alt={photo.alt_text ?? ''}
                    aria-hidden={i !== index}
                    className={cn(
                        'absolute inset-0 size-full object-cover transition-opacity duration-500',
                        i === index ? 'opacity-100' : 'opacity-0',
                    )}
                />
            ))}

            {count > 1 && (
                <>
                    {/* Swipe surface. Keyboard users get the arrow buttons below. */}
                    <div
                        className="absolute inset-0"
                        onTouchStart={(event) => {
                            touchStartX.current =
                                event.touches[0]?.clientX ?? null;
                        }}
                        onTouchEnd={(event) => {
                            const start = touchStartX.current;
                            const end = event.changedTouches[0]?.clientX;
                            touchStartX.current = null;

                            if (start === null || end === undefined) {
                                return;
                            }

                            const delta = end - start;

                            if (Math.abs(delta) < SWIPE_THRESHOLD_PX) {
                                return;
                            }

                            go(delta < 0 ? index + 1 : index - 1);
                        }}
                    />

                    <CarouselArrow
                        direction="prev"
                        onClick={() => go(index - 1)}
                    />
                    <CarouselArrow
                        direction="next"
                        onClick={() => go(index + 1)}
                    />

                    {/* Pagination dots sit above the caption block, as on TikTok. */}
                    <div
                        className="absolute inset-x-0 bottom-[86px] z-10 flex justify-center gap-1"
                        role="group"
                        aria-label={`Photo ${index + 1} of ${count}`}
                    >
                        {photos.map((photo, i) => (
                            <button
                                key={photo.id}
                                type="button"
                                onClick={() => go(i)}
                                aria-label={`Show photo ${i + 1}`}
                                aria-current={i === index}
                                className={cn(
                                    'h-1 rounded-full transition-all',
                                    i === index
                                        ? 'w-4 bg-white'
                                        : 'w-1 bg-white/50 hover:bg-white/80',
                                )}
                            />
                        ))}
                    </div>
                </>
            )}

            {current?.alt_text && (
                <span className="sr-only">{current.alt_text}</span>
            )}
        </>
    );
}

function CarouselArrow({
    direction,
    onClick,
}: {
    direction: 'prev' | 'next';
    onClick: () => void;
}) {
    return (
        <button
            type="button"
            onClick={onClick}
            aria-label={direction === 'prev' ? 'Previous photo' : 'Next photo'}
            className={cn(
                'absolute top-1/2 z-10 grid size-7 -translate-y-1/2 place-items-center rounded-full bg-black/40 text-[16px] leading-none text-white opacity-0 backdrop-blur-sm transition-opacity group-hover:opacity-100 hover:bg-black/60 focus-visible:opacity-100 focus-visible:ring-2 focus-visible:ring-white/70 focus-visible:outline-none',
                direction === 'prev' ? 'left-1.5' : 'right-1.5',
            )}
        >
            {direction === 'prev' ? '‹' : '›'}
        </button>
    );
}

function EmptyStage() {
    return (
        <div className="absolute inset-0 grid place-items-center bg-gradient-to-b from-neutral-800 to-black text-center">
            <div className="space-y-1.5 px-4 text-white/70">
                <ImageIcon className="mx-auto size-6" />
                <p className="text-[12px] leading-4">
                    Add a video or photos to preview your TikTok
                </p>
            </div>
        </div>
    );
}

/** TikTok's feed tabs. Purely decorative context — the post is never "Following". */
function FeedHeader() {
    return (
        <div className="pointer-events-none absolute inset-x-0 top-0 z-0 flex justify-center gap-4 bg-gradient-to-b from-black/40 to-transparent pt-2.5 pb-6 text-[11px] font-medium">
            <span className="text-white/60">Following</span>
            <span className="border-b-2 border-white pb-0.5 text-white">
                For You
            </span>
        </div>
    );
}

/**
 * The right-hand action rail and the caption block. Counts are hard zeros: this
 * post has no engagement because it does not exist yet.
 */
function TikTokChrome({
    preview,
    caption,
    photoCount,
}: {
    preview: PlatformPreview;
    caption: string;
    photoCount: number;
}) {
    const handle = preview.accountHandle || preview.accountName;

    return (
        <>
            <div className="pointer-events-none absolute right-2 bottom-16 z-10 flex flex-col items-center gap-3.5">
                <div className="relative mb-1">
                    <Avatar className="size-8 ring-1 ring-white">
                        <AvatarImage src={preview.avatarUrl ?? undefined} />
                        <AvatarFallback className="text-[9px] font-semibold text-foreground">
                            {initials(preview.accountName)}
                        </AvatarFallback>
                    </Avatar>
                    <span className="absolute -bottom-1.5 left-1/2 grid size-3.5 -translate-x-1/2 place-items-center rounded-full bg-[#FE2C55]">
                        <Plus className="size-2.5 text-white" strokeWidth={3} />
                    </span>
                </div>

                <RailAction icon={<Heart className="size-6 fill-white" />} />
                <RailAction
                    icon={<MessageCircle className="size-6 fill-white" />}
                />
                <RailAction icon={<Bookmark className="size-6 fill-white" />} />
                <RailAction icon={<Share2 className="size-6 fill-white" />} />

                {/* The spinning record TikTok shows for the post's sound. */}
                <span className="mt-0.5 grid size-7 animate-spin place-items-center rounded-full bg-gradient-to-br from-neutral-700 to-black ring-1 ring-white/30 [animation-duration:3s] motion-reduce:animate-none">
                    <Music2 className="size-3 text-white" />
                </span>
            </div>

            <div className="pointer-events-none absolute inset-x-0 bottom-0 z-0 bg-gradient-to-t from-black/70 via-black/30 to-transparent px-3 pt-10 pb-3">
                <div className="pr-11">
                    <p className="text-[12px] font-semibold drop-shadow">
                        {handle}
                    </p>

                    {caption ? (
                        <p className="mt-1 line-clamp-2 text-[11px] leading-snug text-white/90 drop-shadow">
                            {caption}
                        </p>
                    ) : (
                        <p className="mt-1 text-[11px] leading-snug text-white/50 italic drop-shadow">
                            Your caption appears here
                        </p>
                    )}

                    <p className="mt-1.5 flex items-center gap-1 text-[10px] text-white/85 drop-shadow">
                        <Music2 className="size-2.5 shrink-0" />
                        <span className="truncate">
                            original sound - {handle}
                        </span>
                    </p>

                    {photoCount > 1 && (
                        <p className="mt-1 text-[10px] text-white/60">
                            {photoCount} photos
                        </p>
                    )}
                </div>
            </div>
        </>
    );
}

function RailAction({ icon }: { icon: React.ReactNode }) {
    return (
        <span className="flex flex-col items-center gap-0.5 drop-shadow">
            {icon}
            <span className="text-[10px] font-semibold">0</span>
        </span>
    );
}

function initials(name: string): string {
    return name
        .split(' ')
        .map((part) => part.charAt(0))
        .join('')
        .slice(0, 2)
        .toUpperCase();
}
