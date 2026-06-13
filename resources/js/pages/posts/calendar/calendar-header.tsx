import {
    Calendar as CalendarIcon,
    ChevronLeft,
    ChevronRight,
} from 'lucide-react';
import { useState } from 'react';

import { Button } from '@/components/ui/button';
import { Calendar } from '@/components/ui/calendar';
import {
    Popover,
    PopoverContent,
    PopoverTrigger,
} from '@/components/ui/popover';
import { ToggleGroup, ToggleGroupItem } from '@/components/ui/toggle-group';
import { useSchedulingTimezone } from '@/hooks/use-scheduling-timezone';
import { dayjs } from '@/lib/datetime/dayjs';
import type { Dayjs } from '@/lib/datetime/dayjs';

interface Props {
    label: string;
    view: 'month' | 'week';
    anchor: Dayjs;
    onPrev: () => void;
    onNext: () => void;
    onToday: () => void;
    onSetView: (v: 'month' | 'week') => void;
    onSelectDate: (d: Dayjs) => void;
}

export function CalendarHeader({
    label,
    view,
    anchor,
    onPrev,
    onNext,
    onToday,
    onSetView,
    onSelectDate,
}: Props) {
    const [open, setOpen] = useState(false);
    const tz = useSchedulingTimezone();
    const anchorJs = anchor.toDate();

    return (
        <div className="sticky top-0 z-10 flex items-center justify-between gap-3 border-b border-border bg-background/85 px-3 py-2 backdrop-blur-md">
            <div className="flex items-center gap-1">
                <Button
                    variant="ghost"
                    size="sm"
                    className="size-7 p-0"
                    onClick={onPrev}
                    aria-label={
                        view === 'month' ? 'Previous month' : 'Previous week'
                    }
                >
                    <ChevronLeft className="size-3.5" />
                </Button>
                <Button
                    variant="ghost"
                    size="sm"
                    className="size-7 p-0"
                    onClick={onNext}
                    aria-label={view === 'month' ? 'Next month' : 'Next week'}
                >
                    <ChevronRight className="size-3.5" />
                </Button>
                <Button
                    variant="ghost"
                    size="sm"
                    className="h-7 px-2 text-[12px]"
                    onClick={onToday}
                >
                    Today
                </Button>

                <Popover open={open} onOpenChange={setOpen}>
                    <PopoverTrigger asChild>
                        <Button
                            variant="ghost"
                            size="sm"
                            aria-label="Jump to date"
                            className="ml-1 h-7 gap-1.5 px-2 text-[15px] font-semibold tracking-tight tabular-nums hover:bg-muted/60"
                        >
                            <CalendarIcon
                                className="size-3.5 text-muted-foreground"
                                aria-hidden
                            />
                            {label}
                        </Button>
                    </PopoverTrigger>
                    <PopoverContent align="start" className="w-auto p-0">
                        <Calendar
                            mode="single"
                            defaultMonth={anchorJs}
                            selected={anchorJs}
                            onSelect={(d) => {
                                if (!d) return;
                                onSelectDate(dayjs(d).tz(tz));
                                setOpen(false);
                            }}
                        />
                    </PopoverContent>
                </Popover>
            </div>

            <div className="flex items-center gap-2">
                <span className="text-[11px] text-muted-foreground">{tz}</span>
                <ToggleGroup
                    type="single"
                    value={view}
                    size="sm"
                    variant="outline"
                    onValueChange={(v) => {
                        if (v === 'month' || v === 'week') onSetView(v);
                    }}
                    className="h-7"
                >
                    <ToggleGroupItem
                        value="month"
                        className="h-7 px-2.5 text-[11.5px] font-medium"
                    >
                        Month
                    </ToggleGroupItem>
                    <ToggleGroupItem
                        value="week"
                        className="h-7 px-2.5 text-[11.5px] font-medium"
                    >
                        Week
                    </ToggleGroupItem>
                </ToggleGroup>
            </div>
        </div>
    );
}
