import { Head, Link, router } from '@inertiajs/react';
import { CircleCheck, CircleHelp, CircleMinus, Download, Info, Search, UserCheck } from 'lucide-react';
import { useEffect, useRef, useState } from 'react';

import { EmptyState } from '@/components/empty-state';
import { FlashMessages } from '@/components/flash-messages';
import { OfficeNetworkTabs } from '@/components/office-network-tabs';
import { Pill } from '@/components/status-badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import AppLayout from '@/layouts/app-layout';
import { timeAgo } from '@/lib/format';
import type { Option, Paginated } from '@/types/campusnet';
import type { ConnectionRow, OfficeOption } from '@/types/connected-members';
import type { OfficeSource } from '@/types/office-network';

type Filters = { search: string; office: string; status: string; type: string };

type Props = {
    connections: Paginated<ConnectionRow>;
    summary: { online_identified: number; online_unidentified: number; members: number; registered_devices: number };
    source: OfficeSource;
    offices: OfficeOption[];
    typeOptions: Option[];
    filters: Filters;
    can: { manage: boolean; export: boolean };
};

const SELECT =
    'border-input bg-background h-9 rounded-md border px-2 text-sm shadow-xs outline-none focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50';

const FILTER_KEYS = ['search', 'office', 'status', 'type'] as const;
const RELOAD = ['connections', 'filters'];

function SourceNotice({ source }: { source: OfficeSource }) {
    let text: string | null = null;

    if (!source.enabled) {
        text =
            'No connection data source is enabled, so CampusNet cannot tell which devices are connected. Connection data comes from the MikroTik DHCP lease source.';
    } else if (source.failing) {
        text = 'The last attempt to read the DHCP leases failed. The list below may be out of date.';
    } else if (source.stale) {
        text = source.last_run_at ? `Connection data may be out of date. It was last collected ${timeAgo(source.last_run_at)}.` : 'Connection data has not been collected yet.';
    }

    if (text === null) {
        return null;
    }

    return (
        <div role="status" className="flex items-start gap-2 rounded-xl border bg-muted/40 p-3 text-sm text-muted-foreground">
            <Info className="mt-0.5 size-4 shrink-0" aria-hidden="true" />
            <p>{text}</p>
        </div>
    );
}

export default function ConnectedMembersIndex({ connections, summary, source, offices, typeOptions, filters, can }: Props) {
    const [search, setSearch] = useState(filters.search);
    const first = useRef(true);

    const visit = (changes: Partial<Filters & { page: number }>) => {
        const next = { ...filters, ...changes };
        const query: Record<string, string | number> = {};

        FILTER_KEYS.forEach((key) => {
            if (next[key]) {
                query[key] = next[key];
            }
        });

        if (changes.page && changes.page > 1) {
            query.page = changes.page;
        }

        router.get('/connected-members', query, { preserveState: true, preserveScroll: true, replace: true, only: RELOAD });
    };

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

    const hasFilters = FILTER_KEYS.some((key) => Boolean(filters[key]));

    const clearFilters = () => {
        setSearch('');
        router.get('/connected-members', {}, { preserveState: true, preserveScroll: true, replace: true, only: RELOAD });
    };

    const params = new URLSearchParams();
    FILTER_KEYS.forEach((key) => {
        if (filters[key]) {
            params.set(key, filters[key]);
        }
    });
    const queryString = params.toString();
    const exportHref = `/connected-members/export${queryString ? `?${queryString}` : ''}`;

    const cards = [
        { label: 'Members online', value: source.enabled ? summary.online_identified : '\u2014', hint: 'Registered devices seen recently' },
        { label: 'Unidentified online', value: source.enabled ? summary.online_unidentified : '\u2014', hint: 'Connected but not registered' },
        { label: 'Active members', value: summary.members, hint: 'Across all offices' },
        { label: 'Registered devices', value: summary.registered_devices, hint: 'Active devices' },
    ];

    return (
        <AppLayout breadcrumbs={[{ title: 'Connected Members', href: '/connected-members' }]}>
            <Head title="Connected Members" />

            <div className="flex flex-col gap-4 p-4">
                <div className="flex flex-wrap items-start justify-between gap-3">
                    <div>
                        <h1 className="text-xl font-semibold">Connected Members</h1>
                        <p className="text-sm text-muted-foreground">
                            Registered members whose devices are connected to the campus network. A device is only linked to a member when an administrator registered it. Anything else is shown as unidentified.
                        </p>
                    </div>

                    {can.export && (
                        <Button asChild variant="outline">
                            <a href={exportHref}>
                                <Download className="size-4" aria-hidden="true" />
                                Export CSV
                            </a>
                        </Button>
                    )}
                </div>

                <OfficeNetworkTabs current="connected" />
                <FlashMessages />
                <SourceNotice source={source} />

                <dl className="grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
                    {cards.map((card) => (
                        <div key={card.label} className="rounded-xl border p-4">
                            <dt className="text-xs text-muted-foreground">{card.label}</dt>
                            <dd className="mt-1 text-2xl font-semibold tabular-nums">{card.value}</dd>
                            <p className="mt-1 text-xs text-muted-foreground">{card.hint}</p>
                        </div>
                    ))}
                </dl>

                <form role="search" aria-label="Filter connections" onSubmit={(event) => event.preventDefault()} className="flex flex-wrap items-end gap-3">
                    <div className="relative min-w-56 flex-1">
                        <label htmlFor="cm-search" className="sr-only">
                            Search connections
                        </label>
                        <Search className="pointer-events-none absolute top-2.5 left-2.5 size-4 text-muted-foreground" aria-hidden="true" />
                        <Input
                            id="cm-search"
                            type="search"
                            value={search}
                            onChange={(event) => setSearch(event.target.value)}
                            placeholder="Search member, device, hostname, IP or MAC"
                            className="pl-8"
                        />
                    </div>

                    <div className="grid gap-1">
                        <label htmlFor="cm-office" className="text-xs text-muted-foreground">
                            Office
                        </label>
                        <select id="cm-office" className={SELECT} value={filters.office} onChange={(event) => visit({ office: event.target.value })}>
                            <option value="">All offices</option>
                            {offices.map((office) => (
                                <option key={office.id} value={String(office.id)}>
                                    {office.name}
                                </option>
                            ))}
                        </select>
                    </div>

                    <div className="grid gap-1">
                        <label htmlFor="cm-status" className="text-xs text-muted-foreground">
                            Status
                        </label>
                        <select id="cm-status" className={SELECT} value={filters.status} onChange={(event) => visit({ status: event.target.value })}>
                            <option value="">Any status</option>
                            <option value="online">Online</option>
                            <option value="offline">Offline</option>
                            <option value="unidentified">Unidentified</option>
                        </select>
                    </div>

                    <div className="grid gap-1">
                        <label htmlFor="cm-type" className="text-xs text-muted-foreground">
                            Device type
                        </label>
                        <select id="cm-type" className={SELECT} value={filters.type} onChange={(event) => visit({ type: event.target.value })}>
                            <option value="">All types</option>
                            {typeOptions.map((option) => (
                                <option key={option.value} value={option.value}>
                                    {option.label}
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

                {connections.data.length === 0 ? (
                    hasFilters ? (
                        <EmptyState icon={Search} title="No connections match these filters" description="Try a different search term or clear the filters.">
                            <Button type="button" variant="outline" onClick={clearFilters}>
                                Clear filters
                            </Button>
                        </EmptyState>
                    ) : (
                        <EmptyState
                            icon={UserCheck}
                            title="No connections recorded yet"
                            description={
                                source.enabled
                                    ? 'The data source is enabled but has not recorded any connection yet. Check back after its next collection.'
                                    : 'Connections appear here after a connection data source is enabled. Register your members and devices first so they are recognised.'
                            }
                        />
                    )
                ) : (
                    <div className="overflow-x-auto rounded-xl border">
                        <table className="w-full text-sm">
                            <caption className="sr-only">
                                Connections, page {connections.current_page} of {connections.last_page}, {connections.total} in total
                            </caption>
                            <thead className="bg-muted/50 text-left text-xs text-muted-foreground">
                                <tr>
                                    <th scope="col" className="px-3 py-2 font-medium">Member</th>
                                    <th scope="col" className="px-3 py-2 font-medium">Device</th>
                                    <th scope="col" className="px-3 py-2 font-medium">Office</th>
                                    <th scope="col" className="px-3 py-2 font-medium">IP address</th>
                                    <th scope="col" className="px-3 py-2 font-medium">MAC address</th>
                                    <th scope="col" className="px-3 py-2 font-medium">Connected since</th>
                                    <th scope="col" className="px-3 py-2 font-medium">Last seen</th>
                                    <th scope="col" className="px-3 py-2 font-medium">Status</th>
                                    <th scope="col" className="px-3 py-2 text-right font-medium">Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                {connections.data.map((row) => (
                                    <tr key={row.id} className="border-t align-top">
                                        <td className="px-3 py-2">
                                            {row.member_id && row.member_name ? (
                                                <Link href={`/members/${row.member_id}`} className="font-medium underline-offset-2 hover:underline">
                                                    {row.member_name}
                                                </Link>
                                            ) : row.identified ? (
                                                <span className="text-muted-foreground">Shared device</span>
                                            ) : (
                                                <Pill tone="gray">
                                                    <CircleHelp className="size-3" aria-hidden="true" />
                                                    Unidentified
                                                </Pill>
                                            )}
                                        </td>
                                        <td className="px-3 py-2">
                                            <div>{row.device_name ?? row.hostname ?? 'Unknown device'}</div>
                                            <div className="mt-0.5 text-xs text-muted-foreground">
                                                {[row.device_type, row.interface_name].filter(Boolean).join(' \u00b7 ') || '\u2014'}
                                            </div>
                                            {row.private_mac && (
                                                <div className="mt-0.5 text-xs text-muted-foreground" title="Phones often use a private Wi-Fi address that changes per network">
                                                    Private Wi-Fi address
                                                </div>
                                            )}
                                        </td>
                                        <td className="px-3 py-2">{row.office_name ?? '\u2014'}</td>
                                        <td className="px-3 py-2 font-mono text-xs">{row.ip_address ?? '\u2014'}</td>
                                        <td className="px-3 py-2 font-mono text-xs">{row.mac_address}</td>
                                        <td className="px-3 py-2 text-muted-foreground">{timeAgo(row.started_at)}</td>
                                        <td className="px-3 py-2 text-muted-foreground">{timeAgo(row.last_seen_at)}</td>
                                        <td className="px-3 py-2">
                                            {row.status === 'online' ? (
                                                <Pill tone="green">
                                                    <CircleCheck className="size-3" aria-hidden="true" />
                                                    Online
                                                </Pill>
                                            ) : (
                                                <Pill tone="gray">
                                                    <CircleMinus className="size-3" aria-hidden="true" />
                                                    Offline
                                                </Pill>
                                            )}
                                        </td>
                                        <td className="px-3 py-2 text-right">
                                            {!row.identified && can.manage ? (
                                                <Button asChild variant="ghost" size="sm">
                                                    <Link
                                                        href={`/registered-devices?register_mac=${encodeURIComponent(row.mac_address)}&register_name=${encodeURIComponent(row.hostname ?? '')}`}
                                                    >
                                                        Register
                                                    </Link>
                                                </Button>
                                            ) : row.member_id ? (
                                                <Button asChild variant="ghost" size="sm">
                                                    <Link href={`/members/${row.member_id}`}>History</Link>
                                                </Button>
                                            ) : null}
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                )}

                {connections.total > 0 && (
                    <nav aria-label="Pagination" className="flex flex-wrap items-center justify-between gap-3 text-sm">
                        <p className="text-muted-foreground">
                            Showing {connections.from ?? 0}&ndash;{connections.to ?? 0} of {connections.total}
                        </p>
                        <div className="flex items-center gap-3">
                            <Button type="button" variant="outline" size="sm" disabled={connections.current_page <= 1} onClick={() => visit({ page: connections.current_page - 1 })}>
                                Previous
                            </Button>
                            <span aria-current="page">
                                Page {connections.current_page} of {connections.last_page}
                            </span>
                            <Button type="button" variant="outline" size="sm" disabled={connections.current_page >= connections.last_page} onClick={() => visit({ page: connections.current_page + 1 })}>
                                Next
                            </Button>
                        </div>
                    </nav>
                )}

                <p className="text-xs text-muted-foreground">
                    Viewing this list and exporting it are recorded in the audit log. Network metadata may be monitored under school policy for network administration.
                </p>
            </div>
        </AppLayout>
    );
}