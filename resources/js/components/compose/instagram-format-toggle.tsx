import { Images, Square } from 'lucide-react';

import { cn } from '@/lib/utils';

export type InstagramFormat = 'feed' | 'story';

type Props = {
    value: InstagramFormat;
    onChange: (format: InstagramFormat) => void;
    disabled?: boolean;
};

const OPTIONS: {
    value: InstagramFormat;
    label: string;
    icon: typeof Square;
}[] = [
    { value: 'feed', label: 'Post', icon: Square },
    { value: 'story', label: 'Story', icon: Images },
];

/**
 * Prominent Post/Story switch shown under the account tabs for an Instagram
 * destination. Stories publish a single 9:16 photo/video with no caption, so
 * this drives the composer between the text editor and the story media picker.
 */
export function InstagramFormatToggle({ value, onChange, disabled }: Props) {
    return (
        <div
            role="radiogroup"
            aria-label="Instagram format"
            className="inline-flex items-center gap-1 rounded-lg border border-border bg-muted/40 p-0.5"
        >
            {OPTIONS.map((option) => {
                const active = value === option.value;
                const Icon = option.icon;

                return (
                    <button
                        key={option.value}
                        type="button"
                        role="radio"
                        aria-checked={active}
                        disabled={disabled}
                        onClick={() => onChange(option.value)}
                        className={cn(
                            'inline-flex items-center gap-1.5 rounded-md px-3 py-1 text-[12.5px] font-medium tracking-[-0.005em] transition-colors disabled:opacity-50',
                            active
                                ? 'bg-background text-foreground shadow-sm ring-1 ring-border'
                                : 'text-muted-foreground hover:text-foreground',
                        )}
                    >
                        <Icon className="size-3.5 shrink-0" aria-hidden />
                        {option.label}
                    </button>
                );
            })}
        </div>
    );
}
