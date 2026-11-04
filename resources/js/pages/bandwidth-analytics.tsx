import { Head, Link, router, usePage } from '@inertiajs/react';
import { Activity, ArrowDown, ArrowUp, CalendarClock, Gauge, TrendingUp, TriangleAlert } from 'lucide-react';
import { useMemo } from 'react';

import { DailyVolumeChart, UtilizationBars } from '@/components/analytics/analytics-charts';
import { TrafficLineChart } from '@/components/charts/traffic-line-chart';
import { UsageRadarChart } from '@/components/charts/usage-radar-chart';
import { EmptyState } from '@/components/empty-state';
import { Button } from '@/components/ui/button';
import AppLayout from '@/layouts/app-layout';
import { formatDateTime, formatMbps, formatPercent, formatVolume, safeTimeZone } from '@/lib/format';
import { cn } from '@/lib/utils';
import type { AnalyticsData, CampusNetShared } from '@/types/campusnet';

const RANGE_LABELS: Record<string, string> = { '7d': 'Last 7 days', '30d': 'Last 30 days', '90d': 'Last 90 days' };

function Stat({ title, value, hint, icon: Icon }: { title: string; value: string; hint?: string; icon: typeof Gauge }) {
    return (
        <section aria-label={title} className="rounded-xl border bg-card p-4 text-card-foreground shadow-xs">
            <div className="flex items-center justify-between gap-2 text-sm text-muted-foreground">
                <h3 className="font-medium">{title}</h3>
                <Icon className="size-4" aria-hidden="true" />
            </div>
            <p className="mt-2 text-2xl font-semibold tabular-nums">{value}</p>
            {hint && <p className="mt-1 text-xs text-muted-foreground">{hint}</p>}
        </section>
    );
}

function hourLabel(hour: number): string {
    return `${String(hour).padStart(2, '0')}:00`;
}

export default function BandwidthAnalytics({ analytics }: { analytics: AnalyticsData }) {
    const { campusnet } = usePage<CampusNetShared>().props;
    const timeZone = safeTimeZone(campusnet?.timezone);
    const { summary, busiest, thresholds } = analytics;

    const periodLabel = RANGE_LABELS[analytics.range] ?? analytics.range;
    const sourceLabel = analytics.mode.demo ? 'Simulated demo data' : `Live ${analytics.mode.driver.toUpperCase()} data`;

    const hourlyData = useMemo(() => analytics.hourly.map((item) => ({ label: String(item.hour).padStart(2, '0'), value: item.avg_utilization_percent })), [analytics.hourly]);
    const weekdayData = useMemo(() => analytics.weekday.map((item) => ({ label: item.label, value: item.avg_utilization_percent })), [analytics.weekday]);

    const visit = (range: string) => {
        router.get('/bandwidth-analytics', { range }, { only: ['analytics'], preserveState: true, preserveScroll: true, replace: true });
    };

    const change =
        summary && summary.previous_avg_utilization_percent !== null ? summary.avg_utilization_percent - summary.previous_avg_utilization_percent : null;

    const changeHint =
        change === null
            ? 'No earlier period to compare with'
            : `${change >= 0 ? '+' : '\u2212'}${Math.abs(change).toFixed(1)} points vs the ${analytics.days} days before`;

    const volumeSummary = summary
        ? `${periodLabel}: ${formatVolume(summary.transferred.in_mb)} inbound and ${formatVolume(summary.transferred.out_mb)} outbound in total.`
        : 'No measurements in this period.';

    return (
        <AppLayout breadcrumbs={[{ title: 'Bandwidth Analytics', href: '/bandwidth-analytics' }]}>
            <Head title="Bandwidth Analytics" />

            <div className="flex flex-col gap-6 p-4">
                <div className="flex flex-wrap items-start justify-between gap-3">
                    <div>
                        <h1 className="text-xl font-semibold">Bandwidth analytics</h1>
                        <p className="text-sm text-muted-foreground">
                            {sourceLabel} &middot; the internet (WAN) link of {campusnet?.campus_name ?? 'the campus'} over {periodLabel.toLowerCase()}
                        </p>
                    </div>

                    <div role="group" aria-label="Period" className="inline-flex overflow-hidden rounded-md border">
                        {analytics.ranges.map((range) => (
                            <button
                                key={range}
                                type="button"
                                onClick={() => visit(range)}
                                aria-pressed={analytics.range === range}
                                className={cn(
                                    'px-3 py-1.5 text-sm font-medium transition-colors focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none',
                                    analytics.range === range ? 'bg-primary text-primary-foreground' : 'bg-background hover:bg-muted',
                                )}
                            >
                                {range}
                            </button>
                        ))}
                    </div>
                </div>

                {!analytics.wan.configured ? (
                    <EmptyState
                        icon={Activity}
                        title="No WAN interface is set"
                        description="Analytics measure the internet link, so at least one monitored interface must have the WAN role. Open the Interfaces page and set the port that faces your ISP to WAN."
                    >
                        <Button asChild>
                            <Link href="/interfaces">Go to Interfaces</Link>
                        </Button>
                    </EmptyState>
                ) : !analytics.has_data || summary === null ? (
                    <EmptyState
                        icon={Gauge}
                        title="No valid measurements in this period"
                        description="Nothing valid was collected from the WAN interface in the selected period. Make sure polling is running (php artisan schedule:work), then check again after a few minutes."
                    />
                ) : (
                    <>
                        <div className="grid gap-4 sm:grid-cols-2 xl:grid-cols-3">
                            <Stat title="Average utilization" value={formatPercent(summary.avg_utilization_percent)} icon={Gauge} hint={changeHint} />
                            <Stat
                                title="95th percentile"
                                value={formatPercent(summary.p95_utilization_percent)}
                                icon={TrendingUp}
                                hint="95 % of measurements were at or below this"
                            />
                            <Stat
                                title="Highest utilization"
                                value={formatPercent(summary.peak_utilization_percent)}
                                icon={CalendarClock}
                                hint={summary.peak_at ? `Reached ${formatDateTime(summary.peak_at, timeZone)}` : undefined}
                            />
                            <Stat
                                title={`Time above ${thresholds.warning}%`}
                                value={formatPercent(summary.above_warning_percent)}
                                icon={TriangleAlert}
                                hint={`${formatPercent(summary.above_critical_percent)} of the time at or above ${thresholds.critical}%`}
                            />
                            <Stat title="Data downloaded" value={formatVolume(summary.transferred.in_mb)} icon={ArrowDown} hint="Inbound, measured periods only" />
                            <Stat title="Data uploaded" value={formatVolume(summary.transferred.out_mb)} icon={ArrowUp} hint="Outbound, measured periods only" />
                        </div>

                        <section aria-label="When the link is busy" className="rounded-xl border bg-card p-4 text-card-foreground shadow-xs">
                            <h2 className="text-sm font-medium">When is the link busy?</h2>
                            <p className="mb-4 text-sm text-muted-foreground">
                                {busiest.hour !== null && busiest.hour_avg !== null
                                    ? `Busiest hour: ${hourLabel(busiest.hour)}\u2013${hourLabel((busiest.hour + 1) % 24)} (average ${formatPercent(busiest.hour_avg)}).`
                                    : 'Not enough data to find the busiest hour.'}{' '}
                                {busiest.day !== null && busiest.day_avg !== null ? `Busiest day: ${busiest.day} (average ${formatPercent(busiest.day_avg)}).` : ''}
                            </p>

                            <div className="grid gap-6 xl:grid-cols-2">
                                <div>
                                    <h3 className="mb-1 text-sm font-medium">Average utilization by hour of day</h3>
                                    <UtilizationBars
                                        data={hourlyData}
                                        title="Average utilization by hour of day"
                                        summary={
                                            busiest.hour !== null
                                                ? `Busiest hour is ${hourLabel(busiest.hour)} with an average of ${formatPercent(busiest.hour_avg)}.`
                                                : 'No hourly data.'
                                        }
                                        warning={thresholds.warning}
                                        xInterval={1}
                                    />
                                </div>
                                <div>
                                    <h3 className="mb-1 text-sm font-medium">Average utilization by day of week</h3>
                                    <UtilizationBars
                                        data={weekdayData}
                                        title="Average utilization by day of week"
                                        summary={busiest.day !== null ? `Busiest weekday is ${busiest.day} with an average of ${formatPercent(busiest.day_avg)}.` : 'No weekday data.'}
                                        warning={thresholds.warning}
                                    />
                                </div>
                            </div>
                            <p className="mt-2 text-xs text-muted-foreground">
                                Hours and days are in {timeZone}. The dashed amber line is the warning threshold ({thresholds.warning}%). A missing bar means nothing was measured then.
                            </p>
                        </section>

                        <section aria-label="Utilization patterns" className="flex flex-col gap-3">
                            <div>
                                <h2 className="text-sm font-medium">Utilization patterns</h2>
                                <p className="text-sm text-muted-foreground">The same measurements as above, drawn as a line and as a 24-hour radar. Times are in {timeZone}.</p>
                            </div>

                            <div className="grid gap-4 xl:grid-cols-2">
                                <TrafficLineChart
                                    title="Utilization by day of week"
                                    description={periodLabel}
                                    valueLabel="Average utilization (%)"
                                    data={weekdayData}
                                    format={(value) => formatPercent(value)}
                                    footer="A gap in the line means nothing was measured on that day."
                                />
                                <UsageRadarChart
                                    title="Utilization by hour of day"
                                    description={`${periodLabel}, 24-hour clock`}
                                    valueLabel="Average utilization (%)"
                                    data={hourlyData}
                                    labelEvery={3}
                                    footer="An hour with no measurement is drawn at 0 on the radar."
                                />
                            </div>
                        </section>

                        <section aria-label="Data transferred per day" className="rounded-xl border bg-card p-4 text-card-foreground shadow-xs">
                            <h2 className="mb-1 text-sm font-medium">Data transferred per day</h2>
                            <DailyVolumeChart data={analytics.daily} summary={volumeSummary} />
                            <p className="mt-2 text-xs text-muted-foreground">
                                Volume is the measured rate multiplied by the time each measurement covers. Periods without a valid measurement are not counted, so these figures are a minimum.
                            </p>
                        </section>

                        <section aria-label="Interfaces by data volume" className="rounded-xl border bg-card p-4 text-card-foreground shadow-xs">
                            <h2 className="mb-3 text-sm font-medium">Busiest interfaces by data volume</h2>

                            {analytics.top_interfaces.length === 0 ? (
                                <p className="text-sm text-muted-foreground">No interface carried measurable traffic in this period.</p>
                            ) : (
                                <div className="overflow-x-auto">
                                    <table className="w-full text-sm">
                                        <caption className="sr-only">Interfaces ordered by data transferred in {periodLabel.toLowerCase()}</caption>
                                        <thead className="text-left text-xs text-muted-foreground">
                                            <tr>
                                                <th scope="col" className="py-1 pr-3 font-medium">Interface</th>
                                                <th scope="col" className="py-1 pr-3 font-medium">Role</th>
                                                <th scope="col" className="py-1 pr-3 text-right font-medium">Downloaded</th>
                                                <th scope="col" className="py-1 pr-3 text-right font-medium">Uploaded</th>
                                                <th scope="col" className="py-1 pr-3 text-right font-medium">Total</th>
                                                <th scope="col" className="py-1 pr-3 text-right font-medium">Average rate</th>
                                                <th scope="col" className="py-1 text-right font-medium">Peak rate</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            {analytics.top_interfaces.map((item) => (
                                                <tr key={item.id} className="border-t">
                                                    <td className="py-1.5 pr-3">
                                                        <Link href={`/traffic-history?interface=${item.id}`} className="font-medium underline-offset-2 hover:underline">
                                                            {item.name}
                                                        </Link>
                                                        <div className="text-xs text-muted-foreground">{item.device ?? 'Unknown device'}</div>
                                                    </td>
                                                    <td className="py-1.5 pr-3">{item.role ?? '\u2014'}</td>
                                                    <td className="py-1.5 pr-3 text-right tabular-nums">{formatVolume(item.in_mb)}</td>
                                                    <td className="py-1.5 pr-3 text-right tabular-nums">{formatVolume(item.out_mb)}</td>
                                                    <td className="py-1.5 pr-3 text-right font-medium tabular-nums">{formatVolume(item.total_mb)}</td>
                                                    <td className="py-1.5 pr-3 text-right tabular-nums">{formatMbps(item.avg_total_mbps)}</td>
                                                    <td className="py-1.5 text-right tabular-nums">{formatMbps(item.peak_mbps)}</td>
                                                </tr>
                                            ))}
                                        </tbody>
                                    </table>
                                </div>
                            )}
                            <p className="mt-3 text-xs text-muted-foreground">These are interface counters, not per-user usage. A device that bridges or routes traffic appears on several interfaces, so totals are not added together.</p>
                        </section>
                    </>
                )}
            </div>
        </AppLayout>
    );
}