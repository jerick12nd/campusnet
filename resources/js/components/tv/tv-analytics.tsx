import { ArrowDown, ArrowUp, CalendarClock, Gauge, TrendingUp, TriangleAlert } from 'lucide-react';
import type { ReactNode } from 'react';

import { Glass } from '@/components/tv/tv-panels';
import { formatDateTime, formatMbps, formatPercent, formatVolume, safeTimeZone } from '@/lib/format';
import { cn } from '@/lib/utils';
import type { TvData } from '@/types/tv-display';

type Analytics = NonNullable<TvData['analytics']>;
type Bar = { label: string; value: number | null };

function Title({ children, right }: { children: ReactNode; right?: ReactNode }) {
    return (
        <div className="mb-2 flex items-center justify-between text-[1.1rem] font-semibold tracking-wide text-white/70 uppercase">
            <span>{children}</span>
            {right}
        </div>
    );
}

function Notice({ children }: { children: ReactNode }) {
    return <Glass className="flex flex-1 items-center justify-center p-8 text-center text-[1.8rem] text-white/70">{children}</Glass>;
}

function Tile({ title, value, hint, icon: Icon, dim }: { title: string; value: string; hint?: string; icon: typeof Gauge; dim: boolean }) {
    return (
        <Glass className="flex flex-col justify-between p-4">
            <div className="flex items-center justify-between text-[1rem] font-semibold tracking-wide text-white/70 uppercase">
                <span className="truncate">{title}</span>
                <Icon className="size-[1.4rem] shrink-0 text-sky-300" aria-hidden="true" />
            </div>
            <div className={cn('mt-1 text-[3rem] leading-none font-semibold tabular-nums', dim && 'opacity-60')}>{value}</div>
            <div className="mt-2 truncate text-[0.95rem] text-white/60">{hint ?? '\u00a0'}</div>
        </Glass>
    );
}

/** Bars for a percentage per label. Colour follows the warning and critical thresholds. */
function UtilBars({
    data,
    warning,
    critical,
    labelEvery = 1,
    showValues = false,
}: {
    data: Bar[];
    warning: number;
    critical: number;
    labelEvery?: number;
    showValues?: boolean;
}) {
    const max = Math.max(0, ...data.map((item) => item.value ?? 0));
    // Zoom the scale to the data so a quiet campus is still readable; never above 100 %.
    const scaleMax = Math.min(100, Math.max(10, max * 1.2));
    const warnAt = warning <= scaleMax ? (warning / scaleMax) * 100 : null;

    const tone = (value: number) => (value >= critical ? 'bg-red-400' : value >= warning ? 'bg-amber-400' : 'bg-sky-400');

    return (
        <div className="flex h-full min-h-0 flex-col">
            <div className="relative mt-[1.6rem] min-h-0 flex-1 border-b border-white/20">
                {warnAt !== null && (
                    <div
                        className="pointer-events-none absolute inset-x-0 border-t-2 border-dashed border-amber-300/60"
                        style={{ bottom: `${warnAt}%` }}
                    />
                )}
                <div className="absolute inset-0 flex items-end gap-[0.3rem]">
                    {data.map((item) => (
                        <div key={item.label} className="relative flex h-full flex-1 items-end">
                            {item.value === null ? (
                                <div className="h-[0.2rem] w-full rounded-full bg-white/20" title="Nothing measured" />
                            ) : (
                                <div
                                    className={cn('relative w-full rounded-t-[0.3rem]', tone(item.value))}
                                    style={{ height: `${Math.max(1, (item.value / scaleMax) * 100)}%` }}
                                >
                                    {showValues && (
                                        <span className="absolute -top-6 left-1/2 -translate-x-1/2 text-[1rem] font-semibold whitespace-nowrap tabular-nums">
                                            {formatPercent(item.value)}
                                        </span>
                                    )}
                                </div>
                            )}
                        </div>
                    ))}
                </div>
            </div>
            <div className="mt-1 flex gap-[0.3rem] text-[0.85rem] text-white/60">
                {data.map((item, index) => (
                    <div key={item.label} className="flex-1 text-center">
                        {index % labelEvery === 0 ? item.label : ''}
                    </div>
                ))}
            </div>
            <div className="mt-1 text-right text-[0.8rem] text-white/40">scale 0–{Math.round(scaleMax)}%</div>
        </div>
    );
}

/** Download and upload volume per day, as two bars per day. */
function DailyBars({ data }: { data: Analytics['daily'] }) {
    if (data.length === 0) {
        return <div className="flex flex-1 items-center justify-center text-[1.2rem] text-white/60">No volume recorded.</div>;
    }

    const max = Math.max(0, ...data.flatMap((day) => [day.in_mb, day.out_mb])) || 1;

    return (
        <div className="flex h-full min-h-0 flex-col">
            <div className="relative mt-[1.6rem] min-h-0 flex-1 border-b border-white/20">
                <div className="absolute inset-0 flex items-end gap-[0.6rem]">
                    {data.map((day) => (
                        <div key={day.date} className="flex h-full flex-1 items-end gap-[0.2rem]">
                            <div className="w-full rounded-t-[0.3rem] bg-sky-400" style={{ height: `${Math.max(1, (day.in_mb / max) * 100)}%` }} />
                            <div className="w-full rounded-t-[0.3rem] bg-emerald-400" style={{ height: `${Math.max(1, (day.out_mb / max) * 100)}%` }} />
                        </div>
                    ))}
                </div>
            </div>
            <div className="mt-1 flex gap-[0.6rem] text-[0.85rem] text-white/60">
                {data.map((day) => (
                    <div key={day.date} className="flex-1 text-center">
                        <div>{day.date.slice(5)}</div>
                        <div className="text-white/80 tabular-nums">{formatVolume(day.in_mb + day.out_mb)}</div>
                    </div>
                ))}
            </div>
            <div className="mt-1 flex justify-end gap-4 text-[0.85rem] text-white/60">
                <span className="flex items-center gap-1.5">
                    <span className="size-[0.7rem] rounded-sm bg-sky-400" /> Download
                </span>
                <span className="flex items-center gap-1.5">
                    <span className="size-[0.7rem] rounded-sm bg-emerald-400" /> Upload
                </span>
            </div>
        </div>
    );
}

const hourText = (hour: number) => `${String(hour).padStart(2, '0')}:00`;

export function TvAnalytics({ data, dim }: { data: TvData; dim: boolean }) {
    const analytics = data.analytics;
    const timeZone = safeTimeZone(data.timezone);

    if (analytics === null) {
        return <Notice>Analytics are temporarily unavailable.</Notice>;
    }

    if (!analytics.wan.configured) {
        return <Notice>No WAN interface is set, so there are no analytics to show yet.</Notice>;
    }

    const { summary, busiest } = analytics;

    if (!analytics.has_data || summary === null) {
        return <Notice>No valid measurements in the last {analytics.days} days yet.</Notice>;
    }

    const { warning, critical } = data.thresholds;
    const change = summary.previous_avg_utilization_percent !== null ? summary.avg_utilization_percent - summary.previous_avg_utilization_percent : null;
    const changeHint =
        change === null ? 'No earlier period to compare with' : `${change >= 0 ? '+' : '\u2212'}${Math.abs(change).toFixed(1)} pts vs the ${analytics.days} days before`;

    const hourly: Bar[] = analytics.hourly.map((item) => ({ label: String(item.hour).padStart(2, '0'), value: item.avg_utilization_percent }));
    const weekday: Bar[] = analytics.weekday.map((item) => ({ label: item.label, value: item.avg_utilization_percent }));
    const topInterfaces = analytics.top_interfaces.slice(0, 5);
    const sourceText = data.mode.demo ? 'Simulated demo data' : `Live ${data.mode.driver.toUpperCase()} data`;

    return (
        <>
            <div className="grid shrink-0 grid-cols-6 gap-4">
                <Tile title="Avg utilization" value={formatPercent(summary.avg_utilization_percent)} hint={changeHint} icon={Gauge} dim={dim} />
                <Tile title="95th percentile" value={formatPercent(summary.p95_utilization_percent)} hint="95% of samples at or below" icon={TrendingUp} dim={dim} />
                <Tile
                    title="Highest"
                    value={formatPercent(summary.peak_utilization_percent)}
                    hint={summary.peak_at ? formatDateTime(summary.peak_at, timeZone) : undefined}
                    icon={CalendarClock}
                    dim={dim}
                />
                <Tile
                    title={`Above ${warning}%`}
                    value={formatPercent(summary.above_warning_percent)}
                    hint={`${formatPercent(summary.above_critical_percent)} at or above ${critical}%`}
                    icon={TriangleAlert}
                    dim={dim}
                />
                <Tile title="Downloaded" value={formatVolume(summary.transferred.in_mb)} hint={`Last ${analytics.days} days`} icon={ArrowDown} dim={dim} />
                <Tile title="Uploaded" value={formatVolume(summary.transferred.out_mb)} hint={`Last ${analytics.days} days`} icon={ArrowUp} dim={dim} />
            </div>

            <div className="grid min-h-0 flex-5 grid-cols-[1.5fr_1fr_1fr] gap-4">
                <Glass className="flex min-h-0 flex-col p-5">
                    <Title>Utilization by hour of day</Title>
                    <div className="min-h-0 flex-1">
                        <UtilBars data={hourly} warning={warning} critical={critical} labelEvery={3} />
                    </div>
                </Glass>
                <Glass className="flex min-h-0 flex-col p-5">
                    <Title>By day of week</Title>
                    <div className="min-h-0 flex-1">
                        <UtilBars data={weekday} warning={warning} critical={critical} showValues />
                    </div>
                </Glass>
                <Glass className="flex min-h-0 flex-col p-5">
                    <Title>Data per day</Title>
                    <div className="min-h-0 flex-1">
                        <DailyBars data={analytics.daily} />
                    </div>
                </Glass>
            </div>

            <div className="grid min-h-0 flex-3 grid-cols-[2fr_1fr] gap-4">
                <Glass className="flex min-h-0 flex-col overflow-hidden p-5">
                    <Title>Busiest interfaces by data volume</Title>
                    {topInterfaces.length === 0 ? (
                        <div className="text-[1.2rem] text-white/60">No interface carried measurable traffic.</div>
                    ) : (
                        <table className="w-full text-[1.05rem]">
                            <thead className="text-left text-[0.85rem] text-white/50 uppercase">
                                <tr>
                                    <th className="py-1 font-medium">Interface</th>
                                    <th className="py-1 font-medium">Role</th>
                                    <th className="py-1 text-right font-medium">Total</th>
                                    <th className="py-1 text-right font-medium">Avg rate</th>
                                    <th className="py-1 text-right font-medium">Peak</th>
                                </tr>
                            </thead>
                            <tbody className="divide-y divide-white/10">
                                {topInterfaces.map((item) => (
                                    <tr key={item.id}>
                                        <td className="max-w-[18rem] py-[0.35rem]">
                                            <div className="truncate font-semibold">{item.name}</div>
                                            <div className="truncate text-[0.85rem] text-white/60">{item.device ?? 'Unknown device'}</div>
                                        </td>
                                        <td className="py-[0.35rem] text-white/80">{item.role ?? '\u2014'}</td>
                                        <td className="py-[0.35rem] text-right font-semibold tabular-nums">{formatVolume(item.total_mb)}</td>
                                        <td className="py-[0.35rem] text-right tabular-nums">{formatMbps(item.avg_total_mbps)}</td>
                                        <td className="py-[0.35rem] text-right tabular-nums">{formatMbps(item.peak_mbps)}</td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    )}
                </Glass>

                <Glass className="flex min-h-0 flex-col justify-between overflow-hidden p-5">
                    <div>
                        <Title>When is the link busy?</Title>
                        <div className="space-y-2 text-[1.2rem]">
                            <div>
                                <span className="text-white/60">Busiest hour </span>
                                <span className="font-semibold">
                                    {busiest.hour !== null ? `${hourText(busiest.hour)}\u2013${hourText((busiest.hour + 1) % 24)}` : '\u2014'}
                                </span>
                                <span className="block text-[0.95rem] text-white/60">average {formatPercent(busiest.hour_avg)}</span>
                            </div>
                            <div>
                                <span className="text-white/60">Busiest day </span>
                                <span className="font-semibold">{busiest.day ?? '\u2014'}</span>
                                <span className="block text-[0.95rem] text-white/60">average {formatPercent(busiest.day_avg)}</span>
                            </div>
                        </div>
                    </div>
                    <p className="text-[0.85rem] text-white/50">
                        {sourceText} · last {analytics.days} days · {summary.sample_count} samples · times in {timeZone}. Dashed line = warning threshold ({warning}%).
                    </p>
                </Glass>
            </div>
        </>
    );
}