import { Head, Link, router } from '@inertiajs/react';
import { ArrowDown, ArrowUp, ArrowUpDown, Plus, Router, Search } from 'lucide-react';
import { useEffect, useRef, useState } from 'react';

import { EmptyState } from '@/components/empty-state';
import { FlashMessages } from '@/components/flash-messages';
import { DemoBadge, DeviceStatusBadge, EnabledBadge } from '@/components/status-badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import AppLayout from '@/layouts/app-layout';
import { timeAgo } from '@/lib/format';
import type { DeviceFilters, DeviceOptions, DeviceSummary, Paginated } from '@/types/campusnet';

type Props = {
    devices: Paginated<DeviceSummary>;
    filters: DeviceFilters;
    locations: string[];
    options: DeviceOptions;
    can: { manage: boolean };
};

const SELECT =
    'border-input bg-background h-9 rounded-md border px-2 text-sm shadow-xs outline-none focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50';

const COLUMNS: { key: string; label: string; sortable: boolean; className?: string }[] = [
    { key: 'name', label: 'Device', sortable: true },
    { key: 'management_ip', label: 'IP address', sortable: true },
    { key: 'device_type', label: 'Type', sortable: true },
    { key: 'location', label: 'Location', sortable: true },
    { key: 'last_status', label: 'Status', sortable: true },
    { key: 'last_successful_poll_at', label: 'Last seen', sortable: true },
    { key: 'actions', label: 'Actions', sortable: false, className: 'text-right' },
];

export default function DevicesIndex({ devices, filters, locations, options, can }: Props) {
    const [search, setSearch] = useState(filters.search);
    const first = useRef(true);

    const visit = (changes: Partial<DeviceFilters & { page: number }>) => {
        const next = { ...filters, ...changes };
        const query: Record<string, string | number> = {};

        (['search', 'status', 'type', 'location', 'enabled'] as const).forEach((key) => {
            if (next[key]) {
                query[key] = next[key];
            }
        });

        // Defaults are left out of the URL so it stays short and shareable.
        if (next.sort !== 'name' || next.direction !== 'asc') {
            query.sort = next.sort;
            query.direction = next.direction;
        }

        if (next.per_page !== 15) {
            query.per_page = next.per_page;
        }

        if (changes.page && changes.page > 1) {
            query.page = changes.page;
        }

        router.get('/devices', query, { preserveState: true, preserveScroll: true, replace: true, only: ['devices', 'filters', 'locations'] });
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

    const sortBy = (key: string) => {
        visit({ sort: key, direction: filters.sort === key && filters.direction === 'asc' ? 'desc' : 'asc' });
    };

    const hasFilters = Boolean(filters.search || filters.status || filters.type || filters.location || filters.enabled);

    const clearFilters = () => {
        setSearch('');
        router.get('/devices', {}, { preserveState: true, preserveScroll: true, replace: true, only: ['devices', 'filters', 'locations'] });
    };

    const toggle = (device: DeviceSummary) => {
        router.patch(`/devices/${device.id}/toggle`, {}, { preserveScroll: true });
    };

    return (
        <AppLayout breadcrumbs={[{ title: 'Network Devices', href: '/devices' }]}>
            <Head title="Network Devices" />

            <div className="flex flex-col gap-4 p-4">
                <div className="flex flex-wrap items-center justify-between gap-3">
                    <div>
                        <h1 className="text-xl font-semibold">Network devices</h1>
                        <p className="text-sm text-muted-foreground">Routers, switches, access points and servers that CampusNet monitors. Devices are only ever added by you.</p>
                    </div>
                    {can.manage && (
                        <Button asChild>
                            <Link href="/devices/create">
                                <Plus className="size-4" aria-hidden="true" />
                                Add device
                            </Link>
                        </Button>
                    )}
                </div>

                <FlashMessages />

                <form role="search" aria-label="Filter devices" onSubmit={(event) => event.preventDefault()} className="flex flex-wrap items-end gap-3">
                    <div className="relative min-w-56 flex-1">
                        <label htmlFor="device-search" className="sr-only">
                            Search devices
                        </label>
                        <Search className="pointer-events-none absolute top-2.5 left-2.5 size-4 text-muted-foreground" aria-hidden="true" />
                        <Input id="device-search" type="search" value={search} onChange={(event) => setSearch(event.target.value)} placeholder="Search name, IP, vendor, model or location" className="pl-8" />
                    </div>

                    <div className="grid gap-1">
                        <label htmlFor="filter-status" className="text-xs text-muted-foreground">
                            Status
                        </label>
                        <select id="filter-status" className={SELECT} value={filters.status} onChange={(event) => visit({ status: event.target.value })}>
                            <option value="">All statuses</option>
                            {options.statuses.map((status) => (
                                <option key={status.value} value={status.value}>
                                    {status.label}
                                </option>
                            ))}
                        </select>
                    </div>

                    <div className="grid gap-1">
                        <label htmlFor="filter-type" className="text-xs text-muted-foreground">
                            Type
                        </label>
                        <select id="filter-type" className={SELECT} value={filters.type} onChange={(event) => visit({ type: event.target.value })}>
                            <option value="">All types</option>
                            {options.types.map((type) => (
                                <option key={type.value} value={type.value}>
                                    {type.label}
                                </option>
                            ))}
                        </select>
                    </div>

                    <div className="grid gap-1">
                        <label htmlFor="filter-location" className="text-xs text-muted-foreground">
                            Location
                        </label>
                        <select id="filter-location" className={SELECT} value={filters.location} onChange={(event) => visit({ location: event.target.value })}>
                            <option value="">All locations</option>
                            {locations.map((location) => (
                                <option key={location} value={location}>
                                    {location}
                                </option>
                            ))}
                        </select>
                    </div>

                    <div className="grid gap-1">
                        <label htmlFor="filter-enabled" className="text-xs text-muted-foreground">
                            Monitoring
                        </label>
                        <select id="filter-enabled" className={SELECT} value={filters.enabled} onChange={(event) => visit({ enabled: event.target.value })}>
                            <option value="">Enabled and disabled</option>
                            <option value="yes">Enabled only</option>
                            <option value="no">Disabled only</option>
                        </select>
                    </div>

                    {hasFilters && (
                        <Button type="button" variant="ghost" onClick={clearFilters}>
                            Clear filters
                        </Button>
                    )}
                </form>

                {devices.data.length === 0 ? (
                    hasFilters ? (
                        <EmptyState icon={Search} title="No devices match these filters" description="Try a different search term or clear the filters.">
                            <Button type="button" variant="outline" onClick={clearFilters}>
                                Clear filters
                            </Button>
                        </EmptyState>
                    ) : (
                        <EmptyState icon={Router} title="No devices yet" description="Add the routers, switches and access points you want to monitor. CampusNet only contacts devices you add yourself.">
                            {can.manage && (
                                <Button asChild>
                                    <Link href="/devices/create">Add the first device</Link>
                                </Button>
                            )}
                        </EmptyState>
                    )
                ) : (
                    <div className="overflow-x-auto rounded-xl border">
                        <table className="w-full text-sm">
                            <caption className="sr-only">
                                Network devices, page {devices.current_page} of {devices.last_page}, {devices.total} in total
                            </caption>
                            <thead className="bg-muted/50 text-left text-xs text-muted-foreground">
                                <tr>
                                    {COLUMNS.map((column) => {
                                        const active = filters.sort === column.key;

                                        return (
                                            <th
                                                key={column.key}
                                                scope="col"
                                                className={`px-3 py-2 font-medium ${column.className ?? ''}`}
                                                aria-sort={column.sortable && active ? (filters.direction === 'asc' ? 'ascending' : 'descending') : undefined}
                                            >
                                                {column.sortable ? (
                                                    <button type="button" onClick={() => sortBy(column.key)} className="inline-flex items-center gap-1 rounded-sm font-medium hover:text-foreground focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none">
                                                        {column.label}
                                                        {active ? filters.direction === 'asc' ? <ArrowUp className="size-3" aria-hidden="true" /> : <ArrowDown className="size-3" aria-hidden="true" /> : <ArrowUpDown className="size-3 opacity-50" aria-hidden="true" />}
                                                    </button>
                                                ) : (
                                                    column.label
                                                )}
                                            </th>
                                        );
                                    })}
                                </tr>
                            </thead>
                            <tbody>
                                {devices.data.map((device) => (
                                    <tr key={device.id} className="border-t align-top">
                                        <td className="px-3 py-2">
                                            <Link href={`/devices/${device.id}`} className="font-medium underline-offset-2 hover:underline">
                                                {device.name}
                                            </Link>
                                            <div className="mt-0.5 flex flex-wrap items-center gap-1.5 text-xs text-muted-foreground">
                                                {[device.vendor, device.model].filter(Boolean).join(' ') || 'No model recorded'}
                                                {device.is_demo && <DemoBadge />}
                                                {!device.is_enabled && <EnabledBadge enabled={false} />}
                                            </div>
                                        </td>
                                        <td className="px-3 py-2 font-mono text-xs">{device.management_ip}</td>
                                        <td className="px-3 py-2">{device.device_type.label}</td>
                                        <td className="px-3 py-2">{device.location ?? '—'}</td>
                                        <td className="px-3 py-2">
                                            <DeviceStatusBadge status={device.last_status} />
                                            {device.open_alerts_count > 0 && (
                                                <div className="mt-1 text-xs text-muted-foreground">
                                                    {device.open_alerts_count} open alert{device.open_alerts_count === 1 ? '' : 's'}
                                                </div>
                                            )}
                                        </td>
                                        <td className="px-3 py-2 text-muted-foreground">{timeAgo(device.last_successful_poll_at)}</td>
                                        <td className="px-3 py-2">
                                            <div className="flex justify-end gap-1">
                                                <Button asChild variant="ghost" size="sm">
                                                    <Link href={`/devices/${device.id}`} aria-label={`View ${device.name}`}>
                                                        View
                                                    </Link>
                                                </Button>
                                                {can.manage && (
                                                    <>
                                                        <Button asChild variant="ghost" size="sm">
                                                            <Link href={`/devices/${device.id}/edit`} aria-label={`Edit ${device.name}`}>
                                                                Edit
                                                            </Link>
                                                        </Button>
                                                        <Button type="button" variant="ghost" size="sm" onClick={() => toggle(device)} aria-label={`${device.is_enabled ? 'Disable' : 'Enable'} ${device.name}`}>
                                                            {device.is_enabled ? 'Disable' : 'Enable'}
                                                        </Button>
                                                    </>
                                                )}
                                            </div>
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                )}

                {devices.total > 0 && (
                    <nav aria-label="Pagination" className="flex flex-wrap items-center justify-between gap-3 text-sm">
                        <p className="text-muted-foreground">
                            Showing {devices.from ?? 0}–{devices.to ?? 0} of {devices.total}
                        </p>

                        <div className="flex items-center gap-3">
                            <label className="flex items-center gap-2 text-muted-foreground">
                                Per page
                                <select className={SELECT} value={filters.per_page} onChange={(event) => visit({ per_page: Number(event.target.value) })}>
                                    {[10, 15, 25, 50].map((size) => (
                                        <option key={size} value={size}>
                                            {size}
                                        </option>
                                    ))}
                                </select>
                            </label>

                            <Button type="button" variant="outline" size="sm" disabled={devices.current_page <= 1} onClick={() => visit({ page: devices.current_page - 1 })}>
                                Previous
                            </Button>
                            <span aria-current="page">
                                Page {devices.current_page} of {devices.last_page}
                            </span>
                            <Button type="button" variant="outline" size="sm" disabled={devices.current_page >= devices.last_page} onClick={() => visit({ page: devices.current_page + 1 })}>
                                Next
                            </Button>
                        </div>
                    </nav>
                )}
            </div>
        </AppLayout>
    );
}