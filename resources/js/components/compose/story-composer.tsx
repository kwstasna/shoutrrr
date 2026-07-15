import { ImagePlus, Trash2 } from 'lucide-react';
import { useRef } from 'react';

import { Button } from '@/components/ui/button';
import type { MediaView } from '@/types/compose';

type Props = {
    media: MediaView[];
    readOnly?: boolean;
    onAddFiles: (files: FileList) => void;
    onRemove: (mediaId: string) => void;
};

/**
 * The Instagram Story composer body, shown in place of the text editor when a
 * story is being composed. A story carries a single 9:16 photo or video and no
 * caption, so instead of a "write something" box we show the media itself (or an
 * upload dropzone) — the user always sees exactly what will publish.
 */
export function StoryComposer({
    media,
    readOnly = false,
    onAddFiles,
    onRemove,
}: Props) {
    const inputRef = useRef<HTMLInputElement>(null);
    const item = media[0] ?? null;

    function pick() {
        inputRef.current?.click();
    }

    return (
        <div className="px-4 pb-3.5 sm:px-[26px]">
            <div className="mx-auto w-full max-w-[220px]">
                <div className="relative aspect-[9/16] overflow-hidden rounded-2xl border border-border bg-neutral-900">
                    {item ? (
                        item.kind === 'video' ? (
                            <video
                                src={item.url}
                                className="absolute inset-0 size-full object-cover"
                                muted
                                playsInline
                            />
                        ) : (
                            <img
                                src={item.url}
                                alt={item.alt_text ?? ''}
                                className="absolute inset-0 size-full object-cover"
                            />
                        )
                    ) : (
                        <button
                            type="button"
                            onClick={pick}
                            disabled={readOnly}
                            className="absolute inset-0 grid place-items-center gap-2 bg-gradient-to-b from-neutral-700 to-neutral-900 text-center text-white/70 transition-colors hover:text-white focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none disabled:cursor-not-allowed"
                        >
                            <span className="space-y-1.5">
                                <ImagePlus
                                    className="mx-auto size-7"
                                    aria-hidden
                                />
                                <span className="block px-4 text-[12px] leading-4">
                                    Upload a photo or video
                                </span>
                                <span className="block text-[11px] text-white/50">
                                    9:16 · 1080×1920
                                </span>
                            </span>
                        </button>
                    )}

                    {item && !readOnly && (
                        <button
                            type="button"
                            onClick={() => onRemove(item.id)}
                            aria-label="Remove media"
                            className="absolute top-2 right-2 grid size-7 place-items-center rounded-full bg-black/55 text-white transition-colors hover:bg-black/75"
                        >
                            <Trash2 className="size-3.5" aria-hidden />
                        </button>
                    )}
                </div>

                {!readOnly && (
                    <div className="mt-2.5 flex justify-center">
                        <Button
                            type="button"
                            variant="outline"
                            size="sm"
                            onClick={pick}
                        >
                            <ImagePlus className="size-3.5" aria-hidden />
                            {item ? 'Replace media' : 'Upload media'}
                        </Button>
                    </div>
                )}

                <p className="mt-2 text-center text-[12px] leading-5 text-muted-foreground">
                    A story is a single 9:16 photo or video. Captions
                    aren&apos;t shown on stories.
                </p>
            </div>

            <input
                ref={inputRef}
                type="file"
                accept="image/*,video/*"
                hidden
                onChange={(event) => {
                    if (event.target.files && event.target.files.length > 0) {
                        onAddFiles(event.target.files);
                    }
                    event.target.value = '';
                }}
            />
        </div>
    );
}
