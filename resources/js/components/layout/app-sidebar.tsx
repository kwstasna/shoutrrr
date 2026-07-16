import { Link, router, usePage } from '@inertiajs/react';
import {
    CalendarDays,
    ChartColumn,
    ChevronDown,
    Inbox,
    ListChecks,
    MessageCircle,
    Pencil,
    Settings,
    Share2,
    Wrench,
    type LucideIcon,
} from 'lucide-react';
import { useEffect, useState } from 'react';

import PostingScheduleController from '@/actions/App/Http/Controllers/Posts/PostingScheduleController';
import AppLogo from '@/components/layout/app-logo';
import { NavUser } from '@/components/layout/nav-user';
import { SidebarFooterCard } from '@/components/layout/sidebar-footer-card';
import {
    Collapsible,
    CollapsibleContent,
    CollapsibleTrigger,
} from '@/components/ui/collapsible';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuLabel,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { Kbd } from '@/components/ui/kbd';
import {
    Sidebar,
    SidebarContent,
    SidebarFooter,
    SidebarGroup,
    SidebarGroupContent,
    SidebarGroupLabel,
    SidebarHeader,
    SidebarMenu,
    SidebarMenuButton,
    SidebarMenuItem,
    SidebarMenuSub,
    SidebarMenuSubButton,
    SidebarMenuSubItem,
    useSidebar,
} from '@/components/ui/sidebar';
import {
    Tooltip,
    TooltipContent,
    TooltipTrigger,
} from '@/components/ui/tooltip';
import { WorkspaceSelector } from '@/components/workspace/workspace-selector';
import { useCurrentUrl } from '@/hooks/use-current-url';
import {
    composeButtonClassName,
    composeIconClassName,
} from '@/lib/navigation/compose-nav';
import {
    instanceSettingsNavItems,
    type InstanceSettingsNavItem,
} from '@/lib/navigation/instance-settings-nav';
import {
    workspaceSettingsNavItems,
    type WorkspaceSettingsNavItem,
} from '@/lib/navigation/workspace-settings-nav';
import { cn } from '@/lib/utils';
import { appVersion, githubReleaseUrl } from '@/lib/version';
import { dashboard } from '@/routes';
import { index as accountsRoute } from '@/routes/accounts';
import { index as analyticsRoute } from '@/routes/analytics';
import { index as calendarRoute } from '@/routes/calendar';
import { index as engagementRoute } from '@/routes/engagement';
import { index as postsRoute } from '@/routes/posts';

type NavItem = {
    title: string;
    href: NonNullable<Parameters<typeof Link>[0]['href']>;
    icon: LucideIcon;
};

type NestedNavItem = WorkspaceSettingsNavItem | InstanceSettingsNavItem;

export const workspaceSettingsLabel = 'Settings';
export const instanceSettingsLabel = 'Instance settings';

function NestedSidebarNav({
    label,
    icon: Icon,
    items,
    active,
    open,
    onOpenChange,
    collapsed,
    isItemActive,
}: {
    label: string;
    icon: LucideIcon;
    items: NestedNavItem[];
    active: boolean;
    open: boolean;
    onOpenChange: (open: boolean) => void;
    collapsed: boolean;
    isItemActive: (item: NestedNavItem) => boolean;
}) {
    if (collapsed) {
        return (
            <SidebarMenuItem>
                <DropdownMenu>
                    <DropdownMenuTrigger
                        render={
                            <SidebarMenuButton
                                tooltip={label}
                                isActive={collapsed && active}
                                className="data-[popup-open]:bg-sidebar-accent"
                            />
                        }
                    >
                        <Icon aria-hidden="true" />
                        <span>{label}</span>
                    </DropdownMenuTrigger>
                    <DropdownMenuContent
                        side="right"
                        align="start"
                        sideOffset={4}
                        className="min-w-44 rounded-lg"
                    >
                        <DropdownMenuLabel>{label}</DropdownMenuLabel>
                        {items.map((item) => (
                            <DropdownMenuItem
                                key={item.key}
                                render={
                                    <Link
                                        href={item.href}
                                        className="cursor-pointer"
                                    />
                                }
                                className={cn(
                                    isItemActive(item) &&
                                        'bg-accent font-medium',
                                )}
                            >
                                {item.title}
                            </DropdownMenuItem>
                        ))}
                    </DropdownMenuContent>
                </DropdownMenu>
            </SidebarMenuItem>
        );
    }

    return (
        <Collapsible
            open={open}
            onOpenChange={onOpenChange}
            className="group/collapsible"
        >
            <SidebarMenuItem>
                <CollapsibleTrigger
                    render={
                        <SidebarMenuButton className="[&[data-panel-open]>svg:last-child]:rotate-180" />
                    }
                >
                    <Icon aria-hidden="true" />
                    <span>{label}</span>
                    <ChevronDown
                        aria-hidden="true"
                        className="ml-auto transition-transform"
                    />
                </CollapsibleTrigger>
                <CollapsibleContent>
                    <SidebarMenuSub>
                        {items.map((item) => (
                            <SidebarMenuSubItem key={item.key}>
                                <SidebarMenuSubButton
                                    isActive={isItemActive(item)}
                                    render={<Link href={item.href} />}
                                >
                                    <span>{item.title}</span>
                                </SidebarMenuSubButton>
                            </SidebarMenuSubItem>
                        ))}
                    </SidebarMenuSub>
                </CollapsibleContent>
            </SidebarMenuItem>
        </Collapsible>
    );
}

const versionBadgeClassName =
    'rounded-full border border-sidebar-border px-1.5 py-0.5 text-[10px] leading-none font-medium text-sidebar-foreground/60 transition-colors hover:border-sidebar-accent-foreground/30 hover:text-sidebar-foreground';

const postsNavItems: NavItem[] = [
    { title: 'Posts', href: postsRoute(), icon: Inbox },
    { title: 'Calendar', href: calendarRoute(), icon: CalendarDays },
    {
        title: 'Queue',
        href: PostingScheduleController.show(),
        icon: ListChecks,
    },
    { title: 'Accounts', href: accountsRoute(), icon: Share2 },
    { title: 'Engagement', href: engagementRoute(), icon: MessageCircle },
];

export function AppSidebar() {
    const {
        workspaces,
        features,
        instance,
        shell,
        updateAvailable,
        latestVersion,
        latestReleaseUrl,
    } = usePage().props;
    const unreadReplies = shell?.unreadReplies ?? 0;
    const { isCurrentOrParentUrl, isCurrentUrl } = useCurrentUrl();
    const { state, setOpenMobile } = useSidebar();
    const collapsed = state === 'collapsed';

    // The mobile sidebar is an off-canvas sheet living in a persistent layout,
    // so it stays open across Inertia visits. Close it the moment a real
    // navigation starts (link click, command palette, programmatic visit).
    useEffect(
        () =>
            router.on('start', () => {
                setOpenMobile(false);
            }),
        [setOpenMobile],
    );

    const composeHref = dashboard();
    const showWorkspaceSettings = workspaces.enabled && workspaces.current;
    const showInstanceSettings = instance.isOwner;
    const settingsItems = showWorkspaceSettings
        ? workspaceSettingsNavItems({
              permissions: workspaces.current?.permissions ?? [],
              billingEnabled: !!features?.billing,
          })
        : [];
    const instanceItems = showInstanceSettings
        ? instanceSettingsNavItems()
        : [];
    const settingsActive = settingsItems.some((item) =>
        item.key === 'overview'
            ? isCurrentUrl(item.href)
            : isCurrentOrParentUrl(item.href),
    );
    const instanceActive = instanceItems.some((item) =>
        item.key === 'general'
            ? isCurrentUrl(item.href)
            : isCurrentOrParentUrl(item.href),
    );
    const [settingsOpen, setSettingsOpen] = useState(settingsActive);
    const [instanceOpen, setInstanceOpen] = useState(instanceActive);

    useEffect(() => {
        if (settingsActive) {
            setSettingsOpen(true);
        }
    }, [settingsActive]);

    useEffect(() => {
        if (instanceActive) {
            setInstanceOpen(true);
        }
    }, [instanceActive]);

    return (
        <Sidebar collapsible="icon" variant="inset">
            <SidebarHeader className="gap-1.5">
                <SidebarMenu>
                    <SidebarMenuItem className="flex items-center gap-1">
                        <SidebarMenuButton
                            className="h-8 min-w-0 flex-1 group-data-[collapsible=icon]:justify-center group-data-[collapsible=icon]:p-0!"
                            render={<Link href={composeHref} />}
                        >
                            <AppLogo />
                        </SidebarMenuButton>
                        <span className="relative flex group-data-[collapsible=icon]:hidden">
                            {(() => {
                                const badge = (
                                    <a
                                        href={
                                            updateAvailable && latestReleaseUrl
                                                ? latestReleaseUrl
                                                : githubReleaseUrl
                                        }
                                        target="_blank"
                                        rel="noopener noreferrer"
                                        className={versionBadgeClassName}
                                        aria-label={
                                            updateAvailable
                                                ? `Shoutrrr ${appVersion} — update ${latestVersion ?? ''} available on GitHub`
                                                : `View Shoutrrr ${appVersion} release notes on GitHub`
                                        }
                                    >
                                        {appVersion}
                                    </a>
                                );

                                return updateAvailable ? (
                                    <Tooltip>
                                        <TooltipTrigger render={badge} />
                                        <TooltipContent>
                                            Update available: {latestVersion}
                                        </TooltipContent>
                                    </Tooltip>
                                ) : (
                                    badge
                                );
                            })()}
                            {updateAvailable && (
                                <span
                                    className="absolute -top-0.5 -right-0.5 h-2 w-2 rounded-full bg-red-500 ring-2 ring-sidebar"
                                    aria-hidden="true"
                                />
                            )}
                        </span>
                    </SidebarMenuItem>
                </SidebarMenu>
                <WorkspaceSelector />
            </SidebarHeader>

            <SidebarContent className="gap-0">
                <SidebarGroup className="border-b border-sidebar-border">
                    <SidebarGroupContent>
                        <SidebarMenu>
                            <SidebarMenuItem>
                                <SidebarMenuButton
                                    tooltip="Compose new post"
                                    isActive={isCurrentUrl(composeHref)}
                                    className={composeButtonClassName(
                                        collapsed,
                                    )}
                                    render={<Link href={composeHref} />}
                                >
                                    <span className="pointer-events-none flex items-center gap-2">
                                        <span
                                            className={composeIconClassName()}
                                        >
                                            <Pencil aria-hidden="true" />
                                        </span>
                                        {!collapsed && (
                                            <span>Compose post</span>
                                        )}
                                    </span>
                                    {!collapsed && (
                                        <Kbd className="bg-primary-foreground/15 text-primary-foreground">
                                            ⌘.
                                        </Kbd>
                                    )}
                                </SidebarMenuButton>
                            </SidebarMenuItem>
                        </SidebarMenu>
                    </SidebarGroupContent>
                </SidebarGroup>

                <SidebarGroup>
                    <SidebarGroupLabel>Posts</SidebarGroupLabel>
                    <SidebarGroupContent>
                        <SidebarMenu>
                            {postsNavItems
                                .filter(
                                    (item) =>
                                        item.title !== 'Engagement' ||
                                        features?.engagement,
                                )
                                .map((item) => (
                                    <SidebarMenuItem key={item.title}>
                                        <SidebarMenuButton
                                            tooltip={item.title}
                                            isActive={isCurrentUrl(item.href)}
                                            render={<Link href={item.href} />}
                                        >
                                            <item.icon aria-hidden="true" />
                                            <span>{item.title}</span>
                                            {item.title === 'Engagement' &&
                                            unreadReplies > 0 ? (
                                                <span className="ml-auto rounded-full bg-primary px-1.5 py-0.5 text-[10px] font-medium text-primary-foreground">
                                                    {unreadReplies > 99
                                                        ? '99+'
                                                        : unreadReplies}
                                                </span>
                                            ) : null}
                                        </SidebarMenuButton>
                                    </SidebarMenuItem>
                                ))}
                            {features?.analytics && (
                                <SidebarMenuItem>
                                    <SidebarMenuButton
                                        tooltip="Analytics"
                                        isActive={isCurrentUrl(
                                            analyticsRoute(),
                                        )}
                                        render={
                                            <Link href={analyticsRoute()} />
                                        }
                                    >
                                        <ChartColumn aria-hidden="true" />
                                        <span>Analytics</span>
                                    </SidebarMenuButton>
                                </SidebarMenuItem>
                            )}
                        </SidebarMenu>
                    </SidebarGroupContent>
                </SidebarGroup>

                {showWorkspaceSettings && (
                    <SidebarGroup>
                        <SidebarGroupLabel>Workspace</SidebarGroupLabel>
                        <SidebarGroupContent>
                            <SidebarMenu>
                                <NestedSidebarNav
                                    label={workspaceSettingsLabel}
                                    icon={Settings}
                                    items={settingsItems}
                                    active={settingsActive}
                                    open={settingsOpen}
                                    onOpenChange={setSettingsOpen}
                                    collapsed={collapsed}
                                    isItemActive={(item) =>
                                        item.key === 'overview'
                                            ? isCurrentUrl(item.href)
                                            : isCurrentOrParentUrl(item.href)
                                    }
                                />
                                {showInstanceSettings && (
                                    <NestedSidebarNav
                                        label={instanceSettingsLabel}
                                        icon={Wrench}
                                        items={instanceItems}
                                        active={instanceActive}
                                        open={instanceOpen}
                                        onOpenChange={setInstanceOpen}
                                        collapsed={collapsed}
                                        isItemActive={(item) =>
                                            item.key === 'general'
                                                ? isCurrentUrl(item.href)
                                                : isCurrentOrParentUrl(
                                                      item.href,
                                                  )
                                        }
                                    />
                                )}
                            </SidebarMenu>
                        </SidebarGroupContent>
                    </SidebarGroup>
                )}
            </SidebarContent>

            <SidebarFooter>
                <SidebarFooterCard />
                <NavUser />
            </SidebarFooter>
        </Sidebar>
    );
}
