import { Head, Link, router } from '@inertiajs/react';
import { ArrowLeft, CircleCheck, CircleMinus, Lock, Network } from 'lucide-react';

import { EmptyState } from '@/components/empty-state';
import { FlashMessages } from '@/components/flash-messages';
import { OfficeStatusPill } from '@/components/office-status-pill';
import { Pill } from '@/components/status-badge';
import { Button } from '@/components/ui/button';
import AppLayout from '@/layouts/app-layout';
import { timeAgo } from '@/lib/format';
import type { Paginated } from '@/types/campusnet';
import type { OfficeRow, OfficeSource, SessionRow } from '@/types/office-network';

type Props = {
    office: OfficeRow;
    source: OfficeSource;
    connections: Paginated<SessionRow> | null;
    filters: { state: string };
    can: { members: boolean };
};

const SELECT =
    'border-input bg-background h-9 rounded-md border px-2 text-sm shadow-xs outline-none focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50';

export default function OfficeConnectionShow({ office, source, connections, filters, can }: Props) {
    const base = `/office-connections/${office.id}`;

    const visit = (changes: { state?: string; page?: number }) => {
        const query: Record<string, string | number> = { state: changes.state ?? filters.state };

        if (changes.page && changes.page > 1) {
            query.page = changes.page;
        }

        router.get(base, query, { preserveState: true, preserveScroll: true, replace: true, only: ['connections', 'filters'] });
    };

    const known = source.enabled;
    const stats = [
        { label: 'Members', value: office.members },
        { label: 'Registered devices', value: office.registered_devices },
        { label: 'Online', value: known ? office.online_devices : '\u2014' },
        { label: 'Offline', value: known ? office.offline_devices : '\u2014' },
    ];

    return (
        <AppLayout
            breadcrumbs={[
                { title: 'Office Connections', href: '/office-connections' },
                { title: office.name, href: base },
            ]}
        >
            <Head title={office.name} />

            <div className="flex flex-col gap-4 p-4">
                <div>
                    <Link href="/office-connections" className="inline-flex items-center gap-1 text-sm text-muted-foreground hover:text-foreground">
                        <ArrowLeft className="size-4" aria-hidden="true" />
                        All offices
                    </Link>

                    <div className="mt-2 flex flex-wrap items-center gap-3">
                        <h1 className="text-xl font-semibold">{office.name}</h1>
                        <OfficeStatusPill status={office.status} />
                    </div>
                    <p className="text-sm text-muted-foreground">
                        {[office.code, office.department, office.subnet_cidr].filter(Boolean).join(' \u00b7 ') || 'No code, department or subnet set'}
                        {' \u00b7 '}
                        {office.last_seen_at ? `last connection seen ${timeAgo(office.last_seen_at)}` : 'no connection recorded yet'}
                    </p>
                </div>

                <FlashMessages />

                <dl className="grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
                    {stats.map((stat) => (
                        <div key={stat.label} className="rounded-xl border p-4">
                            <dt className="text-xs text-muted-foreground">{stat.label}</dt>
                            <dd className="mt-1 text-2xl font-semibold tabular-nums">{stat.value}</dd>
                        </div>
                    ))}
                </dl>

                {!can.members || connections === null ? (
                    <EmptyState
                        icon={Lock}
                        title="Member-level details are restricted"
                        description="Your role can see office totals only. Names, device addresses and connection history need the members.view permission."
                    />
                ) : (
                    <>
                        <div className="flex flex-wrap items-end justify-between gap-3">
                            <h2 className="text-base font-semibold">Device connections</h2>

                            <div className="grid gap-1">
                                <label htmlFor="filter-state" className="text-xs text-muted-foreground">
                                    Show
                                </label>
                                <select id="filter-state" className={SELECT} value={filters.state} onChange={(event) => visit({ state: event.target.value })}>
                                    <option value="online">Connected now</option>
                                    <option value="history">History (not connected)</option>
                                    <option value="all">All connections</option>
                                </select>
                            </div>
                        </div>

                        {connections.data.length === 0 ? (
                            <EmptyState
                                icon={Network}
                                title={filters.state === 'online' ? 'No devices connected now' : 'No connections recorded'}
                                description={
                                    known
                                        ? 'Only registered devices are listed here. Devices that are not registered to a member appear under Connected Members as unidentified.'
                                        : 'No connection data source is enabled, so no connections are recorded yet.'
                                }
                            />
                        ) : (
                            <div className="overflow-x-auto rounded-xl border">
                                <table className="w-full text-sm">
                                    <caption className="sr-only">
                                        Connections of {office.name}, page {connections.current_page} of {connections.last_page}
                                    </caption>
                                    <thead className="bg-muted/50 text-left text-xs text-muted-foreground">
                                        <tr>
                                            <th scope="col" className="px-3 py-2 font-medium">Member</th>
                                            <th scope="col" className="px-3 py-2 font-medium">Device</th>
                                            <th scope="col" className="px-3 py-2 font-medium">IP address</th>
                                            <th scope="col" className="px-3 py-2 font-medium">MAC address</th>
                                            <th scope="col" className="px-3 py-2 font-medium">Connected since</th>
                                            <th scope="col" className="px-3 py-2 font-medium">Last seen</th>
                                            <th scope="col" className="px-3 py-2 font-medium">Status</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        {connections.data.map((row) => (
                                            <tr key={row.id} className="border-t align-top">
                                                <td className="px-3 py-2 font-medium">
                                                    {row.member_name ?? <span className="font-normal text-muted-foreground">Shared device</span>}
                                                </td>
                                                <td className="px-3 py-2">
                                                    <div>{row.device_name ?? row.hostname ?? 'Unnamed device'}</div>
                                                    <div className="mt-0.5 text-xs text-muted-foreground">
                                                        {[row.device_type, row.interface_name].filter(Boolean).join(' \u00b7 ') || '\u2014'}
                                                    </div>
                                                </td>
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
                    </>
                )}
            </div>
        </AppLayout>
    );
}