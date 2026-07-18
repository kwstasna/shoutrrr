import { Inbox, Send } from 'lucide-react';

import type { TikTokPostMode } from '@/lib/compose/tiktok';
import { cn } from '@/lib/utils';

type Props = {
    value: TikTokPostMode;
    onChange: (mode: TikTokPostMode) => void;
    disabled?: boolean;
};

const OPTIONS: {
    value: TikTokPostMode;
    label: string;
    icon: typeof Send;
}[] = [
    { value: 'direct_post', label: 'Direct post', icon: Send },
    { value: 'inbox_draft', label: 'Draft', icon: Inbox },
];

/**
 * Direct post / Draft switch shown under the account tabs for a TikTok
 * destination.
 *
 * The two modes are genuinely different products, not a formatting nicety: a
 * direct post goes live at the scheduled time and therefore carries TikTok's
 * whole compliance payload (visibility, interaction and disclosure settings),
 * while a draft lands in the creator's TikTok inbox for them to finish by hand.
 * That is why the options panel below collapses to almost nothing in draft mode.
 */
export function TikTokPostModeToggle({ value, onChange, disabled }: Props) {
    return (
        <div
            role="radiogroup"
            aria-label="TikTok post mode"
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
