import { Link, router, usePage } from '@inertiajs/react';
import { Bell } from 'lucide-react';
import { useEffect, useState } from 'react';

import { Button } from '@/components/ui/button';
import {
    Popover,
    PopoverContent,
    PopoverTrigger,
} from '@/components/ui/popover';
import { cn } from '@/lib/utils';
import {
    read as markRead,
    readAll as markAllRead,
} from '@/routes/notifications';
import type { NotificationItem } from '@/types/notifications';

export function NotificationBell() {
    const { notifications } = usePage().props;
    const [items, setItems] = useState<NotificationItem[]>(notifications.items);

    useEffect(() => {
        setItems(notifications.items);
    }, [notifications.items]);

    const unread = items.filter((n) => !n.read).length;

    function markOneRead(id: string) {
        setItems((prev) =>
            prev.map((n) => (n.id === id ? { ...n, read: true } : n)),
        );
        router.post(
            markRead(id).url,
            {},
            { preserveScroll: true, preserveState: true },
        );
    }

    function markEverythingRead() {
        setItems((prev) => prev.map((n) => ({ ...n, read: true })));
        router.post(
            markAllRead().url,
            {},
            { preserveScroll: true, preserveState: true },
        );
    }

    return (
        <Popover>
            <PopoverTrigger asChild>
                <Button
                    variant="ghost"
                    size="icon"
                    className="relative size-8 text-muted-foreground"
                    aria-label={
                        unread > 0
                            ? `Notifications (${unread} unread)`
                            : 'Notifications'
                    }
                >
                    <Bell className="size-4" />
                    {unread > 0 && (
                        <span className="absolute -top-0.5 -right-0.5 grid h-4 min-w-4 place-items-center rounded-full bg-destructive px-1 text-[10px] font-medium text-white tabular-nums">
                            {unread > 9 ? '9+' : unread}
                        </span>
                    )}
                </Button>
            </PopoverTrigger>
            <PopoverContent align="end" className="w-80 gap-0 p-0">
                <div className="flex items-center justify-between border-b border-border px-3 py-2">
                    <span className="text-[13px] font-semibold">
                        Notifications
                    </span>
                    <Button
                        variant="ghost"
                        size="sm"
                        className="h-6 px-2 text-[12px]"
                        disabled={unread === 0}
                        onClick={markEverythingRead}
                    >
                        Mark all read
                    </Button>
                </div>

                <div className="max-h-96 overflow-y-auto">
                    {items.length === 0 ? (
                        <div className="flex flex-col items-center gap-2 px-3 py-10 text-center">
                            <span className="grid size-9 place-items-center rounded-full bg-muted text-muted-foreground">
                                <Bell className="size-4" />
                            </span>
                            <p className="text-[12.5px] text-muted-foreground">
                                {"You're all caught up"}
                            </p>
                        </div>
                    ) : (
                        items.map((notification) => (
                            <NotificationRow
                                key={notification.id}
                                notification={notification}
                                onRead={markOneRead}
                            />
                        ))
                    )}
                </div>
            </PopoverContent>
        </Popover>
    );
}

function NotificationRow({
    notification,
    onRead,
}: {
    notification: NotificationItem;
    onRead: (id: string) => void;
}) {
    const body = (
        <div className="flex gap-2.5 px-3 py-2.5 text-left transition-colors hover:bg-muted/50">
            <span
                aria-hidden
                className={cn(
                    'mt-1.5 size-1.5 shrink-0 rounded-full',
                    notification.read ? 'bg-transparent' : 'bg-primary',
                )}
            />
            <div className="min-w-0 flex-1">
                <p className="truncate text-[13px] font-medium text-foreground">
                    {notification.title}
                </p>
                {notification.body && (
                    <p className="mt-0.5 line-clamp-2 text-[12px] text-muted-foreground">
                        {notification.body}
                    </p>
                )}
                <p className="mt-1 text-[11px] text-muted-foreground/70">
                    {notification.timeLabel}
                </p>
            </div>
        </div>
    );

    if (notification.href) {
        return (
            <Link
                href={notification.href}
                className="block border-b border-border last:border-b-0"
                onClick={() => onRead(notification.id)}
            >
                {body}
            </Link>
        );
    }

    return (
        <div
            role="button"
            tabIndex={0}
            className="border-b border-border last:border-b-0"
            onClick={() => onRead(notification.id)}
            onKeyDown={(e) => {
                if (e.key === 'Enter' || e.key === ' ') {
                    if (e.key === ' ') {
                        e.preventDefault();
                    }
                    onRead(notification.id);
                }
            }}
        >
            {body}
        </div>
    );
}
