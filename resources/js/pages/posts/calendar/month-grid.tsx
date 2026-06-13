import { useDroppable } from '@dnd-kit/core';

import { useSchedulingTimezone } from '@/hooks/use-scheduling-timezone';
import { dayjs, monthRange, toUserTz } from '@/lib/datetime/dayjs';
import type { Dayjs } from '@/lib/datetime/dayjs';
import { cn } from '@/lib/utils';
import type { PostRowData } from '@/pages/posts/post-row';

import { PostChip } from './post-chip';

/** Month drop: keep H:M of the original schedule, swap the date. Returns ISO (UTC, Z). */
export function computeMonthDrop(
    scheduledIso: string,
    dropDay: string,
    tz: string,
): string {
    const orig = toUserTz(scheduledIso, tz);
    return dayjs
        .tz(dropDay, 'YYYY-MM-DD', tz)
        .hour(orig.hour())
        .minute(orig.minute())
        .second(0)
        .millisecond(0)
        .utc()
        .format('YYYY-MM-DDTHH:mm:ss[Z]');
}

const WEEKDAYS = ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'];

export function MonthGrid({
    anchor,
    posts,
    onEmptyDayClick,
}: {
    anchor: Dayjs;
    posts: PostRowData[];
    onEmptyDayClick: (day: Dayjs) => void;
}) {
    const tz = useSchedulingTimezone();
    const today = dayjs().tz(tz).startOf('day');
    const { days } = monthRange(anchor);

    const byDay = new Map<string, PostRowData[]>();
    for (const p of posts) {
        if (!p.scheduled_at && !p.published_at) continue;
        const at = p.scheduled_at ?? p.published_at!;
        const key = toUserTz(at, tz).format('YYYY-MM-DD');
        if (!byDay.has(key)) byDay.set(key, []);
        byDay.get(key)!.push(p);
    }

    return (
        <div className="px-3 py-3">
            <div className="mb-1 grid grid-cols-7 text-[10px] font-semibold tracking-[0.08em] text-muted-foreground uppercase">
                {WEEKDAYS.map((w) => (
                    <div key={w} className="px-2 pb-1.5 text-center">
                        {w}
                    </div>
                ))}
            </div>
            <div className="grid grid-cols-7 gap-px overflow-hidden rounded-lg border border-border bg-border">
                {days.map((d) => (
                    <DayCell
                        key={d.format('YYYY-MM-DD')}
                        day={d}
                        inMonth={d.month() === anchor.month()}
                        isToday={d.isSame(today, 'day')}
                        isPast={d.isBefore(today, 'day')}
                        posts={byDay.get(d.format('YYYY-MM-DD')) ?? []}
                        onEmptyClick={() => onEmptyDayClick(d)}
                    />
                ))}
            </div>
        </div>
    );
}

function DayCell({
    day,
    inMonth,
    isToday,
    isPast,
    posts,
    onEmptyClick,
}: {
    day: Dayjs;
    inMonth: boolean;
    isToday: boolean;
    isPast: boolean;
    posts: PostRowData[];
    onEmptyClick: () => void;
}) {
    const { setNodeRef, isOver } = useDroppable({
        id: `day-${day.format('YYYY-MM-DD')}`,
        data: { day: day.format('YYYY-MM-DD') },
        disabled: isPast,
    });
    const visible = posts.slice(0, 3);
    const overflow = posts.length - visible.length;
    const empty = posts.length === 0;
    const dimmed = !inMonth || isPast;

    const handleActivate = () => {
        if (empty && !isPast) onEmptyClick();
    };

    return (
        <div
            ref={setNodeRef}
            // oxlint-disable-next-line prefer-tag-over-role -- droppable container needs the div ref from useDroppable
            role="button"
            tabIndex={isPast ? -1 : 0}
            aria-label={`Day ${day.format('YYYY-MM-DD')}`}
            onClick={(e) => {
                if (empty && !(e.target as HTMLElement).closest('button'))
                    onEmptyClick();
            }}
            onKeyDown={(e) => {
                if (e.key === 'Enter' || e.key === ' ') {
                    e.preventDefault();
                    handleActivate();
                }
            }}
            className={cn(
                'group/day relative min-h-[96px] bg-background p-1.5 transition-colors',
                'focus-visible:z-10 focus-visible:ring-2 focus-visible:ring-ring/50 focus-visible:outline-none',
                isPast && 'bg-muted/40',
                empty && !isPast && 'cursor-pointer hover:bg-accent/40',
                isOver && 'ring-2 ring-primary/60 ring-inset',
            )}
        >
            <div className={cn(dimmed && 'opacity-50')}>
                <div className="mb-1 flex items-center justify-between">
                    <span
                        className={cn(
                            'inline-flex h-[18px] min-w-[18px] items-center justify-center px-1 text-[11px] leading-none font-medium tabular-nums',
                            isToday
                                ? 'rounded-full bg-primary text-primary-foreground'
                                : 'text-foreground/85',
                        )}
                    >
                        {day.date()}
                    </span>
                    {empty && !isPast && (
                        <span
                            aria-hidden
                            className="text-[14px] leading-none text-muted-foreground opacity-0 transition-opacity group-hover/day:opacity-60"
                        >
                            +
                        </span>
                    )}
                </div>
                <div className="space-y-0.5">
                    {visible.map((p) => (
                        <PostChip
                            key={p.id}
                            post={p}
                            draggable={!isPast && p.status === 'scheduled'}
                        />
                    ))}
                    {overflow > 0 && (
                        <div className="px-1.5 text-[10px] font-medium tracking-wider text-muted-foreground uppercase tabular-nums">
                            +{overflow} more
                        </div>
                    )}
                </div>
            </div>
        </div>
    );
}
