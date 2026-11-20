import { Head, Link, router } from '@inertiajs/react';
import { ArrowLeft, CircleCheck, CircleMinus, Network } from 'lucide-react';

import { EmptyState } from '@/components/empty-state';
import { FlashMessages } from '@/components/flash-messages';
import { Pill } from '@/components/status-badge';
import { Button } from '@/components/ui/button';
import AppLayout from '@/layouts/app-layout';
import { timeAgo } from '@/lib/format';
import { formatDateTime } from '@/lib/office-network';
import type { Paginated } from '@/types/campusnet';
import type { AssignmentRow, ConnectionRow } from '@/types/connected-members';

type Props = {
    member: {
        id: number;
        full_name: string;
        employee_id: string | null;
        position: string | null;
        office_name: string | null;
        is_active: boolean;
    };
    devices: AssignmentRow[];
    connections: Paginated<ConnectionRow>;
    last_seen_at: string | null;
};

export default function MemberShow({ member, devices, connections, last_seen_at }: Props) {
    const base = `/members/${member.id}`;

    const goTo = (page: number) =>
        router.get(base, page > 1 ? { page } : {}, { preserveState: true, preserveScroll: true, replace: true, only: ['connections'] });

    return (
        <AppLayout
            breadcrumbs={[
                { title: 'Connected Members', href: '/connected-members' },
                { title: 'Members', href: '/members' },
                { title: member.full_name, href: base },
            ]}
        >
            <Head title={member.full_name} />

            <div className="flex flex-col gap-4 p-4">
                <div>
                    <Link href="/members" className="inline-flex items-center gap-1 text-sm text-muted-foreground hover:text-foreground">
                        <ArrowLeft className="size-4" aria-hidden="true" />
                        All members
                    </Link>

                    <div className="mt-2 flex flex-wrap items-center gap-3">
                        <h1 className="text-xl font-semibold">{member.full_name}</h1>
                        <Pill tone={member.is_active ? 'green' : 'gray'}>{member.is_active ? 'Active' : 'Inactive'}</Pill>
                    </div>
                    <p className="text-sm text-muted-foreground">
                        {[member.office_name, member.employee_id, member.position].filter(Boolean).join(' \u00b7 ') || 'No office set'}
                        {' \u00b7 '}
                        {last_seen_at ? `last detected connection ${timeAgo(last_seen_at)}` : 'no connection detected yet'}
                    </p>
                </div>

                <FlashMessages />

                <section aria-labelledby="devices-heading" className="grid gap-2">
                    <h2 id="devices-heading" className="text-base font-semibold">
                        Associated devices
                    </h2>

                    {devices.length === 0 ? (
                        <EmptyState icon={Network} title="No devices assigned" description="An administrator needs to register a device and assign it to this member." />
                    ) : (
                        <div className="overflow-x-auto rounded-xl border">
                            <table className="w-full text-sm">
                                <caption className="sr-only">Devices assigned to {member.full_name}, current and past</caption>
                                <thead className="bg-muted/50 text-left text-xs text-muted-foreground">
                                    <tr>
                                        <th scope="col" className="px-3 py-2 font-medium">Device</th>
                                        <th scope="col" className="px-3 py-2 font-medium">MAC address</th>
                                        <th scope="col" className="px-3 py-2 font-medium">Assigned</th>
                                        <th scope="col" className="px-3 py-2 font-medium">Until</th>
                                        <th scope="col" className="px-3 py-2 font-medium">Status</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    {devices.map((row) => (
                                        <tr key={row.id} className="border-t align-top">
                                            <td className="px-3 py-2">
                                                <div>{row.device_name ?? 'Unknown device'}</div>
                                                <div className="mt-0.5 text-xs text-muted-foreground">{row.device_type ?? '\u2014'}</div>
                                            </td>
                                            <td className="px-3 py-2 font-mono text-xs">{row.mac_address ?? '\u2014'}</td>
                                            <td className="px-3 py-2 text-muted-foreground">{formatDateTime(row.assigned_at)}</td>
                                            <td className="px-3 py-2 text-muted-foreground">{row.unassigned_at ? formatDateTime(row.unassigned_at) : '\u2014'}</td>
                                            <td className="px-3 py-2">
                                                <Pill tone={row.current ? 'green' : 'gray'}>{row.current ? 'Current' : 'Past'}</Pill>
                                            </td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        </div>
                    )}
                </section>

                <section aria-labelledby="history-heading" className="grid gap-2">
                    <h2 id="history-heading" className="text-base font-semibold">
                        Connection history
                    </h2>

                    {connections.data.length === 0 ? (
                        <EmptyState icon={Network} title="No connections recorded" description="Connections appear here when a data source sees one of this member's registered devices." />
                    ) : (
                        <div className="overflow-x-auto rounded-xl border">
                            <table className="w-full text-sm">
                                <caption className="sr-only">
                                    Connections of {member.full_name}, page {connections.current_page} of {connections.last_page}
                                </caption>
                                <thead className="bg-muted/50 text-left text-xs text-muted-foreground">
                                    <tr>
                                        <th scope="col" className="px-3 py-2 font-medium">Device</th>
                                        <th scope="col" className="px-3 py-2 font-medium">IP address</th>
                                        <th scope="col" className="px-3 py-2 font-medium">Connected since</th>
                                        <th scope="col" className="px-3 py-2 font-medium">Last seen</th>
                                        <th scope="col" className="px-3 py-2 font-medium">Status</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    {connections.data.map((row) => (
                                        <tr key={row.id} className="border-t align-top">
                                            <td className="px-3 py-2">
                                                <div>{row.device_name ?? row.hostname ?? 'Unknown device'}</div>
                                                <div className="mt-0.5 text-xs text-muted-foreground">{[row.office_name, row.interface_name].filter(Boolean).join(' \u00b7 ') || '\u2014'}</div>
                                            </td>
                                            <td className="px-3 py-2 font-mono text-xs">{row.ip_address ?? '\u2014'}</td>
                                            <td className="px-3 py-2 text-muted-foreground">{formatDateTime(row.started_at)}</td>
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
                                <Button type="button" variant="outline" size="sm" disabled={connections.current_page <= 1} onClick={() => goTo(connections.current_page - 1)}>
                                    Previous
                                </Button>
                                <span aria-current="page">
                                    Page {connections.current_page} of {connections.last_page}
                                </span>
                                <Button type="button" variant="outline" size="sm" disabled={connections.current_page >= connections.last_page} onClick={() => goTo(connections.current_page + 1)}>
                                    Next
                                </Button>
                            </div>
                        </nav>
                    )}
                </section>
            </div>
        </AppLayout>
    );
}