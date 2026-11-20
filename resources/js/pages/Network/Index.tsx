import { Head, router, usePage } from '@inertiajs/react';
import { Activity, Globe, RefreshCw, Router, Radar } from 'lucide-react';
import { useCallback, useEffect, useState } from 'react';

import { EmptyState } from '@/components/empty-state';
import AppLayout from '@/layouts/app-layout';
import { formatDateTime, safeTimeZone, timeAgo } from '@/lib/format';
import { cn } from '@/lib/utils';
import type { CampusNetShared } from '@/types/campusnet';

interface LinkStatus {
    target: string;
    label: string;
    isUp: boolean;
    stale: boolean;
    lossPercent: number;
    avgMs: number | null;
    maxMs: number | null;
    checkedAt: string;
    uptime24h: number;
    avgMs24h: number | null;
    peakMs24h: number | null;
    checks24h: number;
}

interface HistoryPoint {
    t: string;
    avg: number | null;
    max: number | null;
    loss: number;
}

interface OfficeStatus {
    office: string;
    cidr: string;
    online: number;
    randomMac: number;
    peak24h: number;
    average24h: number;
    scannedAt: string;
}

interface Props {
    offices: OfficeStatus[];
    links: LinkStatus[];
    history: Record<string, HistoryPoint[]>;
}

const REFRESH_SECONDS = 30;

function StatusBadge({ link }: { link: LinkStatus }) {
    if (link.stale) {
        return <span className="rounded-full bg-amber-100 px-2 py-0.5 text-xs font-medium text-amber-900 dark:bg-amber-950 dark:text-amber-200">No recent check</span>;
    }

    return link.isUp ? (
        <span className="rounded-full bg-emerald-100 px-2 py-0.5 text-xs font-medium text-emerald-900 dark:bg-emerald-950 dark:text-emerald-200">Up</span>
    ) : (
        <span className="rounded-full bg-red-100 px-2 py-0.5 text-xs font-medium text-red-900 dark:bg-red-950 dark:text-red-200">Down</span>
    );
}

function LinkCard({ link }: { link: LinkStatus }) {
    const Icon = link.target === '8.8.8.8' ? Globe : Router;

    return (
        <section aria-label={link.label} className="rounded-xl border bg-card p-4 text-card-foreground shadow-xs">
            <div className="flex items-center justify-between gap-2">
                <div className="flex min-w-0 items-center gap-2">
                    <Icon className="size-4 shrink-0 text-muted-foreground" aria-hidden="true" />
                    <h3 className="truncate text-sm font-medium">{link.label}</h3>
                </div>
                <StatusBadge link={link} />
            </div>
            <p className="mt-1 text-xs text-muted-foreground">{link.target}</p>

            <dl className="mt-3 grid grid-cols-3 gap-2 text-sm">
                <div>
                    <dt className="text-xs text-muted-foreground">Latency now</dt>
                    <dd className="text-lg font-semibold tabular-nums">{link.avgMs === null ? '-' : `${link.avgMs} ms`}</dd>
                </div>
                <div>
                    <dt className="text-xs text-muted-foreground">Packet loss</dt>
                    <dd className="text-lg font-semibold tabular-nums">{link.lossPercent}%</dd>
                </div>
                <div>
                    <dt className="text-xs text-muted-foreground">Uptime 24h</dt>
                    <dd className="text-lg font-semibold tabular-nums">{link.uptime24h}%</dd>
                </div>
            </dl>

            <p className="mt-3 text-xs text-muted-foreground">
                24h average {link.avgMs24h === null ? '-' : `${link.avgMs24h} ms`}, peak {link.peakMs24h === null ? '-' : `${link.peakMs24h} ms`}. Checked {timeAgo(link.checkedAt)}.
            </p>
        </section>
    );
}

/** Small dependency-free SVG chart: solid line = average latency, dashed = highest reading, red dot = packet loss. */
function LatencyChart({ points, label }: { points: HistoryPoint[]; label: string }) {
    const valid = points.filter((p) => p.avg !== null);

    if (valid.length < 2) {
        return <p className="text-sm text-muted-foreground">Not enough data yet. At least two 10-minute buckets are needed.</p>;
    }

    const W = 600;
    const H = 170;
    const pad = { l: 40, r: 10, t: 10, b: 22 };
    const end = Date.now();
    const start = end - 24 * 3600 * 1000;
    const yMax = Math.max(10, ...valid.map((p) => p.max ?? p.avg ?? 0));
    const top = Math.ceil((yMax * 1.1) / 10) * 10;

    const x = (iso: string) => {
        const ratio = (new Date(iso).getTime() - start) / (end - start);

        return pad.l + Math.min(1, Math.max(0, ratio)) * (W - pad.l - pad.r);
    };
    const y = (value: number) => pad.t + (1 - value / top) * (H - pad.t - pad.b);

    const avgPath = valid.map((p, i) => `${i === 0 ? 'M' : 'L'}${x(p.t).toFixed(1)},${y(p.avg as number).toFixed(1)}`).join(' ');
    const maxPath = valid
        .filter((p) => p.max !== null)
        .map((p, i) => `${i === 0 ? 'M' : 'L'}${x(p.t).toFixed(1)},${y(p.max as number).toFixed(1)}`)
        .join(' ');

    const ticks = [0, top / 2, top];

    return (
        <svg viewBox={`0 0 ${W} ${H}`} role="img" aria-label={label} className="h-auto w-full">
            {ticks.map((tick) => (
                <g key={tick}>
                    <line x1={pad.l} x2={W - pad.r} y1={y(tick)} y2={y(tick)} className="stroke-border" strokeWidth={1} />
                    <text x={pad.l - 6} y={y(tick) + 4} textAnchor="end" className="fill-muted-foreground text-[10px]">
                        {Math.round(tick)} ms
                    </text>
                </g>
            ))}

            <path d={maxPath} fill="none" className="stroke-violet-500" strokeWidth={1} strokeDasharray="4 3" />
            <path d={avgPath} fill="none" className="stroke-emerald-500" strokeWidth={2} strokeLinejoin="round" />

            {valid
                .filter((p) => p.loss > 0)
                .map((p) => (
                    <circle key={p.t} cx={x(p.t)} cy={y(p.avg as number)} r={3.5} className="fill-red-500" />
                ))}

            <text x={pad.l} y={H - 5} className="fill-muted-foreground text-[10px]">-24h</text>
            <text x={(W + pad.l) / 2} y={H - 5} textAnchor="middle" className="fill-muted-foreground text-[10px]">-12h</text>
            <text x={W - pad.r} y={H - 5} textAnchor="end" className="fill-muted-foreground text-[10px]">now</text>
        </svg>
    );
}

export default function Index({ offices, links, history }: Props) {
    const { campusnet } = usePage<CampusNetShared>().props;
    const timeZone = safeTimeZone(campusnet?.timezone);
    const [refreshing, setRefreshing] = useState(false);

    const reload = useCallback(() => {
        router.reload({
            only: ['offices', 'links', 'history'],
            onStart: () => setRefreshing(true),
            onFinish: () => setRefreshing(false),
        });
    }, []);

    // Refresh only while the tab is visible.
    useEffect(() => {
        const id = window.setInterval(() => {
            if (document.visibilityState === 'visible') {
                reload();
            }
        }, REFRESH_SECONDS * 1000);

        return () => window.clearInterval(id);
    }, [reload]);

    return (
        <AppLayout breadcrumbs={[{ title: 'Online Devices', href: '/network' }]}>
            <Head title="Online Devices" />

            <div className="flex flex-col gap-6 p-4">
                <div className="flex flex-wrap items-center justify-between gap-3">
                    <div>
                        <h1 className="text-xl font-semibold">Network status</h1>
                        <p className="text-sm text-muted-foreground">
                            Real ping and network scan results. This page does not show bandwidth usage; that needs router access.
                        </p>
                    </div>
                    <button
                        type="button"
                        onClick={reload}
                        aria-label="Refresh"
                        className="inline-flex size-9 items-center justify-center rounded-md border hover:bg-muted focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
                    >
                        <RefreshCw className={cn('size-4', refreshing && 'animate-spin')} aria-hidden="true" />
                    </button>
                </div>

                <section aria-label="Link status" className="flex flex-col gap-4">
                    <h2 className="text-base font-semibold">Router and internet</h2>

                    {links.length === 0 ? (
                        <EmptyState
                            icon={Activity}
                            title="No ping data yet"
                            description="Run php artisan network:ping, or start the scheduler (php artisan schedule:work)."
                        />
                    ) : (
                        <>
                            <div className="grid gap-4 md:grid-cols-2">
                                {links.map((link) => (
                                    <LinkCard key={link.target} link={link} />
                                ))}
                            </div>

                            <div className="grid gap-4 xl:grid-cols-2">
                                {links.map((link) => (
                                    <div key={link.target} className="rounded-xl border bg-card p-4 text-card-foreground shadow-xs">
                                        <h3 className="mb-2 text-sm font-medium">Latency over the last 24 hours: {link.label}</h3>
                                        <LatencyChart points={history[link.target] ?? []} label={`Latency of ${link.label}, last 24 hours`} />
                                    </div>
                                ))}
                            </div>

                            <p className="text-xs text-muted-foreground">
                                Solid green = average latency per 10 minutes. Dashed violet = highest reading. Red dot = packet loss occurred.
                            </p>
                        </>
                    )}
                </section>

                <section aria-label="Online devices per office" className="flex flex-col gap-3">
                    <h2 className="text-base font-semibold">Online devices per office</h2>

                    {offices.length === 0 ? (
                        <EmptyState icon={Radar} title="No scan yet" description="Run php artisan network:scan." />
                    ) : (
                        <div className="overflow-x-auto rounded-xl border bg-card p-4 text-card-foreground shadow-xs">
                            <table className="w-full text-left text-sm">
                                <caption className="sr-only">Online devices per office, from the latest scan</caption>
                                <thead className="text-xs text-muted-foreground">
                                    <tr>
                                        <th scope="col" className="py-1 pr-3 font-medium">Office</th>
                                        <th scope="col" className="py-1 pr-3 font-medium">Subnet</th>
                                        <th scope="col" className="py-1 pr-3 text-right font-medium">Online</th>
                                        <th scope="col" className="py-1 pr-3 text-right font-medium">Phones (random MAC)</th>
                                        <th scope="col" className="py-1 pr-3 text-right font-medium">Peak 24h</th>
                                        <th scope="col" className="py-1 pr-3 text-right font-medium">Average 24h</th>
                                        <th scope="col" className="py-1 font-medium">Last scan</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    {offices.map((o) => (
                                        <tr key={o.office} className="border-t">
                                            <td className="py-2 pr-3 font-medium">{o.office}</td>
                                            <td className="py-2 pr-3">{o.cidr}</td>
                                            <td className="py-2 pr-3 text-right tabular-nums">{o.online}</td>
                                            <td className="py-2 pr-3 text-right tabular-nums">{o.randomMac}</td>
                                            <td className="py-2 pr-3 text-right tabular-nums">{o.peak24h}</td>
                                            <td className="py-2 pr-3 text-right tabular-nums">{o.average24h}</td>
                                            <td className="py-2">{formatDateTime(o.scannedAt, timeZone)}</td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        </div>
                    )}

                    <p className="text-xs text-muted-foreground">
                        The numbers show how many devices are online, not who they are or how much bandwidth they use. Refreshes every {REFRESH_SECONDS} seconds while the tab is visible.
                    </p>
                </section>
            </div>
        </AppLayout>
    );
}