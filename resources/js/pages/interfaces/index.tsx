import { Head, Link, router } from '@inertiajs/react';
import { CircleCheck, CircleHelp, CircleX, History, Network, Search } from 'lucide-react';
import { useEffect, useRef, useState } from 'react';

import { EmptyState } from '@/components/empty-state';
import { FlashMessages } from '@/components/flash-messages';
import { DemoBadge, Pill } from '@/components/status-badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import AppLayout from '@/layouts/app-layout';
import { formatMbps, timeAgo } from '@/lib/format';
import { cn } from '@/lib/utils';
import type { InterfaceFilters, InterfaceListRow, InterfaceStats, Option, Paginated } from '@/types/campusnet';

type Props = {
    interfaces: Paginated<InterfaceListRow>;
    filters: InterfaceFilters;
    stats: InterfaceStats;
    devices: { id: number; name: string }[];
    options: { roles: Option[] };
    can: { manage: boolean };
};

const SELECT =
    'border-input bg-background h-9 rounded-md border px-2 text-sm shadow-xs outline-none focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50';

const RELOAD = ['interfaces', 'filters', 'stats'];

function formatSpeed(speedMbps: number | null): string {
    if (speedMbps === null) {
        return '\u2014';
    }

    return speedMbps >= 1000 ? `${speedMbps / 1000} Gbps` : `${speedMbps} Mbps`;
}

/** Status is always shown with an icon and text, never by colour alone. */
function OperStatus({ value }: { value: string | null }) {
    if (value === 'up') {
        return (
            <Pill tone="green">
                <CircleCheck className="size-3" aria-hidden="true" />
                Up
            </Pill>
        );
    }

    if (value === 'down') {
        return (
            <Pill tone="red">
                <CircleX className="size-3" aria-hidden="true" />
                Down
            </Pill>
        );
    }

    return (
        <Pill tone="gray">
            <CircleHelp className="size-3" aria-hidden="true" />
            {value ? value.charAt(0).toUpperCase() + value.slice(1) : 'Unknown'}
        </Pill>
    );
}

export default function InterfacesIndex({ interfaces, filters, stats, devices, options, can }: Props) {
    const [search, setSearch] = useState(filters.search);
    const first = useRef(true);

    const visit = (changes: Partial<InterfaceFilters & { page: number }>) => {
        const next = { ...filters, ...changes };
        const query: Record<string, string | number> = {};

        (['search', 'device', 'role', 'monitored', 'status'] as const).forEach((key) => {
            if (next[key]) {
                query[key] = next[key];
            }
        });

        if (changes.page && changes.page > 1) {
            query.page = changes.page;
        }

        router.get('/interfaces', query, { preserveState: true, preserveScroll: true, replace: true, only: RELOAD });
    };

    // Search as you type, after a short pause.
    useEffect(() => {
        if (first.current) {
            first.current = false;

            return;
        }

        if (search === filters.search) {
            return;
        }

        const timer = window.setTimeout(() => visit({ search }), 350);

        return () => window.clearTimeout(timer);
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [search]);

    const hasFilters = Boolean(filters.search || filters.device || filters.role || filters.monitored || filters.status);

    const clearFilters = () => {
        setSearch('');
        router.get('/interfaces', {}, { preserveState: true, preserveScroll: true, replace: true, only: RELOAD });
    };

    const update = (row: InterfaceListRow, data: { role?: string; is_monitored?: boolean }) => {
        router.patch(`/interfaces/${row.id}`, data, { preserveScroll: true, preserveState: true });
    };

    return (
        <AppLayout breadcrumbs={[{ title: 'Interfaces', href: '/interfaces' }]}>
            <Head title="Interfaces" />

            <div className="flex flex-col gap-4 p-4">
                <div>
                    <h1 className="text-xl font-semibold">Interfaces</h1>
                    <p className="text-sm text-muted-foreground">
                        Every port CampusNet knows about. Set the one that faces the internet to <span className="font-medium text-foreground">WAN</span>, because the dashboard and
                        analytics measure utilization on WAN interfaces only.
                    </p>
                </div>

                <FlashMessages />

                <p className="text-sm text-muted-foreground" aria-live="polite">
                    {stats.total} interface{stats.total === 1 ? '' : 's'} &middot; {stats.monitored} monitored &middot; {stats.up} up &middot; {stats.down} down
                </p>

                <form role="search" aria-label="Filter interfaces" onSubmit={(event) => event.preventDefault()} className="flex flex-wrap items-end gap-3">
                    <div className="relative min-w-56 flex-1">
                        <label htmlFor="interface-search" className="sr-only">
                            Search interfaces
                        </label>
                        <Search className="pointer-events-none absolute top-2.5 left-2.5 size-4 text-muted-foreground" aria-hidden="true" />
                        <Input
                            id="interface-search"
                            type="search"
                            value={search}
                            onChange={(event) => setSearch(event.target.value)}
                            placeholder="Search interface, description or device"
                            className="pl-8"
                        />
                    </div>

                    <div className="grid gap-1">
                        <label htmlFor="filter-device" className="text-xs text-muted-foreground">
                            Device
                        </label>
                        <select id="filter-device" className={SELECT} value={filters.device} onChange={(event) => visit({ device: event.target.value })}>
                            <option value="">All devices</option>
                            {devices.map((device) => (
                                <option key={device.id} value={String(device.id)}>
                                    {device.name}
                                </option>
                            ))}
                        </select>
                    </div>

                    <div className="grid gap-1">
                        <label htmlFor="filter-role" className="text-xs text-muted-foreground">
                            Role
                        </label>
                        <select id="filter-role" className={SELECT} value={filters.role} onChange={(event) => visit({ role: event.target.value })}>
                            <option value="">All roles</option>
                            {options.roles.map((role) => (
                                <option key={role.value} value={role.value}>
                                    {role.label}
                                </option>
                            ))}
                        </select>
                    </div>

                    <div className="grid gap-1">
                        <label htmlFor="filter-status" className="text-xs text-muted-foreground">
                            Link status
                        </label>
                        <select id="filter-status" className={SELECT} value={filters.status} onChange={(event) => visit({ status: event.target.value })}>
                            <option value="">Any status</option>
                            <option value="up">Up</option>
                            <option value="down">Down</option>
                            <option value="unknown">Unknown</option>
                        </select>
                    </div>

                    <div className="grid gap-1">
                        <label htmlFor="filter-monitored" className="text-xs text-muted-foreground">
                            Monitoring
                        </label>
                        <select id="filter-monitored" className={SELECT} value={filters.monitored} onChange={(event) => visit({ monitored: event.target.value })}>
                            <option value="">Monitored and not</option>
                            <option value="yes">Monitored only</option>
                            <option value="no">Not monitored only</option>
                        </select>
                    </div>

                    {hasFilters && (
                        <Button type="button" variant="ghost" onClick={clearFilters}>
                            Clear filters
                        </Button>
                    )}
                </form>

                {interfaces.data.length === 0 ? (
                    hasFilters ? (
                        <EmptyState icon={Search} title="No interfaces match these filters" description="Try a different search term or clear the filters.">
                            <Button type="button" variant="outline" onClick={clearFilters}>
                                Clear filters
                            </Button>
                        </EmptyState>
                    ) : (
                        <EmptyState
                            icon={Network}
                            title="No interfaces yet"
                            description="Interfaces appear after a device has been polled for the first time. In demo mode, run php artisan campusnet:demo-data, then php artisan campusnet:poll --force --sync."
                        />
                    )
                ) : (
                    <div className="overflow-x-auto rounded-xl border">
                        <table className="w-full text-sm">
                            <caption className="sr-only">
                                Interfaces, page {interfaces.current_page} of {interfaces.last_page}, {interfaces.total} in total
                            </caption>
                            <thead className="bg-muted/50 text-left text-xs text-muted-foreground">
                                <tr>
                                    <th scope="col" className="px-3 py-2 font-medium">Interface</th>
                                    <th scope="col" className="px-3 py-2 font-medium">Device</th>
                                    <th scope="col" className="px-3 py-2 font-medium">Role</th>
                                    <th scope="col" className="px-3 py-2 font-medium">Link</th>
                                    <th scope="col" className="px-3 py-2 font-medium">Speed</th>
                                    <th scope="col" className="px-3 py-2 font-medium">Latest traffic</th>
                                    <th scope="col" className="px-3 py-2 font-medium">Monitored</th>
                                    <th scope="col" className="px-3 py-2 text-right font-medium">History</th>
                                </tr>
                            </thead>
                            <tbody>
                                {interfaces.data.map((row) => (
                                    <tr key={row.id} className="border-t align-top">
                                        <td className="px-3 py-2">
                                            <div className="font-medium">{row.name}</div>
                                            <div className="mt-0.5 text-xs text-muted-foreground">
                                                ifIndex {row.if_index}
                                                {row.description ? ` \u00b7 ${row.description}` : ''}
                                            </div>
                                        </td>
                                        <td className="px-3 py-2">
                                            <Link href={`/devices/${row.device.id}`} className="underline-offset-2 hover:underline">
                                                {row.device.name}
                                            </Link>
                                            {row.device.is_demo && (
                                                <div className="mt-1">
                                                    <DemoBadge />
                                                </div>
                                            )}
                                        </td>
                                        <td className="px-3 py-2">
                                            {can.manage ? (
                                                <select
                                                    className={SELECT}
                                                    aria-label={`Role of ${row.name} on ${row.device.name}`}
                                                    value={row.role.value}
                                                    onChange={(event) => update(row, { role: event.target.value })}
                                                >
                                                    {options.roles.map((role) => (
                                                        <option key={role.value} value={role.value}>
                                                            {role.label}
                                                        </option>
                                                    ))}
                                                </select>
                                            ) : (
                                                row.role.label
                                            )}
                                        </td>
                                        <td className="px-3 py-2">
                                            <OperStatus value={row.oper_status} />
                                        </td>
                                        <td className="px-3 py-2 tabular-nums">{formatSpeed(row.speed_mbps)}</td>
                                        <td className="px-3 py-2">
                                            {!row.is_monitored ? (
                                                <span className="text-muted-foreground">Not monitored</span>
                                            ) : row.latest ? (
                                                <>
                                                    <div className="tabular-nums">
                                                        <span aria-label="Inbound">&darr;</span> {formatMbps(row.latest.in_mbps)} &middot; <span aria-label="Outbound">&uarr;</span>{' '}
                                                        {formatMbps(row.latest.out_mbps)}
                                                    </div>
                                                    <div className="mt-0.5 text-xs text-muted-foreground">measured {timeAgo(row.latest.sampled_at)}</div>
                                                </>
                                            ) : (
                                                <span className="text-muted-foreground">No valid measurement yet</span>
                                            )}
                                        </td>
                                        <td className="px-3 py-2">
                                            {can.manage ? (
                                                <button
                                                    type="button"
                                                    role="switch"
                                                    aria-checked={row.is_monitored}
                                                    aria-label={`Monitor ${row.name} on ${row.device.name}`}
                                                    onClick={() => update(row, { is_monitored: !row.is_monitored })}
                                                    className="inline-flex items-center gap-2 rounded-full focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
                                                >
                                                    <span
                                                        className={cn(
                                                            'relative inline-flex h-5 w-9 items-center rounded-full transition-colors',
                                                            row.is_monitored ? 'bg-primary' : 'bg-muted-foreground/40',
                                                        )}
                                                    >
                                                        <span
                                                            className={cn(
                                                                'inline-block size-4 rounded-full bg-background transition-transform',
                                                                row.is_monitored ? 'translate-x-4.5' : 'translate-x-0.5',
                                                            )}
                                                        />
                                                    </span>
                                                    <span className="text-xs">{row.is_monitored ? 'On' : 'Off'}</span>
                                                </button>
                                            ) : (
                                                <Pill tone={row.is_monitored ? 'blue' : 'gray'}>{row.is_monitored ? 'Monitored' : 'Not monitored'}</Pill>
                                            )}
                                        </td>
                                        <td className="px-3 py-2 text-right">
                                            <Button asChild variant="ghost" size="sm">
                                                <Link href={`/traffic-history?interface=${row.id}`} aria-label={`Traffic history of ${row.name} on ${row.device.name}`}>
                                                    <History className="size-4" aria-hidden="true" />
                                                    History
                                                </Link>
                                            </Button>
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                )}

                {interfaces.total > 0 && (
                    <nav aria-label="Pagination" className="flex flex-wrap items-center justify-between gap-3 text-sm">
                        <p className="text-muted-foreground">
                            Showing {interfaces.from ?? 0}&ndash;{interfaces.to ?? 0} of {interfaces.total}
                        </p>

                        <div className="flex items-center gap-3">
                            <Button type="button" variant="outline" size="sm" disabled={interfaces.current_page <= 1} onClick={() => visit({ page: interfaces.current_page - 1 })}>
                                Previous
                            </Button>
                            <span aria-current="page">
                                Page {interfaces.current_page} of {interfaces.last_page}
                            </span>
                            <Button type="button" variant="outline" size="sm" disabled={interfaces.current_page >= interfaces.last_page} onClick={() => visit({ page: interfaces.current_page + 1 })}>
                                Next
                            </Button>
                        </div>
                    </nav>
                )}

                {can.manage && (
                    <p className="text-xs text-muted-foreground">
                        Turning monitoring off keeps the interface and its history, and the next poll skips it. Name, index and speed always come from the device itself, so they cannot be edited here.
                    </p>
                )}
            </div>
        </AppLayout>
    );
}