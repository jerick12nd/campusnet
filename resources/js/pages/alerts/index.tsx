import { Head, Link, router } from '@inertiajs/react';
import { Bell, CircleCheck, Search } from 'lucide-react';
import { type FormEvent, useEffect, useRef, useState } from 'react';

import { EmptyState } from '@/components/empty-state';
import { FlashMessages } from '@/components/flash-messages';
import { DemoBadge, Pill, SeverityBadge } from '@/components/status-badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import AppLayout from '@/layouts/app-layout';
import { formatDateTime, timeAgo } from '@/lib/format';
import type { AlertFilters, AlertListRow, AlertStats, Option, Paginated } from '@/types/campusnet';

type Props = {
    alerts: Paginated<AlertListRow>;
    filters: AlertFilters;
    stats: AlertStats;
    devices: { id: number; name: string }[];
    options: { types: Option[]; severities: Option[] };
    can: { manage: boolean };
};

const SELECT =
    'border-input bg-background h-9 rounded-md border px-2 text-sm shadow-xs outline-none focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50';

const RELOAD = ['alerts', 'filters', 'stats', 'devices'];

/** Status is always shown as text, never by colour alone. */
function StatusPill({ status }: { status: AlertListRow['status'] }) {
    if (status === 'active') {
        return <Pill tone="red">Active</Pill>;
    }

    if (status === 'acknowledged') {
        return <Pill tone="blue">Acknowledged</Pill>;
    }

    return (
        <Pill tone="green">
            <CircleCheck className="size-3" aria-hidden="true" />
            Resolved
        </Pill>
    );
}

export default function AlertsIndex({ alerts, filters, stats, devices, options, can }: Props) {
    const [search, setSearch] = useState(filters.search);
    const [resolving, setResolving] = useState<number | null>(null);
    const [note, setNote] = useState('');
    const first = useRef(true);

    const visit = (changes: Partial<AlertFilters & { page: number }>) => {
        const next = { ...filters, ...changes };
        const query: Record<string, string | number> = {};

        (['status', 'severity', 'type', 'device', 'search'] as const).forEach((key) => {
            if (next[key]) {
                query[key] = next[key];
            }
        });

        if (changes.page && changes.page > 1) {
            query.page = changes.page;
        }

        router.get('/alerts', query, { preserveState: true, preserveScroll: true, replace: true, only: RELOAD });
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

    // Alerts are raised by the scheduler every minute, so keep the list fresh without a full page reload.
    // Reloading is paused while someone is writing a resolution note, so the form is never lost.
    useEffect(() => {
        const timer = window.setInterval(() => {
            if (document.visibilityState === 'visible' && resolving === null) {
                router.reload({ only: ['alerts', 'stats'] });
            }
        }, 30000);

        return () => window.clearInterval(timer);
    }, [resolving]);

    // "Open" is the default view, so it is the only status that does not count as a filter.
    const hasFilters = Boolean(filters.search || filters.device || filters.severity || filters.type || (filters.status && filters.status !== 'open'));

    const clearFilters = () => {
        setSearch('');
        router.get('/alerts', {}, { preserveState: true, preserveScroll: true, replace: true, only: RELOAD });
    };

    const acknowledge = (row: AlertListRow) => {
        router.patch(`/alerts/${row.id}/acknowledge`, {}, { preserveScroll: true });
    };

    const submitResolve = (event: FormEvent, row: AlertListRow) => {
        event.preventDefault();

        router.patch(
            `/alerts/${row.id}/resolve`,
            { note },
            {
                preserveScroll: true,
                onSuccess: () => {
                    setResolving(null);
                    setNote('');
                },
            },
        );
    };

    return (
        <AppLayout breadcrumbs={[{ title: 'Alerts', href: '/alerts' }]}>
            <Head title="Alerts" />

            <div className="flex flex-col gap-4 p-4">
                <div>
                    <h1 className="text-xl font-semibold">Alerts</h1>
                    <p className="text-sm text-muted-foreground">
                        CampusNet raises an alert when a device stops answering, when WAN utilization stays above the thresholds in System Settings, or when no new
                        measurements arrive. An alert closes by itself when the problem ends.
                    </p>
                </div>

                <FlashMessages />

                <p className="text-sm text-muted-foreground" aria-live="polite">
                    {stats.open} open ({stats.active} active, {stats.acknowledged} acknowledged) &middot; {stats.critical} critical &middot; {stats.resolved_7d} resolved in the
                    last 7 days
                </p>

                <form role="search" aria-label="Filter alerts" onSubmit={(event) => event.preventDefault()} className="flex flex-wrap items-end gap-3">
                    <div className="relative min-w-56 flex-1">
                        <label htmlFor="alert-search" className="sr-only">
                            Search alerts
                        </label>
                        <Search className="pointer-events-none absolute top-2.5 left-2.5 size-4 text-muted-foreground" aria-hidden="true" />
                        <Input
                            id="alert-search"
                            type="search"
                            value={search}
                            onChange={(event) => setSearch(event.target.value)}
                            placeholder="Search title or description"
                            className="pl-8"
                        />
                    </div>

                    <div className="grid gap-1">
                        <label htmlFor="filter-status" className="text-xs text-muted-foreground">
                            Status
                        </label>
                        <select id="filter-status" className={SELECT} value={filters.status} onChange={(event) => visit({ status: event.target.value })}>
                            <option value="open">Open (active + acknowledged)</option>
                            <option value="active">Active only</option>
                            <option value="acknowledged">Acknowledged only</option>
                            <option value="resolved">Resolved</option>
                            <option value="all">All</option>
                        </select>
                    </div>

                    <div className="grid gap-1">
                        <label htmlFor="filter-severity" className="text-xs text-muted-foreground">
                            Severity
                        </label>
                        <select id="filter-severity" className={SELECT} value={filters.severity} onChange={(event) => visit({ severity: event.target.value })}>
                            <option value="">All severities</option>
                            {options.severities.map((severity) => (
                                <option key={severity.value} value={severity.value}>
                                    {severity.label}
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

                    {hasFilters && (
                        <Button type="button" variant="ghost" onClick={clearFilters}>
                            Clear filters
                        </Button>
                    )}
                </form>

                {alerts.data.length === 0 ? (
                    hasFilters ? (
                        <EmptyState icon={Search} title="No alerts match these filters" description="Try a different search term or clear the filters.">
                            <Button type="button" variant="outline" onClick={clearFilters}>
                                Clear filters
                            </Button>
                        </EmptyState>
                    ) : filters.status === 'open' ? (
                        <EmptyState
                            icon={CircleCheck}
                            title="No open alerts"
                            description="Everything that is monitored looks healthy right now. Resolved alerts stay in the log: choose Resolved or All in the status filter."
                        />
                    ) : (
                        <EmptyState
                            icon={Bell}
                            title="No alerts recorded yet"
                            description="Alerts are evaluated every minute by the scheduler. Run php artisan schedule:work, or php artisan campusnet:evaluate-alerts to check once."
                        />
                    )
                ) : (
                    <div className="overflow-x-auto rounded-xl border">
                        <table className="w-full text-sm">
                            <caption className="sr-only">
                                Alerts, page {alerts.current_page} of {alerts.last_page}, {alerts.total} in total
                            </caption>
                            <thead className="bg-muted/50 text-left text-xs text-muted-foreground">
                                <tr>
                                    <th scope="col" className="px-3 py-2 font-medium">Severity</th>
                                    <th scope="col" className="px-3 py-2 font-medium">Alert</th>
                                    <th scope="col" className="px-3 py-2 font-medium">Device</th>
                                    <th scope="col" className="px-3 py-2 font-medium">Status</th>
                                    <th scope="col" className="px-3 py-2 font-medium">Raised</th>
                                    {can.manage && (
                                        <th scope="col" className="px-3 py-2 text-right font-medium">
                                            Actions
                                        </th>
                                    )}
                                </tr>
                            </thead>
                            <tbody>
                                {alerts.data.map((row) => (
                                    <tr key={row.id} className="border-t align-top">
                                        <td className="px-3 py-2">
                                            <SeverityBadge severity={row.severity} />
                                        </td>
                                        <td className="max-w-md px-3 py-2">
                                            <div className="font-medium">{row.title}</div>
                                            <div className="mt-0.5 text-xs text-muted-foreground">{row.type.label}</div>
                                            {row.description && <p className="mt-1 text-xs text-muted-foreground">{row.description}</p>}

                                            {resolving === row.id && (
                                                <form onSubmit={(event) => submitResolve(event, row)} className="mt-2 flex flex-wrap items-end gap-2">
                                                    <div className="grid min-w-48 flex-1 gap-1">
                                                        <label htmlFor={`note-${row.id}`} className="text-xs text-muted-foreground">
                                                            Resolution note (optional)
                                                        </label>
                                                        <Input
                                                            id={`note-${row.id}`}
                                                            value={note}
                                                            maxLength={255}
                                                            onChange={(event) => setNote(event.target.value)}
                                                            placeholder="What was done?"
                                                            autoFocus
                                                        />
                                                    </div>
                                                    <Button type="submit" size="sm">
                                                        Resolve
                                                    </Button>
                                                    <Button
                                                        type="button"
                                                        size="sm"
                                                        variant="ghost"
                                                        onClick={() => {
                                                            setResolving(null);
                                                            setNote('');
                                                        }}
                                                    >
                                                        Cancel
                                                    </Button>
                                                </form>
                                            )}
                                        </td>
                                        <td className="px-3 py-2">
                                            {row.device ? (
                                                <>
                                                    <Link href={`/devices/${row.device.id}`} className="underline-offset-2 hover:underline">
                                                        {row.device.name}
                                                    </Link>
                                                    {row.interface && <div className="mt-0.5 text-xs text-muted-foreground">{row.interface}</div>}
                                                    {row.device.is_demo && (
                                                        <div className="mt-1">
                                                            <DemoBadge />
                                                        </div>
                                                    )}
                                                </>
                                            ) : (
                                                <span className="text-muted-foreground">System</span>
                                            )}
                                        </td>
                                        <td className="px-3 py-2">
                                            <StatusPill status={row.status} />
                                            {row.status === 'acknowledged' && row.acknowledged_by && (
                                                <div className="mt-1 text-xs text-muted-foreground">by {row.acknowledged_by}</div>
                                            )}
                                            {row.status === 'resolved' && (
                                                <div className="mt-1 text-xs text-muted-foreground">
                                                    {row.auto_resolved ? 'automatically' : `by ${row.resolved_by ?? 'unknown'}`}, {timeAgo(row.resolved_at)}
                                                    {row.resolution_note && <div>&ldquo;{row.resolution_note}&rdquo;</div>}
                                                </div>
                                            )}
                                        </td>
                                        <td className="px-3 py-2">
                                            <div>{timeAgo(row.triggered_at)}</div>
                                            <div className="mt-0.5 text-xs text-muted-foreground">{formatDateTime(row.triggered_at)}</div>
                                        </td>
                                        {can.manage && (
                                            <td className="px-3 py-2 text-right">
                                                {row.status !== 'resolved' && (
                                                    <div className="flex justify-end gap-2">
                                                        {row.status === 'active' && (
                                                            <Button type="button" size="sm" variant="outline" onClick={() => acknowledge(row)} aria-label={`Acknowledge: ${row.title}`}>
                                                                Acknowledge
                                                            </Button>
                                                        )}
                                                        <Button
                                                            type="button"
                                                            size="sm"
                                                            variant="outline"
                                                            onClick={() => {
                                                                setResolving(row.id);
                                                                setNote('');
                                                            }}
                                                            aria-label={`Resolve: ${row.title}`}
                                                        >
                                                            Resolve
                                                        </Button>
                                                    </div>
                                                )}
                                            </td>
                                        )}
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                )}

                {alerts.total > 0 && (
                    <nav aria-label="Pagination" className="flex flex-wrap items-center justify-between gap-3 text-sm">
                        <p className="text-muted-foreground">
                            Showing {alerts.from ?? 0}&ndash;{alerts.to ?? 0} of {alerts.total}
                        </p>

                        <div className="flex items-center gap-3">
                            <Button type="button" variant="outline" size="sm" disabled={alerts.current_page <= 1} onClick={() => visit({ page: alerts.current_page - 1 })}>
                                Previous
                            </Button>
                            <span aria-current="page">
                                Page {alerts.current_page} of {alerts.last_page}
                            </span>
                            <Button type="button" variant="outline" size="sm" disabled={alerts.current_page >= alerts.last_page} onClick={() => visit({ page: alerts.current_page + 1 })}>
                                Next
                            </Button>
                        </div>
                    </nav>
                )}

                <p className="text-xs text-muted-foreground">
                    Acknowledge means &ldquo;someone is looking at it&rdquo; and the alert stays open. If the problem gets worse, the alert becomes active again. Resolving by hand is for problems that
                    cannot clear themselves.
                </p>
            </div>
        </AppLayout>
    );
}