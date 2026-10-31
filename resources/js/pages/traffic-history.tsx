import { Head, router, usePage } from '@inertiajs/react';
import { ArrowDown, ArrowUp, Database, Gauge, History } from 'lucide-react';
import { useMemo, useState } from 'react';

import { TrafficChart, UtilizationChart } from '@/components/dashboard/traffic-charts';
import { EmptyState } from '@/components/empty-state';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import AppLayout from '@/layouts/app-layout';
import { formatDateTime, formatMbps, formatPercent, formatVolume, rangeLabel, safeTimeZone } from '@/lib/format';
import { cn } from '@/lib/utils';
import type { CampusNetShared, TrafficHistoryData, TrafficHistoryInterface } from '@/types/campusnet';

const SELECT =
    'border-input bg-background h-9 rounded-md border px-2 text-sm shadow-xs outline-none focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50';

function bucketLabel(seconds: number): string {
    if (seconds >= 86400) {
        return `${seconds / 86400} day`;
    }

    if (seconds >= 3600) {
        return `${seconds / 3600} hour${seconds === 3600 ? '' : 's'}`;
    }

    return `${seconds / 60} minute${seconds === 60 ? '' : 's'}`;
}

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

export default function TrafficHistory({ traffic }: { traffic: TrafficHistoryData }) {
    const { campusnet, errors } = usePage<CampusNetShared>().props;
    const timeZone = safeTimeZone(campusnet?.timezone);
    const [fromDate, setFromDate] = useState(traffic.from_date);
    const [toDate, setToDate] = useState(traffic.to_date);

    const { selected, summary, points, quality } = traffic;

    const groups = useMemo(() => {
        const map = new Map<string, TrafficHistoryInterface[]>();

        traffic.interfaces.forEach((item) => {
            const key = item.device ?? 'Unknown device';
            map.set(key, [...(map.get(key) ?? []), item]);
        });

        return Array.from(map.entries());
    }, [traffic.interfaces]);

    const visit = (changes: { range?: string; interface?: number; from?: string; to?: string }) => {
        const range = changes.range ?? traffic.range;
        const interfaceId = changes.interface ?? selected?.id;
        const query: Record<string, string | number> = { range };

        if (interfaceId) {
            query.interface = interfaceId;
        }

        if (range === 'custom') {
            query.from = changes.from ?? fromDate;
            query.to = changes.to ?? toDate;
        }

        router.get('/traffic-history', query, { only: ['traffic'], preserveState: true, preserveScroll: true, replace: true });
    };

    const periodLabel = traffic.range === 'custom' ? `${traffic.from_date} to ${traffic.to_date}` : rangeLabel(traffic.range);
    const spanMs = Date.parse(traffic.to) - Date.parse(traffic.from);
    // The chart axis shows clock times for short periods and dates for long ones.
    const axisRange = traffic.range === '1h' ? '1h' : spanMs > 172800000 ? '7d' : '24h';
    const sourceLabel = traffic.mode.demo ? 'Simulated demo data' : `Live ${traffic.mode.driver.toUpperCase()} data`;
    const dateError = errors.from ?? errors.to;

    const chartSummary = summary
        ? `${periodLabel}: average inbound ${formatMbps(summary.avg_in_mbps)}, average outbound ${formatMbps(summary.avg_out_mbps)}, peak inbound ${formatMbps(summary.peak_in_mbps)}, peak outbound ${formatMbps(summary.peak_out_mbps)}.`
        : 'No measurements in this period.';

    const capacityNote =
        selected?.capacity_basis === 'internet_capacity'
            ? `Utilization is measured against the configured internet capacity (${formatMbps(selected.capacity_mbps)}).`
            : selected?.capacity_basis === 'link_speed'
              ? `Utilization is measured against this interface's link speed (${formatMbps(selected.capacity_mbps)}).`
              : 'Utilization is not shown because this interface has no known link speed.';

    return (
        <AppLayout breadcrumbs={[{ title: 'Traffic History', href: '/traffic-history' }]}>
            <Head title="Traffic History" />

            <div className="flex flex-col gap-6 p-4">
                <div>
                    <h1 className="text-xl font-semibold">Traffic history</h1>
                    <p className="text-sm text-muted-foreground">
                        {sourceLabel} &middot; one interface at a time &middot; each point is the average over {bucketLabel(traffic.bucket_seconds)}
                    </p>
                </div>

                <form aria-label="Choose interface and period" onSubmit={(event) => event.preventDefault()} className="flex flex-wrap items-end gap-3">
                    <div className="grid gap-1">
                        <label htmlFor="history-interface" className="text-xs text-muted-foreground">
                            Interface
                        </label>
                        <select
                            id="history-interface"
                            className={cn(SELECT, 'min-w-64')}
                            value={selected?.id ?? ''}
                            disabled={traffic.interfaces.length === 0}
                            onChange={(event) => visit({ interface: Number(event.target.value) })}
                        >
                            {groups.map(([device, items]) => (
                                <optgroup key={device} label={device}>
                                    {items.map((item) => (
                                        <option key={item.id} value={item.id}>
                                            {item.name} ({item.role})
                                        </option>
                                    ))}
                                </optgroup>
                            ))}
                        </select>
                    </div>

                    <div role="group" aria-label="Period" className="inline-flex overflow-hidden rounded-md border">
                        {traffic.ranges.map((range) => (
                            <button
                                key={range}
                                type="button"
                                onClick={() => visit({ range })}
                                aria-pressed={traffic.range === range}
                                className={cn(
                                    'px-3 py-1.5 text-sm font-medium transition-colors focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none',
                                    traffic.range === range ? 'bg-primary text-primary-foreground' : 'bg-background hover:bg-muted',
                                )}
                            >
                                {range}
                            </button>
                        ))}
                        <button
                            type="button"
                            onClick={() => visit({ range: 'custom', from: fromDate, to: toDate })}
                            aria-pressed={traffic.range === 'custom'}
                            className={cn(
                                'px-3 py-1.5 text-sm font-medium transition-colors focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none',
                                traffic.range === 'custom' ? 'bg-primary text-primary-foreground' : 'bg-background hover:bg-muted',
                            )}
                        >
                            Custom
                        </button>
                    </div>

                    {traffic.range === 'custom' && (
                        <>
                            <div className="grid gap-1">
                                <label htmlFor="history-from" className="text-xs text-muted-foreground">
                                    From
                                </label>
                                <Input id="history-from" type="date" value={fromDate} max={toDate} onChange={(event) => setFromDate(event.target.value)} />
                            </div>
                            <div className="grid gap-1">
                                <label htmlFor="history-to" className="text-xs text-muted-foreground">
                                    To
                                </label>
                                <Input id="history-to" type="date" value={toDate} min={fromDate} onChange={(event) => setToDate(event.target.value)} />
                            </div>
                            <Button type="button" onClick={() => visit({ range: 'custom', from: fromDate, to: toDate })}>
                                Apply
                            </Button>
                        </>
                    )}
                </form>

                {dateError && (
                    <p role="alert" className="text-sm text-red-700 dark:text-red-400">
                        {dateError}
                    </p>
                )}

                {selected === null ? (
                    <EmptyState
                        icon={History}
                        title="No monitored interfaces"
                        description="Interfaces appear here once a device has been polled. In demo mode, run php artisan campusnet:demo-data and php artisan campusnet:poll --force --sync."
                    />
                ) : (
                    <>
                        <p className="text-sm text-muted-foreground">
                            <span className="font-medium text-foreground">{selected.device ?? 'Unknown device'}</span> &middot; {selected.name} &middot; {selected.role} &middot;{' '}
                            {periodLabel}
                        </p>

                        <div className="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
                            <Stat title="Average inbound" value={formatMbps(summary?.avg_in_mbps)} icon={ArrowDown} hint={`Peak ${formatMbps(summary?.peak_in_mbps)}`} />
                            <Stat title="Average outbound" value={formatMbps(summary?.avg_out_mbps)} icon={ArrowUp} hint={`Peak ${formatMbps(summary?.peak_out_mbps)}`} />
                            <Stat
                                title="Average utilization"
                                value={formatPercent(summary?.avg_utilization_percent)}
                                icon={Gauge}
                                hint={selected.capacity_basis ? `Peak ${formatPercent(summary?.peak_utilization_percent)}` : 'No capacity known'}
                            />
                            <Stat
                                title="Data transferred"
                                value={`${formatVolume(traffic.transferred?.in_mb)} in`}
                                icon={Database}
                                hint={`${formatVolume(traffic.transferred?.out_mb)} out (measured periods only)`}
                            />
                        </div>

                        <section aria-label="Traffic charts" className="rounded-xl border bg-card p-4 text-card-foreground shadow-xs">
                            {points.length === 0 ? (
                                <EmptyState
                                    icon={Gauge}
                                    title="No valid measurements in this period"
                                    description="Nothing valid was collected from this interface in the selected period. Check the data quality line below for failed polls."
                                />
                            ) : (
                                <div className={cn('grid gap-6', selected.capacity_basis && 'xl:grid-cols-2')}>
                                    {selected.capacity_basis && (
                                        <div>
                                            <h2 className="mb-1 text-sm font-medium">Utilization</h2>
                                            <UtilizationChart points={points} range={axisRange} timeZone={timeZone} thresholds={traffic.thresholds} summary={chartSummary} />
                                        </div>
                                    )}
                                    <div>
                                        <h2 className="mb-1 text-sm font-medium">Inbound and outbound traffic</h2>
                                        <TrafficChart points={points} range={axisRange} timeZone={timeZone} summary={chartSummary} />
                                    </div>
                                </div>
                            )}

                            <p className="mt-3 text-xs text-muted-foreground">
                                {capacityNote} The dashed purple line is the highest reading in each bucket. A gap in a chart means no valid measurement: the device was
                                unreachable, or the sample was a baseline or counter reset. These are interface counters, not per-user usage.
                            </p>
                        </section>

                        <p className={cn('text-sm', quality.failed > 0 ? 'text-amber-800 dark:text-amber-300' : 'text-muted-foreground')}>
                            Samples in this period: {quality.ok} valid &middot; {quality.failed} failed polls &middot; {quality.counter_reset} counter resets &middot; {quality.invalid}{' '}
                            rejected &middot; {quality.baseline} baseline
                        </p>

                        {points.length > 0 && (
                            <details className="rounded-xl border bg-card p-4 text-card-foreground shadow-xs">
                                <summary className="cursor-pointer text-sm font-medium">Data table ({points.length} rows)</summary>
                                <div className="mt-3 max-h-96 overflow-auto">
                                    <table className="w-full text-sm">
                                        <caption className="sr-only">
                                            Traffic of {selected.name}, {periodLabel}, newest first
                                        </caption>
                                        <thead className="sticky top-0 bg-card text-left text-xs text-muted-foreground">
                                            <tr>
                                                <th scope="col" className="py-1 pr-3 font-medium">Time</th>
                                                <th scope="col" className="py-1 pr-3 text-right font-medium">Inbound avg</th>
                                                <th scope="col" className="py-1 pr-3 text-right font-medium">Outbound avg</th>
                                                <th scope="col" className="py-1 pr-3 text-right font-medium">Inbound peak</th>
                                                <th scope="col" className="py-1 pr-3 text-right font-medium">Outbound peak</th>
                                                <th scope="col" className="py-1 text-right font-medium">Utilization</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            {[...points].reverse().map((point) => (
                                                <tr key={point.t} className="border-t">
                                                    <td className="py-1.5 pr-3">{formatDateTime(point.t, timeZone)}</td>
                                                    <td className="py-1.5 pr-3 text-right tabular-nums">{formatMbps(point.in_mbps)}</td>
                                                    <td className="py-1.5 pr-3 text-right tabular-nums">{formatMbps(point.out_mbps)}</td>
                                                    <td className="py-1.5 pr-3 text-right tabular-nums">{formatMbps(point.peak_in_mbps)}</td>
                                                    <td className="py-1.5 pr-3 text-right tabular-nums">{formatMbps(point.peak_out_mbps)}</td>
                                                    <td className="py-1.5 text-right tabular-nums">{formatPercent(point.utilization_percent)}</td>
                                                </tr>
                                            ))}
                                        </tbody>
                                    </table>
                                </div>
                            </details>
                        )}
                    </>
                )}
            </div>
        </AppLayout>
    );
}