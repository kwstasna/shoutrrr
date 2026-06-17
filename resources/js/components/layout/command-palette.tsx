import { router, usePage } from '@inertiajs/react';
import {
    Building2,
    CalendarDays,
    FileText,
    ListChecks,
    LogOut,
    Pencil,
    Settings,
    Share2,
} from 'lucide-react';
import { useCallback, useEffect, useState } from 'react';

import PostingScheduleController from '@/actions/App/Http/Controllers/Posts/PostingScheduleController';
import WorkspaceSettingsController from '@/actions/App/Http/Controllers/Settings/WorkspaceSettingsController';
import {
    Command,
    CommandDialog,
    CommandEmpty,
    CommandGroup,
    CommandInput,
    CommandItem,
    CommandList,
    CommandSeparator,
} from '@/components/ui/command';
import { dashboard, logout } from '@/routes';
import { index as accountsRoute } from '@/routes/accounts';
import { index as calendarRoute } from '@/routes/calendar';
import { index as postsRoute } from '@/routes/posts';
import { switchMethod } from '@/routes/workspaces';

/** Window event the topbar search button dispatches to open the palette. */
export const OPEN_COMMAND_EVENT = 'shoutrrr:open-command';

export function openCommandPalette() {
    window.dispatchEvent(new Event(OPEN_COMMAND_EVENT));
}

export function CommandPalette() {
    const { workspaces } = usePage().props;
    const [open, setOpen] = useState(false);

    const composeUrl = dashboard().url;

    useEffect(() => {
        function onKey(e: KeyboardEvent) {
            const meta = e.metaKey || e.ctrlKey;
            if (!meta) {
                return;
            }
            const key = e.key.toLowerCase();
            if (key === 'k') {
                e.preventDefault();
                setOpen((v) => !v);
            } else if (key === 'n') {
                e.preventDefault();
                router.visit(composeUrl);
            }
        }
        function onOpen() {
            setOpen(true);
        }
        window.addEventListener('keydown', onKey);
        window.addEventListener(OPEN_COMMAND_EVENT, onOpen);
        return () => {
            window.removeEventListener('keydown', onKey);
            window.removeEventListener(OPEN_COMMAND_EVENT, onOpen);
        };
    }, [composeUrl]);

    const run = useCallback((fn: () => void) => {
        return () => {
            setOpen(false);
            fn();
        };
    }, []);

    const current = workspaces.current;
    const showSettings = workspaces.enabled && current;
    const otherWorkspaces = workspaces.enabled
        ? workspaces.all.filter((w) => w.id !== current?.id)
        : [];

    return (
        <CommandDialog open={open} onOpenChange={setOpen}>
            <Command>
                <CommandInput placeholder="Type a command or search…" />
                <CommandList>
                    <CommandEmpty>No results.</CommandEmpty>

                    <CommandGroup heading="Go to">
                        <CommandItem
                            value="compose new post"
                            onSelect={run(() => router.visit(composeUrl))}
                        >
                            <Pencil className="size-4" aria-hidden />
                            Compose
                        </CommandItem>
                        <CommandItem
                            value="posts"
                            onSelect={run(() => router.visit(postsRoute().url))}
                        >
                            <FileText className="size-4" aria-hidden />
                            Posts
                        </CommandItem>
                        <CommandItem
                            value="calendar"
                            onSelect={run(() =>
                                router.visit(calendarRoute().url),
                            )}
                        >
                            <CalendarDays className="size-4" aria-hidden />
                            Calendar
                        </CommandItem>
                        <CommandItem
                            value="queue"
                            onSelect={run(() =>
                                router.visit(
                                    PostingScheduleController.show().url,
                                ),
                            )}
                        >
                            <ListChecks className="size-4" aria-hidden />
                            Queue
                        </CommandItem>
                        <CommandItem
                            value="accounts connections"
                            onSelect={run(() =>
                                router.visit(accountsRoute().url),
                            )}
                        >
                            <Share2 className="size-4" aria-hidden />
                            Accounts
                        </CommandItem>
                        {showSettings && (
                            <CommandItem
                                value="workspace settings"
                                onSelect={run(() =>
                                    router.visit(
                                        WorkspaceSettingsController.showOverview()
                                            .url,
                                    ),
                                )}
                            >
                                <Settings className="size-4" aria-hidden />
                                Workspace settings
                            </CommandItem>
                        )}
                    </CommandGroup>

                    {otherWorkspaces.length > 0 && (
                        <>
                            <CommandSeparator />
                            <CommandGroup heading="Switch workspace">
                                {otherWorkspaces.map((workspace) => (
                                    <CommandItem
                                        key={workspace.id}
                                        value={`switch workspace ${workspace.name}`}
                                        onSelect={run(() =>
                                            router.post(
                                                switchMethod.url(),
                                                { workspace_id: workspace.id },
                                                { preserveState: false },
                                            ),
                                        )}
                                    >
                                        <Building2
                                            className="size-4"
                                            aria-hidden
                                        />
                                        <span className="truncate">
                                            {workspace.name}
                                        </span>
                                    </CommandItem>
                                ))}
                            </CommandGroup>
                        </>
                    )}

                    <CommandSeparator />
                    <CommandGroup heading="Account">
                        <CommandItem
                            value="sign out log out"
                            onSelect={run(() => router.post(logout.url()))}
                        >
                            <LogOut className="size-4" aria-hidden />
                            Sign out
                        </CommandItem>
                    </CommandGroup>
                </CommandList>
            </Command>
        </CommandDialog>
    );
}
