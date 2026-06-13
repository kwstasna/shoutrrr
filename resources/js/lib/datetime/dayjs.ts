import baseDayjs, { type Dayjs } from 'dayjs';
import advancedFormat from 'dayjs/plugin/advancedFormat';
import customParseFormat from 'dayjs/plugin/customParseFormat';
import timezone from 'dayjs/plugin/timezone';
import utc from 'dayjs/plugin/utc';
import weekday from 'dayjs/plugin/weekday';

baseDayjs.extend(utc);
baseDayjs.extend(timezone);
baseDayjs.extend(advancedFormat);
baseDayjs.extend(customParseFormat);
baseDayjs.extend(weekday);

export const dayjs = baseDayjs;
export type { Dayjs };

/** IANA tz name from the browser session. */
export function userTz(): string {
    return Intl.DateTimeFormat().resolvedOptions().timeZone || 'UTC';
}

/** Parse an ISO-8601 string to a Dayjs in `tz` (defaults to the user tz). */
export function toUserTz(iso: string, tz: string = userTz()): Dayjs {
    return dayjs(iso).tz(tz);
}

export interface DayRange {
    start: Dayjs;
    end: Dayjs;
    days: Dayjs[];
}

/** 6-week month grid aligned Sunday-first (stable 42-day layout). */
export function monthRange(anchor: Dayjs): DayRange {
    const start = anchor.startOf('month').weekday(0);
    const end = start.add(41, 'day');
    const days: Dayjs[] = [];
    for (let i = 0; i < 42; i += 1) days.push(start.add(i, 'day'));
    return { start, end, days };
}

/** 7-day Sunday-first week containing `anchor`. */
export function weekRange(anchor: Dayjs): DayRange {
    const start = anchor.weekday(0);
    const end = start.add(6, 'day');
    const days: Dayjs[] = [];
    for (let i = 0; i < 7; i += 1) days.push(start.add(i, 'day'));
    return { start, end, days };
}

/** YYYY-MM key used by the calendar route segment. */
export function ymKey(d: Dayjs): string {
    return d.format('YYYY-MM');
}

/** Parse a YYYY-MM string into a Dayjs at the 1st of that month (UTC). */
export function parseYm(ym: string): Dayjs | null {
    const d = dayjs.utc(`${ym}-01`, 'YYYY-MM-DD', true);
    return d.isValid() ? d : null;
}
