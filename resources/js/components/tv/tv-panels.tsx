import { ArrowDown, ArrowUp, CircleAlert, CircleCheck, CircleHelp, CircleX, Info, Network, TriangleAlert } from 'lucide-react';
import type { ReactNode } from 'react';

import { useNow, useRotatingPage, type Connection } from '@/hooks/use-tv-data';
import { formatClock, formatMbps, formatPercent, safeTimeZone } from '@/lib/format';
import { cn } from '@/lib/utils';
import type { DeviceState, OverallState, Severity, TvAlert, TvData } from '@/types/tv-display';

export function ageText(iso: string | null | undefined, now: number): string {
    const then = iso ? Date.parse(iso) : NaN;

    if (Number.isNaN(then)) {
        return 'never';
    }

    const s = Math.max(0, Math.round((now - then) / 1000));

    if (s < 60) {
        return `${s}s ago`;
    }

    if (s < 3600) {
        return `${Math.floor(s / 60)} min ago`;
    }

    return s < 86400 ? `${Math.floor(s / 3600)} h ago` : `${Math.floor(s / 86400)} d ago`;
}

export function Glass({ className, children }: { className?: string; children: ReactNode }) {
    return (
        <section
            className={cn(
                'min-h-0 rounded-[1.5rem] border border-white/10 bg-white/[0.07] shadow-[0_0.5rem_2rem_rgba(0,0,0,0.35)] backdrop-blur-sm',
                className,
            )}
        >
            {children}
        </section>
    );
}

function PanelTitle({ children, right }: { children: ReactNode; right?: ReactNode }) {
    return (
        <div className="mb-3 flex items-center justify-between text-[1.15rem] font-semibold tracking-wide text-white/70 uppercase">
            <span>{children}</span>
            {right}
        </div>
    );
}

/* ---------- Header ---------- */

const OVERALL: Record<OverallState, { text: string; cls: string; Icon: typeof CircleCheck }> = {
    operational: { text: 'Network operational', cls: 'bg-emerald-500/20 text-emerald-200 ring-emerald-400/40', Icon: CircleCheck },
    degraded: { text: 'Network degraded', cls: 'bg-amber-500/20 text-amber-100 ring-amber-400/50', Icon: TriangleAlert },
    critical: { text: 'Network critical', cls: 'bg-red-500/25 text-red-100 ring-red-400/60', Icon: CircleX },
    unknown: { text: 'No data', cls: 'bg-zinc-500/20 text-zinc-200 ring-zinc-400/40', Icon: CircleHelp },
};

function Clock({ timeZone, skewMs }: { timeZone: string; skewMs: number }) {
    const now = useNow(1000) + skewMs;
    const tz = safeTimeZone(timeZone);
    const time = new Intl.DateTimeFormat('en-PH', { hour: '2-digit', minute: '2-digit', second: '2-digit', hour12: false, timeZone: tz }).format(now);
    const date = new Intl.DateTimeFormat('en-PH', { weekday: 'long', year: 'numeric', month: 'long', day: 'numeric', timeZone: tz }).format(now);

    return (
        <div className="text-right">
            <div className="text-[3rem] leading-none font-semibold tabular-nums">{time}</div>
            <div className="mt-1 text-[1.1rem] text-white/70">
                {date} · {tz}
            </div>
        </div>
    );
}

function Freshness({ data, lastOkAt, skewMs }: { data: TvData; lastOkAt: number | null; skewMs: number }) {
    const now = useNow(1000);
    const tz = data.timezone;

    return (
        <div className="text-right text-[1.05rem] text-white/70 tabular-nums">
            <div>
                Last collection{' '}
                <span className="text-white">
                    {data.latest_measurement_at ? formatClock(data.latest_measurement_at, tz) : '—'}
                </span>{' '}
                ({ageText(data.latest_measurement_at, now + skewMs)})
            </div>
            <div>
                Display updated{' '}
                <span className="text-white">{lastOkAt ? formatClock(new Date(lastOkAt).toISOString(), tz) : '—'}</span> (
                {lastOkAt ? ageText(new Date(lastOkAt).toISOString(), now) : 'never'})
            </div>
        </div>
    );
}

export function TvHeader({
    data,
    lastOkAt,
    skewMs,
    campusName,
    timeZone,
}: {
    data: TvData | null;
    lastOkAt: number | null;
    skewMs: number;
    campusName: string;
    timeZone: string;
}) {
    const overall = OVERALL[data?.overall ?? 'unknown'];
    const OverallIcon = overall.Icon;

    return (
        <header className="flex shrink-0 items-center justify-between gap-6">
            <div className="flex items-center gap-4">
                <div className="flex size-[4rem] items-center justify-center rounded-[1.2rem] bg-sky-500/90 shadow-lg">
                    <Network className="size-[2.2rem]" aria-hidden="true" />
                </div>
                <div>
                    <div className="text-[2.2rem] leading-tight font-semibold">CampusNet</div>
                    <div className="text-[1.2rem] text-white/70">{campusName}</div>
                </div>
            </div>

            <div className="flex items-center gap-4">
                <span className={cn('flex items-center gap-3 rounded-full px-6 py-3 text-[1.6rem] font-semibold ring-2 ring-inset', overall.cls)}>
                    <OverallIcon className="size-[1.8rem]" aria-hidden="true" />
                    {overall.text}
                </span>
                {data && (
                    <span
                        className={cn(
                            'rounded-full px-5 py-3 text-[1.3rem] font-semibold tracking-wide uppercase ring-2 ring-inset',
                            data.mode.demo
                                ? 'bg-amber-500/20 text-amber-100 ring-amber-400/50'
                                : 'bg-sky-500/15 text-sky-100 ring-sky-400/40',
                        )}
                    >
                        {data.mode.demo ? 'Demo mode · simulated data' : `Live monitoring · ${data.mode.driver.toUpperCase()}`}
                    </span>
                )}
            </div>

            <div className="flex items-center gap-8">
                {data && <Freshness data={data} lastOkAt={lastOkAt} skewMs={skewMs} />}
                <Clock timeZone={timeZone} skewMs={skewMs} />
            </div>
        </header>
    );
}

/* ---------- Status banner ---------- */

export function StatusBanner({
    connection,
    paused,
    connectionStale,
    data,
}: {
    connection: Connection;
    paused: boolean;
    connectionStale: boolean;
    data: TvData | null;
}) {
    let tone = '';
    let message: ReactNode = null;

    if (connection === 'unauthorized') {
        tone = 'bg-red-600/30 text-red-50 ring-red-400/60';
        message = (
            <>
                This display is signed out or its access was revoked. Retrying…{' '}
                <a href="/login" className="underline">
                    Sign in
                </a>{' '}
                or ask a Super Administrator for a new pairing link.
            </>
        );
    } else if (connection === 'retrying') {
        tone = 'bg-red-600/30 text-red-50 ring-red-400/60';
        message = 'Connection to CampusNet lost. Showing the last known values and retrying…';
    } else if (connectionStale) {
        tone = 'bg-amber-500/25 text-amber-50 ring-amber-400/60';
        message = 'No update received recently. The values below may be outdated.';
    } else if (paused) {
        tone = 'bg-sky-500/20 text-sky-50 ring-sky-400/50';
        message = 'Refresh is paused. The values below are not updating.';
    } else if (data && data.collector.status !== 'ok') {
        tone = 'bg-amber-500/25 text-amber-50 ring-amber-400/60';
        message = data.collector.message ?? 'The monitoring collector reports a problem.';
    } else if (data?.wan.current?.stale) {
        tone = 'bg-amber-500/25 text-amber-50 ring-amber-400/60';
        message = 'The latest bandwidth measurement is older than the stale-data limit. Values are not current.';
    }

    if (message === null) {
        return null;
    }

    return (
        <div role="status" className={cn('flex shrink-0 items-center gap-3 rounded-[1.2rem] px-6 py-3 text-[1.3rem] font-medium ring-2 ring-inset', tone)}>
            <TriangleAlert className="size-[1.6rem] shrink-0" aria-hidden="true" />
            <span>{message}</span>
        </div>
    );
}

/* ---------- KPI row ---------- */

function splitMbps(value: number | null | undefined): [string, string] {
    if (value === null || value === undefined || Number.isNaN(value)) {
        return ['—', ''];
    }

    return value >= 1000 ? [(value / 1000).toFixed(2), 'Gbps'] : [value.toFixed(1), 'Mbps'];
}

function BigNumber({ value, unit, dim }: { value: string; unit: string; dim?: boolean }) {
    return (
        <div className={cn('flex items-baseline gap-3', dim && 'opacity-60')}>
            <span className="text-[4.8rem] leading-none font-semibold tabular-nums">{value}</span>
            <span className="text-[1.8rem] text-white/70">{unit}</span>
        </div>
    );
}

function TrafficCard({ direction, data, dim }: { direction: 'in' | 'out'; data: TvData; dim: boolean }) {
    const current = data.wan.current;
    const [value, unit] = splitMbps(direction === 'in' ? current?.in_mbps : current?.out_mbps);
    const avg = direction === 'in' ? data.summary?.avg_in_mbps : data.summary?.avg_out_mbps;
    const peak = direction === 'in' ? data.summary?.peak_in_mbps : data.summary?.peak_out_mbps;
    const Icon = direction === 'in' ? ArrowDown : ArrowUp;

    return (
        <Glass className="flex flex-col justify-between p-5">
            <PanelTitle right={<Icon className="size-[1.8rem] text-sky-300" aria-hidden="true" />}>
                {direction === 'in' ? 'Download' : 'Upload'}
            </PanelTitle>
            <BigNumber value={value} unit={unit} dim={dim} />
            <div className="mt-3 text-[1.1rem] text-white/70 tabular-nums">
                {current ? (
                    <>
                        Avg {formatMbps(avg)} · Peak {formatMbps(peak)} <span className="text-white/50">(last hour)</span>
                    </>
                ) : (
                    'No measurement yet'
                )}
            </div>
        </Glass>
    );
}

function CapacityCard({ data, dim }: { data: TvData; dim: boolean }) {
    const utilization = data.wan.current?.utilization_percent ?? null;
    const [capValue, capUnit] = splitMbps(data.capacity_mbps);
    const level =
        utilization === null
            ? { text: 'No data', bar: 'bg-zinc-400' }
            : utilization >= data.thresholds.critical
              ? { text: 'Critical', bar: 'bg-red-400' }
              : utilization >= data.thresholds.warning
                ? { text: 'Warning', bar: 'bg-amber-400' }
                : { text: 'Normal', bar: 'bg-emerald-400' };

    return (
        <Glass className="flex flex-col justify-between p-5">
            <PanelTitle>Internet capacity</PanelTitle>
            <div className="flex items-baseline justify-between gap-4">
                <BigNumber value={capValue} unit={capUnit} />
                <div className={cn('text-right', dim && 'opacity-60')}>
                    <div className="text-[2.6rem] leading-none font-semibold tabular-nums">{formatPercent(utilization)}</div>
                    <div className="text-[1.1rem] text-white/70">utilization · {level.text}</div>
                </div>
            </div>
            <div className="mt-3 h-[0.9rem] overflow-hidden rounded-full bg-white/10" role="meter" aria-valuenow={utilization ?? 0} aria-valuemin={0} aria-valuemax={100}>
                <div className={cn('h-full rounded-full', level.bar)} style={{ width: `${Math.min(100, utilization ?? 0)}%` }} />
            </div>
        </Glass>
    );
}

function AvailabilityCard({ data }: { data: TvData }) {
    const { total, online, unreachable } = data.devices;
    const pct = total > 0 ? (online / total) * 100 : null;

    return (
        <Glass className="flex flex-col justify-between p-5">
            <PanelTitle>Device availability</PanelTitle>
            <div className="flex items-baseline gap-3">
                <span className="text-[4.8rem] leading-none font-semibold tabular-nums">
                    {online}
                    <span className="text-white/50">/{total}</span>
                </span>
                <span className="text-[1.8rem] text-white/70">online</span>
            </div>
            <div className="mt-3 text-[1.1rem] text-white/70 tabular-nums">
                {pct === null ? 'No devices monitored' : `${pct.toFixed(0)}% available`} ·{' '}
                <span className={unreachable > 0 ? 'font-semibold text-red-300' : ''}>{unreachable} unreachable</span>
            </div>
        </Glass>
    );
}

export function KpiRow({ data, dim }: { data: TvData; dim: boolean }) {
    const stale = dim || (data.wan.current?.stale ?? false);

    return (
        <div className="grid shrink-0 grid-cols-4 gap-4">
            <TrafficCard direction="in" data={data} dim={stale} />
            <TrafficCard direction="out" data={data} dim={stale} />
            <CapacityCard data={data} dim={stale} />
            <AvailabilityCard data={data} />
        </div>
    );
}

/* ---------- Bottom panels ---------- */

const DEVICE_STATE: Record<DeviceState, { text: string; cls: string; Icon: typeof CircleCheck; rank: number }> = {
    unreachable: { text: 'Unreachable', cls: 'text-red-300', Icon: CircleX, rank: 0 },
    unknown: { text: 'Unknown', cls: 'text-zinc-300', Icon: CircleHelp, rank: 1 },
    online: { text: 'Online', cls: 'text-emerald-300', Icon: CircleCheck, rank: 2 },
};

export function DevicesPanel({ data, skewMs }: { data: TvData; skewMs: number }) {
    const now = useNow(5000) + skewMs;
    const sorted = [...data.devices.list].sort(
        (a, b) => DEVICE_STATE[a.status].rank - DEVICE_STATE[b.status].rank || a.name.localeCompare(b.name),
    );
    const PAGE = 6;
    const { page, pages } = useRotatingPage(sorted.length, PAGE);
    const rows = sorted.slice(page * PAGE, page * PAGE + PAGE);

    return (
        <Glass className="flex flex-col overflow-hidden p-5">
            <PanelTitle right={pages > 1 ? <span className="text-white/50">page {page + 1}/{pages}</span> : undefined}>
                Network devices · {data.devices.online}/{data.devices.total} online
            </PanelTitle>
            {rows.length === 0 ? (
                <div className="text-[1.2rem] text-white/60">No devices are being monitored.</div>
            ) : (
                <ul className="min-h-0 flex-1 divide-y divide-white/10 overflow-hidden">
                    {rows.map((device) => {
                        const state = DEVICE_STATE[device.status];

                        return (
                            <li key={device.id} className="flex items-center justify-between gap-4 py-[0.55rem] text-[1.15rem]">
                                <div className="min-w-0">
                                    <div className="truncate font-semibold">{device.name}</div>
                                    <div className="truncate text-[0.95rem] text-white/60">
                                        {device.type}
                                        {device.location ? ` · ${device.location}` : ''}
                                    </div>
                                </div>
                                <div className="shrink-0 text-right">
                                    <div className={cn('flex items-center justify-end gap-2 font-semibold', state.cls)}>
                                        <state.Icon className="size-[1.2rem]" aria-hidden="true" />
                                        {state.text}
                                    </div>
                                    <div className="text-[0.95rem] text-white/60">{ageText(device.last_successful_poll_at, now)}</div>
                                </div>
                            </li>
                        );
                    })}
                </ul>
            )}
        </Glass>
    );
}

const SEVERITY: Record<Severity, { text: string; cls: string; Icon: typeof CircleCheck }> = {
    critical: { text: 'Critical', cls: 'text-red-300', Icon: CircleAlert },
    warning: { text: 'Warning', cls: 'text-amber-300', Icon: TriangleAlert },
    info: { text: 'Info', cls: 'text-sky-300', Icon: Info },
};

function Count({ label, value, tone }: { label: string; value: number; tone: string }) {
    return (
        <div className="rounded-[1rem] bg-white/[0.06] px-3 py-2 text-center">
            <div className={cn('text-[2rem] leading-none font-semibold tabular-nums', value > 0 && tone)}>{value}</div>
            <div className="mt-1 text-[0.85rem] text-white/60">{label}</div>
        </div>
    );
}

export function AlertsPanel({ data, skewMs }: { data: TvData; skewMs: number }) {
    const now = useNow(5000) + skewMs;
    const { alerts } = data;
    const top = [...alerts.list].sort((a, b) => SEVERITY_RANK[b.severity] - SEVERITY_RANK[a.severity]).slice(0, 3);

    return (
        <Glass className="flex flex-col overflow-hidden p-5">
            <PanelTitle>Active alerts · {alerts.active}</PanelTitle>
            <div className="mb-3 grid grid-cols-4 gap-2">
                <Count label="Critical" value={alerts.critical} tone="text-red-300" />
                <Count label="Warning" value={alerts.warning} tone="text-amber-300" />
                <Count label="Unreachable" value={alerts.unreachable} tone="text-red-300" />
                <Count label="Collector" value={alerts.collector_failures} tone="text-amber-300" />
            </div>
            {top.length === 0 ? (
                <div className="flex items-center gap-2 text-[1.2rem] text-emerald-300">
                    <CircleCheck className="size-[1.4rem]" aria-hidden="true" /> No active alerts
                </div>
            ) : (
                <ul className="min-h-0 flex-1 space-y-2 overflow-hidden">
                    {top.map((alert) => (
                        <AlertRow key={alert.id} alert={alert} now={now} />
                    ))}
                    {alerts.list.length > top.length && (
                        <li className="text-[0.95rem] text-white/60">+{alerts.list.length - top.length} more</li>
                    )}
                </ul>
            )}
        </Glass>
    );
}

const SEVERITY_RANK: Record<Severity, number> = { info: 1, warning: 2, critical: 3 };

function AlertRow({ alert, now }: { alert: TvAlert; now: number }) {
    const severity = SEVERITY[alert.severity];

    return (
        <li className="flex items-start gap-3 text-[1.1rem]">
            <severity.Icon className={cn('mt-1 size-[1.3rem] shrink-0', severity.cls)} aria-hidden="true" />
            <div className="min-w-0 flex-1">
                <div className="truncate font-semibold">
                    <span className={severity.cls}>{severity.text}</span> · {alert.title}
                </div>
                <div className="truncate text-[0.95rem] text-white/60">
                    {alert.device ?? 'Network-wide'} · {ageText(alert.triggered_at, now)}
                </div>
            </div>
        </li>
    );
}

const SERVICE_LABEL: Record<TvData['collector']['status'], { text: string; ok: boolean }> = {
    ok: { text: 'Operational', ok: true },
    stale: { text: 'Data stale', ok: false },
    no_data: { text: 'No data yet', ok: false },
    no_devices: { text: 'No devices', ok: false },
    misconfigured: { text: 'Misconfigured driver', ok: false },
};

function HealthRow({ label, value, ok, sub }: { label: string; value: string; ok?: boolean; sub?: string }) {
    return (
        <div className="flex items-center justify-between gap-4 py-[0.5rem] text-[1.15rem]">
            <span className="text-white/70">{label}</span>
            <span className="text-right">
                <span className={cn('flex items-center justify-end gap-2 font-semibold', ok === undefined ? '' : ok ? 'text-emerald-300' : 'text-amber-300')}>
                    {ok === undefined ? null : ok ? <CircleCheck className="size-[1.2rem]" aria-hidden="true" /> : <TriangleAlert className="size-[1.2rem]" aria-hidden="true" />}
                    {value}
                </span>
                {sub && <span className="block text-[0.9rem] text-white/60">{sub}</span>}
            </span>
        </div>
    );
}

export function HealthPanel({
    data,
    connection,
    skewMs,
}: {
    data: TvData;
    connection: Connection;
    skewMs: number;
}) {
    const now = useNow(5000) + skewMs;
    const service = SERVICE_LABEL[data.collector.status];

    return (
        <Glass className="flex flex-col overflow-hidden p-5">
            <PanelTitle>Network health</PanelTitle>
            <div className="divide-y divide-white/10">
                <HealthRow label="Monitoring service" value={service.text} ok={service.ok} />
                <HealthRow
                    label="Last successful poll"
                    value={data.latest_measurement_at ? formatClock(data.latest_measurement_at, data.timezone) : '—'}
                    sub={ageText(data.latest_measurement_at, now)}
                />
                <HealthRow
                    label="Data freshness"
                    value={data.wan.current ? (data.wan.current.stale ? 'Stale' : 'Fresh') : 'No data'}
                    ok={data.wan.current ? !data.wan.current.stale : false}
                />
                <HealthRow
                    label="Monitoring driver"
                    value={data.mode.demo ? 'Demo (simulated)' : `Live · ${data.mode.driver.toUpperCase()}`}
                    ok={data.collector.status !== 'misconfigured'}
                />
                <HealthRow
                    label="Display link"
                    value={connection === 'live' ? 'Connected' : connection === 'retrying' ? 'Reconnecting' : 'Not authorized'}
                    ok={connection === 'live'}
                />
            </div>
        </Glass>
    );
}

export function EventsPanel({ data, skewMs }: { data: TvData; skewMs: number }) {
    const now = useNow(5000) + skewMs;
    const events = data.events.slice(0, 5);

    return (
        <Glass className="flex flex-col overflow-hidden p-5">
            <PanelTitle>Latest events</PanelTitle>
            {events.length === 0 ? (
                <div className="text-[1.2rem] text-white/60">No events recorded.</div>
            ) : (
                <ul className="min-h-0 flex-1 space-y-2 overflow-hidden">
                    {events.map((event) => {
                        const severity = SEVERITY[event.severity];

                        return (
                            <li key={event.id} className="flex items-start gap-3 text-[1.05rem]">
                                <severity.Icon className={cn('mt-1 size-[1.2rem] shrink-0', severity.cls)} aria-hidden="true" />
                                <div className="min-w-0 flex-1">
                                    <div className="truncate font-semibold">{event.title}</div>
                                    <div className="truncate text-[0.9rem] text-white/60">
                                        {event.device ?? 'Network-wide'} · {ageText(event.triggered_at, now)}
                                        {event.status === 'resolved' ? ' · resolved' : ''}
                                    </div>
                                </div>
                            </li>
                        );
                    })}
                </ul>
            )}
        </Glass>
    );
}