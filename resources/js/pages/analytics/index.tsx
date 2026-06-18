import { Head, Link } from '@inertiajs/react';
import { TrendingUp } from 'lucide-react';
import { lazy, Suspense } from 'react';

import { PlatformGlyph } from '@/components/common/platform-glyph';
import {
    Empty,
    EmptyContent,
    EmptyDescription,
    EmptyHeader,
    EmptyMedia,
    EmptyTitle,
} from '@/components/ui/empty';
import { dayjs } from '@/lib/datetime/dayjs';
import { cn } from '@/lib/utils';
import { dashboard } from '@/routes';
import { index as analyticsRoute } from '@/routes/analytics';
import type {
    AnalyticsPageProps,
    AnalyticsComparisonRow,
} from '@/types/metrics';

// Lazily loaded so recharts is fetched only when there is series data to plot.
const FollowerChart = lazy(
    () => import('@/components/analytics/follower-chart'),
);

const RANGE_OPTIONS = [
    { days: 7, label: '7d' },
    { days: 14, label: '14d' },
    { days: 30, label: '30d' },
    { days: 90, label: '90d' },
];

type ComparisonItemProps = {
    row: AnalyticsComparisonRow;
    rank: number;
    isTop: boolean;
};

function ComparisonItem({ row, rank, isTop }: ComparisonItemProps) {
    return (
        <div className="flex items-start gap-3 py-2.5">
            <span
                className={cn(
                    'mt-0.5 flex size-5 shrink-0 items-center justify-center rounded-full text-[10px] font-semibold tabular-nums',
                    isTop
                        ? 'bg-emerald-100 text-emerald-700 dark:bg-emerald-900/40 dark:text-emerald-400'
                        : 'bg-muted text-muted-foreground',
                )}
            >
                {rank}
            </span>
            <div className="min-w-0 flex-1">
                <p className="truncate text-[13px] leading-snug font-medium">
                    {row.title || 'Untitled post'}
                </p>
                <div className="mt-0.5 flex items-center gap-1.5">
                    {row.platforms.map((p) => (
                        <span key={p} className="text-muted-foreground">
                            <PlatformGlyph platform={p} size={10} />
                        </span>
                    ))}
                    <span className="text-[11px] text-muted-foreground">
                        {dayjs(row.published_at).format('MMM D')}
                    </span>
                </div>
            </div>
            <span
                className={cn(
                    'shrink-0 text-[12px] font-semibold tabular-nums',
                    isTop
                        ? 'text-emerald-600 dark:text-emerald-400'
                        : 'text-muted-foreground',
                )}
            >
                {row.engagement.toLocaleString()}
            </span>
        </div>
    );
}

export default function AnalyticsIndex({
    accounts,
    posts,
    comparison,
    rangeDays,
}: AnalyticsPageProps) {
    const hasSeries = accounts.some((a) => a.series.length > 0);

    return (
        <>
            <Head title="Analytics" />

            <div className="mx-auto w-full max-w-6xl space-y-6 px-4 pt-6 pb-16 sm:px-6">
                {/* Page header */}
                <div className="flex flex-wrap items-center justify-between gap-3">
                    <div>
                        <h1 className="text-[22px] leading-tight font-semibold tracking-tight">
                            Analytics
                        </h1>
                        <p className="mt-0.5 text-[13px] text-muted-foreground">
                            Follower growth and post engagement across your
                            accounts.
                        </p>
                    </div>

                    {/* Range selector */}
                    <div className="flex items-center gap-1 rounded-lg border border-border bg-muted/50 p-0.5">
                        {RANGE_OPTIONS.map((opt) => (
                            <Link
                                key={opt.days}
                                href={
                                    analyticsRoute({
                                        query: { days: opt.days },
                                    }).url
                                }
                                className={cn(
                                    'rounded-md px-3 py-1 text-[12px] font-medium transition-colors',
                                    rangeDays === opt.days
                                        ? 'bg-background text-foreground shadow-sm ring-1 ring-border/50'
                                        : 'text-muted-foreground hover:text-foreground',
                                )}
                            >
                                {opt.label}
                            </Link>
                        ))}
                    </div>
                </div>

                {/* Follower timeline — the signature piece */}
                <div className="overflow-hidden rounded-2xl border border-border bg-card shadow-sm ring-1 ring-foreground/5 dark:ring-foreground/10">
                    <div className="border-b border-border px-5 py-4">
                        <h2 className="text-[13px] font-semibold tracking-tight">
                            Follower growth
                        </h2>
                    </div>

                    {!hasSeries ? (
                        <Empty className="m-4 rounded-xl border-dashed">
                            <EmptyHeader>
                                <EmptyMedia variant="icon">
                                    <TrendingUp />
                                </EmptyMedia>
                                <EmptyTitle className="text-base">
                                    Collecting your data
                                </EmptyTitle>
                                <EmptyDescription>
                                    First numbers appear after the next sync —
                                    usually within an hour of connecting your
                                    accounts.
                                </EmptyDescription>
                            </EmptyHeader>
                        </Empty>
                    ) : (
                        <Suspense
                            fallback={
                                <div className="p-5">
                                    <div className="h-[260px] w-full animate-pulse rounded-xl bg-muted/50" />
                                </div>
                            }
                        >
                            <FollowerChart accounts={accounts} posts={posts} />
                        </Suspense>
                    )}
                </div>

                {/* Account follower counts */}
                {accounts.length > 0 && (
                    <div className="grid grid-cols-2 gap-3 sm:grid-cols-4">
                        {accounts.map((account) => (
                            <div
                                key={account.id}
                                className="overflow-hidden rounded-xl border border-border bg-card px-4 py-3 shadow-sm ring-1 ring-foreground/5 dark:ring-foreground/10"
                            >
                                <div className="flex items-center gap-2">
                                    <span className="flex size-6 shrink-0 items-center justify-center rounded-full bg-muted text-foreground">
                                        <PlatformGlyph
                                            platform={account.platform}
                                            size={11}
                                        />
                                    </span>
                                    <p className="truncate text-[11px] text-muted-foreground">
                                        {account.display_name ?? account.handle}
                                    </p>
                                </div>
                                <p className="mt-2 text-[22px] leading-tight font-semibold tracking-tight tabular-nums">
                                    {account.latest_followers !== null
                                        ? account.latest_followers.toLocaleString()
                                        : '—'}
                                </p>
                                <p className="text-[11px] text-muted-foreground">
                                    followers
                                </p>
                            </div>
                        ))}
                    </div>
                )}

                {/* Post comparison */}
                {(comparison.top.length > 0 ||
                    comparison.bottom.length > 0) && (
                    <>
                        {comparison.bottom.length === 0 ? (
                            /* Fewer than 10 eligible posts — single ranked list */
                            <div className="overflow-hidden rounded-2xl border border-border bg-card shadow-sm ring-1 ring-foreground/5 dark:ring-foreground/10">
                                <div className="border-b border-border px-5 py-3.5">
                                    <h2 className="text-[13px] font-semibold tracking-tight">
                                        Posts by engagement
                                    </h2>
                                    <p className="text-[11px] text-muted-foreground">
                                        All posts ranked by engagement this
                                        period
                                    </p>
                                </div>
                                <div className="divide-y divide-border px-5">
                                    {comparison.top.map((row, i) => (
                                        <ComparisonItem
                                            key={row.id}
                                            row={row}
                                            rank={i + 1}
                                            isTop={true}
                                        />
                                    ))}
                                </div>
                            </div>
                        ) : (
                            /* 10+ eligible posts — best vs needs-attention two-column layout */
                            <div className="grid gap-3 sm:grid-cols-2">
                                {/* Top posts */}
                                {comparison.top.length > 0 && (
                                    <div className="overflow-hidden rounded-2xl border border-border bg-card shadow-sm ring-1 ring-foreground/5 dark:ring-foreground/10">
                                        <div className="border-b border-border px-5 py-3.5">
                                            <h2 className="text-[13px] font-semibold tracking-tight">
                                                Best performing
                                            </h2>
                                            <p className="text-[11px] text-muted-foreground">
                                                Highest engagement this period
                                            </p>
                                        </div>
                                        <div className="divide-y divide-border px-5">
                                            {comparison.top.map((row, i) => (
                                                <ComparisonItem
                                                    key={row.id}
                                                    row={row}
                                                    rank={i + 1}
                                                    isTop={true}
                                                />
                                            ))}
                                        </div>
                                    </div>
                                )}

                                {/* Bottom posts */}
                                <div className="overflow-hidden rounded-2xl border border-border bg-card shadow-sm ring-1 ring-foreground/5 dark:ring-foreground/10">
                                    <div className="border-b border-border px-5 py-3.5">
                                        <h2 className="text-[13px] font-semibold tracking-tight">
                                            Needs attention
                                        </h2>
                                        <p className="text-[11px] text-muted-foreground">
                                            Lowest engagement this period
                                        </p>
                                    </div>
                                    <div className="divide-y divide-border px-5">
                                        {comparison.bottom.map((row, i) => (
                                            <ComparisonItem
                                                key={row.id}
                                                row={row}
                                                rank={i + 1}
                                                isTop={false}
                                            />
                                        ))}
                                    </div>
                                </div>
                            </div>
                        )}
                    </>
                )}

                {/* No accounts at all */}
                {accounts.length === 0 && (
                    <Empty>
                        <EmptyHeader>
                            <EmptyMedia variant="icon">
                                <TrendingUp />
                            </EmptyMedia>
                            <EmptyTitle>No accounts connected</EmptyTitle>
                            <EmptyDescription>
                                Connect an account to start tracking follower
                                growth and post engagement.
                            </EmptyDescription>
                        </EmptyHeader>
                        <EmptyContent>
                            <Link
                                href={dashboard().url}
                                className="inline-flex h-9 items-center justify-center rounded-md border border-border bg-background px-4 text-sm font-medium transition-colors hover:bg-muted"
                            >
                                Go to Dashboard
                            </Link>
                        </EmptyContent>
                    </Empty>
                )}
            </div>
        </>
    );
}

AnalyticsIndex.layout = {
    breadcrumbs: [
        {
            title: 'Analytics',
            href: analyticsRoute().url,
        },
    ],
};
