import {
    CartesianGrid,
    Line,
    LineChart,
    ReferenceLine,
    XAxis,
    YAxis,
} from 'recharts';

import { PlatformGlyph } from '@/components/common/platform-glyph';
import {
    ChartContainer,
    ChartTooltip,
    ChartTooltipContent,
    type ChartConfig,
} from '@/components/ui/chart';
import { dayjs } from '@/lib/datetime/dayjs';
import type { AnalyticsPageProps } from '@/types/metrics';

const ACCOUNT_COLORS = [
    'var(--chart-1)',
    'var(--chart-2)',
    'var(--chart-3)',
    'var(--chart-4)',
    'var(--chart-5)',
];

type FollowerChartProps = {
    accounts: AnalyticsPageProps['accounts'];
    posts: AnalyticsPageProps['posts'];
};

/**
 * The follower-growth line chart. Lives in its own module so recharts (a heavy
 * dependency) is code-split into a lazily-loaded chunk and only fetched when
 * there is series data to plot.
 */
export default function FollowerChart({ accounts, posts }: FollowerChartProps) {
    // Build merged timeline: collect all unique timestamps across all accounts,
    // then create one row per timestamp with follower counts keyed by account id.
    // Use epoch-ms as the x-axis key so numeric time scale and ReferenceLine markers align.
    const allTimestamps = [
        ...new Set(accounts.flatMap((a) => a.series.map((s) => s.at))),
    ].sort();

    type ChartRow = Record<string, number | undefined> & {
        date: number;
    };

    const chartData: ChartRow[] = allTimestamps.map((at) => {
        const row: ChartRow = { date: new Date(at).getTime() };
        for (const account of accounts) {
            const point = account.series.find((s) => s.at === at);
            if (point) {
                row[account.id] = point.followers ?? undefined;
            }
        }
        return row;
    });

    const chartConfig: ChartConfig = Object.fromEntries(
        accounts.map((a, i) => [
            a.id,
            {
                label: a.display_name ?? a.handle,
                color: ACCOUNT_COLORS[i % ACCOUNT_COLORS.length],
            },
        ]),
    );

    return (
        <div className="p-5">
            <ChartContainer
                config={chartConfig}
                className="h-[260px] w-full"
                initialDimension={{ width: 800, height: 260 }}
            >
                <LineChart
                    data={chartData}
                    margin={{ top: 4, right: 8, bottom: 0, left: 0 }}
                >
                    <CartesianGrid
                        strokeDasharray="3 3"
                        className="stroke-border/50"
                        vertical={false}
                    />
                    <XAxis
                        dataKey="date"
                        type="number"
                        scale="time"
                        domain={['dataMin', 'dataMax']}
                        tickLine={false}
                        axisLine={false}
                        tickMargin={8}
                        tick={{ fontSize: 11 }}
                        tickFormatter={(v: number) => dayjs(v).format('MMM D')}
                        interval="preserveStartEnd"
                    />
                    <YAxis
                        tickLine={false}
                        axisLine={false}
                        tickMargin={8}
                        tick={{ fontSize: 11 }}
                        tickFormatter={(v: number) =>
                            v >= 1000 ? `${(v / 1000).toFixed(1)}k` : String(v)
                        }
                        width={40}
                    />
                    <ChartTooltip
                        content={
                            <ChartTooltipContent
                                labelFormatter={(v) =>
                                    dayjs(v as number).format('MMM D, YYYY')
                                }
                                indicator="line"
                            />
                        }
                    />

                    {/* Post publish markers */}
                    {posts.map((post) => (
                        <ReferenceLine
                            key={post.id}
                            x={new Date(post.published_at).getTime()}
                            stroke="var(--primary)"
                            strokeDasharray="3 3"
                            strokeWidth={1.5}
                            opacity={0.6}
                            label={(props: {
                                viewBox?: { x?: number; y?: number };
                            }) => {
                                const x = (props.viewBox?.x ?? 0) + 3;
                                const y = (props.viewBox?.y ?? 0) + 12;
                                return (
                                    <text
                                        x={x}
                                        y={y}
                                        fontSize={10}
                                        fill="var(--primary)"
                                    >
                                        <title>
                                            {post.title || 'Untitled post'}
                                        </title>
                                        ↑
                                    </text>
                                );
                            }}
                        />
                    ))}

                    {accounts.map((account, i) => (
                        <Line
                            key={account.id}
                            dataKey={account.id}
                            type="monotone"
                            stroke={ACCOUNT_COLORS[i % ACCOUNT_COLORS.length]}
                            strokeWidth={2}
                            dot={false}
                            activeDot={{ r: 4, strokeWidth: 0 }}
                            connectNulls
                        />
                    ))}
                </LineChart>
            </ChartContainer>

            {/* Legend */}
            {accounts.length > 1 && (
                <div className="mt-3 flex flex-wrap items-center gap-4">
                    {accounts.map((account, i) => (
                        <div
                            key={account.id}
                            className="flex items-center gap-1.5"
                        >
                            <span
                                className="block h-2 w-4 rounded-full"
                                style={{
                                    backgroundColor:
                                        ACCOUNT_COLORS[
                                            i % ACCOUNT_COLORS.length
                                        ],
                                }}
                            />
                            <span className="flex items-center gap-1 text-[11px] text-muted-foreground">
                                <PlatformGlyph
                                    platform={account.platform}
                                    size={10}
                                />
                                {account.display_name ?? account.handle}
                            </span>
                        </div>
                    ))}
                </div>
            )}
        </div>
    );
}
