import { Deferred, Head, Link, router } from '@inertiajs/react';
import { CalendarClock, Plus } from 'lucide-react';
import { useState } from 'react';
import { toast } from 'sonner';

import PostingScheduleController from '@/actions/App/Http/Controllers/Posts/PostingScheduleController';
import WorkspaceSettingsController from '@/actions/App/Http/Controllers/Settings/WorkspaceSettingsController';
import { QueueSkeleton } from '@/components/skeletons/queue-skeleton';
import { Button } from '@/components/ui/button';
import {
    Popover,
    PopoverContent,
    PopoverTrigger,
} from '@/components/ui/popover';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { dayjs, type Dayjs } from '@/lib/datetime/dayjs';
import {
    addSlot,
    copyMondayToWeekdays,
    DISPLAY_DAYS,
    formatTime,
    mergeSlots,
    normalizeSlots,
    PRESETS,
    removeSlot,
    type Slot,
    slotsEqual,
    timesForDay,
} from '@/lib/queue/queue-schedule';
import { cn } from '@/lib/utils';

type Props = {
    timezone: string;
    slots?: Slot[];
    canManage: boolean;
};

export default function QueueIndex({ timezone, slots, canManage }: Props) {
    return (
        <>
            <Head title="Queue" />

            <div className="mx-auto w-full max-w-6xl space-y-5 px-4 pt-6 pb-16 sm:px-6">
                {/* Header — slots-independent, paints immediately */}
                <div className="flex flex-col gap-1">
                    <h1 className="text-[22px] leading-tight font-semibold tracking-tight">
                        Posting queue
                    </h1>
                    <p className="text-[13px] text-muted-foreground">
                        Queued posts go out at these times each week, in{' '}
                        <span className="font-medium text-foreground">
                            {timezone}
                        </span>{' '}
                        ·{' '}
                        <Link
                            href={
                                WorkspaceSettingsController.showOverview().url
                            }
                            className="font-medium text-foreground underline underline-offset-2 hover:no-underline"
                        >
                            change
                        </Link>
                    </p>
                </div>

                <Deferred data="slots" fallback={<QueueSkeleton />}>
                    <ScheduleEditor
                        key={normalizeSlots(slots ?? [])
                            .map((s) => `${s.weekday}:${s.hour}:${s.minute}`)
                            .join(',')}
                        initialSlots={normalizeSlots(slots ?? [])}
                        timezone={timezone}
                        canManage={canManage}
                    />
                </Deferred>
            </div>
        </>
    );
}

/** Soonest future slot occurrence from `now`, scanning the next week. */
function nextOccurrence(slots: Slot[], now: Dayjs): Dayjs | null {
    let best: Dayjs | null = null;
    for (const slot of slots) {
        for (let offset = 0; offset <= 7; offset += 1) {
            const day = now.add(offset, 'day');
            if (day.day() !== slot.weekday) {
                continue;
            }
            const candidate = day
                .hour(slot.hour)
                .minute(slot.minute)
                .second(0)
                .millisecond(0);
            if (
                candidate.isAfter(now) &&
                (best === null || candidate.isBefore(best))
            ) {
                best = candidate;
            }
        }
    }

    return best;
}

function nextLabel(next: Dayjs | null, now: Dayjs): string {
    if (!next) {
        return '—';
    }
    const days = next.startOf('day').diff(now.startOf('day'), 'day');
    const time = next.format('h:mm A');
    if (days === 0) {
        return `Today · ${time}`;
    }
    if (days === 1) {
        return `Tomorrow · ${time}`;
    }

    return next.format('ddd · h:mm A');
}

/** Bar height bucket for the cadence equalizer (count relative to the busiest day). */
function barClass(count: number, max: number): string {
    if (count === 0) {
        return 'h-1 bg-border';
    }
    const ratio = max > 0 ? count / max : 0;
    if (ratio <= 0.25) {
        return 'h-3 bg-primary/50';
    }
    if (ratio <= 0.5) {
        return 'h-5 bg-primary/65';
    }
    if (ratio <= 0.75) {
        return 'h-7 bg-primary/80';
    }

    return 'h-10 bg-primary';
}

function ScheduleEditor({
    initialSlots,
    timezone,
    canManage,
}: {
    initialSlots: Slot[];
    timezone: string;
    canManage: boolean;
}) {
    const [slots, setSlots] = useState<Slot[]>(initialSlots);
    const [saving, setSaving] = useState(false);

    const dirty = !slotsEqual(slots, initialSlots);
    const activeDays = new Set(slots.map((s) => s.weekday)).size;

    const now = dayjs().tz(timezone);
    const todayWeekday = now.day();
    const next = slots.length > 0 ? nextOccurrence(slots, now) : null;
    const busiest = DISPLAY_DAYS.reduce(
        (m, d) => Math.max(m, timesForDay(slots, d.weekday).length),
        0,
    );

    function onSave() {
        setSaving(true);
        router.put(
            PostingScheduleController.update().url,
            { slots: normalizeSlots(slots) },
            {
                preserveScroll: true,
                onSuccess: () => toast.success('Queue saved.'),
                onError: () => toast.error('Could not save the queue.'),
                onFinish: () => setSaving(false),
            },
        );
    }

    return (
        <div className="space-y-5">
            {/* Cadence overview — the week's rhythm at a glance + next post */}
            <section className="flex flex-col gap-5 rounded-xl border border-border bg-card p-4 sm:flex-row sm:items-center sm:justify-between sm:gap-8 sm:p-5">
                <div className="flex items-end gap-2">
                    {DISPLAY_DAYS.map(({ weekday, label }) => {
                        const count = timesForDay(slots, weekday).length;
                        const isToday = weekday === todayWeekday;

                        return (
                            <div
                                key={weekday}
                                className="flex flex-1 flex-col items-center gap-1.5"
                            >
                                <span className="text-[11px] text-muted-foreground tabular-nums">
                                    {count > 0 ? count : ''}
                                </span>
                                <div className="flex h-10 w-full items-end justify-center">
                                    <div
                                        className={cn(
                                            'w-2.5 rounded-full transition-all',
                                            barClass(count, busiest),
                                        )}
                                    />
                                </div>
                                <span
                                    className={cn(
                                        'text-[11px] tabular-nums',
                                        isToday
                                            ? 'font-semibold text-primary'
                                            : 'text-muted-foreground',
                                    )}
                                >
                                    {label[0]}
                                </span>
                            </div>
                        );
                    })}
                </div>

                <div className="flex items-center gap-6 sm:gap-8">
                    <div className="flex flex-col">
                        <span className="text-[22px] leading-none font-semibold tabular-nums">
                            {slots.length}
                        </span>
                        <span className="mt-1 text-[11.5px] text-muted-foreground">
                            posts / week
                        </span>
                    </div>
                    <div className="flex flex-col">
                        <span className="text-[22px] leading-none font-semibold tabular-nums">
                            {activeDays}
                        </span>
                        <span className="mt-1 text-[11.5px] text-muted-foreground">
                            {activeDays === 1 ? 'active day' : 'active days'}
                        </span>
                    </div>
                    <div className="flex min-w-0 flex-col">
                        <span className="flex items-center gap-1.5 text-[15px] leading-none font-semibold">
                            <CalendarClock className="size-4 shrink-0 text-primary" />
                            <span className="truncate tabular-nums">
                                {nextLabel(next, now)}
                            </span>
                        </span>
                        <span className="mt-1 text-[11.5px] text-muted-foreground">
                            next post
                        </span>
                    </div>
                </div>
            </section>

            {canManage && (
                <div className="flex flex-wrap items-center gap-2">
                    <span className="mr-1 text-[12px] font-medium text-muted-foreground">
                        Quick add
                    </span>
                    {PRESETS.map((preset) => (
                        <Button
                            key={preset.label}
                            type="button"
                            variant="outline"
                            size="sm"
                            className="h-7 rounded-full text-[12px]"
                            onClick={() =>
                                setSlots((s) => mergeSlots(s, preset.slots))
                            }
                        >
                            {preset.label}
                        </Button>
                    ))}
                    {timesForDay(slots, 1).length > 0 && (
                        <Button
                            type="button"
                            variant="ghost"
                            size="sm"
                            className="h-7 text-[12px] text-muted-foreground"
                            onClick={() =>
                                setSlots((s) => copyMondayToWeekdays(s))
                            }
                        >
                            Copy Monday → weekdays
                        </Button>
                    )}
                </div>
            )}

            {/* Week board */}
            <div className="grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-4 xl:grid-cols-7">
                {DISPLAY_DAYS.map(({ weekday, label }) => {
                    const times = timesForDay(slots, weekday);
                    const isToday = weekday === todayWeekday;

                    return (
                        <div
                            key={weekday}
                            className={cn(
                                'flex flex-col gap-2 rounded-xl border bg-card p-3 transition-colors',
                                times.length === 0
                                    ? 'border-dashed border-border'
                                    : 'border-border',
                                isToday && 'ring-1 ring-primary/40',
                            )}
                        >
                            <div className="flex items-center justify-between">
                                <span
                                    className={cn(
                                        'text-[12.5px] font-semibold',
                                        isToday
                                            ? 'text-primary'
                                            : 'text-foreground',
                                    )}
                                >
                                    {label}
                                </span>
                                {times.length > 0 && (
                                    <span className="text-[11px] text-muted-foreground tabular-nums">
                                        {times.length}
                                    </span>
                                )}
                            </div>

                            <div className="flex flex-col gap-1.5">
                                {times.length === 0 && (
                                    <span className="py-1 text-[12px] text-muted-foreground/70">
                                        No times
                                    </span>
                                )}
                                {times.map(({ hour, minute }) => (
                                    <span
                                        key={`${hour}:${minute}`}
                                        className="group/slot inline-flex items-center justify-between rounded-md bg-primary/10 py-1 pr-1 pl-2.5 text-[12.5px] font-medium text-foreground tabular-nums"
                                    >
                                        {formatTime(hour, minute)}
                                        {canManage && (
                                            <button
                                                type="button"
                                                aria-label={`Remove ${label} ${formatTime(hour, minute)}`}
                                                onClick={() =>
                                                    setSlots((s) =>
                                                        removeSlot(
                                                            s,
                                                            weekday,
                                                            hour,
                                                            minute,
                                                        ),
                                                    )
                                                }
                                                className="grid size-5 place-items-center rounded text-muted-foreground transition-colors hover:bg-primary/20 hover:text-foreground"
                                            >
                                                ×
                                            </button>
                                        )}
                                    </span>
                                ))}
                                {canManage && (
                                    <AddTime
                                        onAdd={(hour, minute) =>
                                            setSlots((s) =>
                                                addSlot(
                                                    s,
                                                    weekday,
                                                    hour,
                                                    minute,
                                                ),
                                            )
                                        }
                                    />
                                )}
                            </div>
                        </div>
                    );
                })}
            </div>

            {/* Sticky save bar — only when there are unsaved edits */}
            {canManage && dirty && (
                <div className="sticky bottom-4 z-10 flex items-center justify-between gap-3 rounded-xl border border-border bg-card/95 px-4 py-2.5 shadow-lg backdrop-blur">
                    <span className="text-[12.5px] text-muted-foreground">
                        Unsaved changes
                    </span>
                    <div className="flex items-center gap-2">
                        <Button
                            type="button"
                            variant="ghost"
                            size="sm"
                            className="h-8 text-[12.5px]"
                            disabled={saving}
                            onClick={() => setSlots(initialSlots)}
                        >
                            Discard
                        </Button>
                        <Button
                            size="sm"
                            className="h-8 px-4 text-[12.5px]"
                            disabled={saving}
                            onClick={onSave}
                        >
                            {saving ? 'Saving…' : 'Save changes'}
                        </Button>
                    </div>
                </div>
            )}
        </div>
    );
}

const MINUTES = Array.from({ length: 60 }, (_, m) => m);

function AddTime({ onAdd }: { onAdd: (hour: number, minute: number) => void }) {
    const [open, setOpen] = useState(false);
    const [hour12, setHour12] = useState(9);
    const [minute, setMinute] = useState(0);
    const [meridiem, setMeridiem] = useState<'AM' | 'PM'>('AM');

    function to24(): number {
        if (meridiem === 'AM') {
            return hour12 === 12 ? 0 : hour12;
        }

        return hour12 === 12 ? 12 : hour12 + 12;
    }

    function add() {
        onAdd(to24(), minute);
        setOpen(false);
    }

    return (
        <Popover open={open} onOpenChange={setOpen}>
            <PopoverTrigger asChild>
                <button
                    type="button"
                    className="inline-flex items-center justify-center gap-1 rounded-md border border-dashed border-border py-1 text-[12px] text-muted-foreground transition-colors hover:border-primary/40 hover:bg-primary/5 hover:text-foreground"
                >
                    <Plus className="size-3.5" />
                    Add time
                </button>
            </PopoverTrigger>
            <PopoverContent align="start" className="w-auto p-3">
                <div className="flex items-center gap-1.5">
                    <Select
                        value={String(hour12)}
                        onValueChange={(v) => setHour12(Number(v))}
                    >
                        <SelectTrigger
                            size="sm"
                            className="h-7 w-14 font-mono text-[12px] tabular-nums"
                        >
                            <SelectValue />
                        </SelectTrigger>
                        <SelectContent>
                            {Array.from({ length: 12 }, (_, i) => i + 1).map(
                                (h) => (
                                    <SelectItem key={h} value={String(h)}>
                                        {String(h).padStart(2, '0')}
                                    </SelectItem>
                                ),
                            )}
                        </SelectContent>
                    </Select>
                    <span className="text-muted-foreground/60">:</span>
                    <Select
                        value={String(minute)}
                        onValueChange={(v) => setMinute(Number(v))}
                    >
                        <SelectTrigger
                            size="sm"
                            className="h-7 w-14 font-mono text-[12px] tabular-nums"
                        >
                            <SelectValue />
                        </SelectTrigger>
                        <SelectContent className="max-h-60">
                            {MINUTES.map((m) => (
                                <SelectItem key={m} value={String(m)}>
                                    {String(m).padStart(2, '0')}
                                </SelectItem>
                            ))}
                        </SelectContent>
                    </Select>
                    <div className="ml-1 inline-flex h-7 overflow-hidden rounded-md border border-border">
                        {(['AM', 'PM'] as const).map((m) => (
                            <button
                                key={m}
                                type="button"
                                aria-pressed={m === meridiem}
                                onClick={() => setMeridiem(m)}
                                className={
                                    m === meridiem
                                        ? 'inline-flex w-8 items-center justify-center bg-foreground text-[11px] font-medium text-background'
                                        : 'inline-flex w-8 items-center justify-center text-[11px] font-medium text-muted-foreground hover:bg-muted'
                                }
                            >
                                {m}
                            </button>
                        ))}
                    </div>
                    <Button
                        size="sm"
                        className="ml-1 h-7 text-[12px]"
                        onClick={add}
                    >
                        Add
                    </Button>
                </div>
            </PopoverContent>
        </Popover>
    );
}

QueueIndex.layout = {
    breadcrumbs: [
        {
            title: 'Queue',
            href: PostingScheduleController.show().url,
        },
    ],
};
