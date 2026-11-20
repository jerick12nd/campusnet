import { Head, router, useForm } from '@inertiajs/react';
import { Laptop, Pencil, Plus, Search } from 'lucide-react';
import { useEffect, useRef, useState, type FormEvent } from 'react';

import { EmptyState } from '@/components/empty-state';
import { FlashMessages } from '@/components/flash-messages';
import { OfficeNetworkTabs } from '@/components/office-network-tabs';
import { Pill } from '@/components/status-badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import AppLayout from '@/layouts/app-layout';
import { timeAgo } from '@/lib/format';
import type { Option, Paginated } from '@/types/campusnet';
import type { DeviceListRow, OfficeOption } from '@/types/connected-members';

type Filters = { search: string; type: string; assignment: string; state: string };

type Props = {
    devices: Paginated<DeviceListRow>;
    typeOptions: Option[];
    offices: OfficeOption[];
    members: { id: number; label: string }[];
    prefill: { mac: string; name: string } | null;
    filters: Filters;
    can: { manage: boolean };
};

const SELECT =
    'border-input bg-background h-9 rounded-md border px-2 text-sm shadow-xs outline-none focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50';

const FILTER_KEYS = ['search', 'type', 'assignment', 'state'] as const;
const RELOAD = ['devices', 'filters'];

function DeviceForm({
    device,
    prefill,
    typeOptions,
    offices,
    onDone,
}: {
    device: DeviceListRow | null;
    prefill: { mac: string; name: string } | null;
    typeOptions: Option[];
    offices: OfficeOption[];
    onDone: () => void;
}) {
    const form = useForm({
        name: device?.name ?? prefill?.name ?? '',
        device_type: device?.device_type ?? 'laptop',
        mac_address: prefill?.mac ?? '',
        office_id: device?.office_id ? String(device.office_id) : '',
        notes: device?.notes ?? '',
        is_active: device?.is_active ?? true,
    });

    const submit = (event: FormEvent) => {
        event.preventDefault();
        const options = { preserveScroll: true, onSuccess: onDone };

        if (device) {
            form.patch(`/registered-devices/${device.id}`, options);
        } else {
            form.post('/registered-devices', options);
        }
    };

    return (
        <form onSubmit={submit} className="grid gap-3 rounded-xl border p-4" aria-label={device ? `Edit ${device.name}` : 'Register device'}>
            <h2 className="text-sm font-semibold">{device ? `Edit ${device.name}` : 'Register device'}</h2>

            <div className="grid gap-3 sm:grid-cols-2">
                <div className="grid gap-1">
                    <label htmlFor="device-name" className="text-xs text-muted-foreground">
                        Device name
                    </label>
                    <Input id="device-name" value={form.data.name} onChange={(event) => form.setData('name', event.target.value)} placeholder="Maria's laptop" required />
                    {form.errors.name && <p className="text-xs text-red-500">{form.errors.name}</p>}
                </div>

                <div className="grid gap-1">
                    <label htmlFor="device-type" className="text-xs text-muted-foreground">
                        Device type
                    </label>
                    <select id="device-type" className={SELECT} value={form.data.device_type} onChange={(event) => form.setData('device_type', event.target.value)}>
                        {typeOptions.map((option) => (
                            <option key={option.value} value={option.value}>
                                {option.label}
                            </option>
                        ))}
                    </select>
                    {form.errors.device_type && <p className="text-xs text-red-500">{form.errors.device_type}</p>}
                </div>

                <div className="grid gap-1">
                    <label htmlFor="device-mac" className="text-xs text-muted-foreground">
                        MAC address
                    </label>
                    {device ? (
                        <p id="device-mac" className="h-9 py-2 font-mono text-sm">
                            {device.mac_address}
                        </p>
                    ) : (
                        <Input id="device-mac" value={form.data.mac_address} onChange={(event) => form.setData('mac_address', event.target.value)} placeholder="AA:BB:CC:DD:EE:FF" className="font-mono" required />
                    )}
                    {form.errors.mac_address && <p className="text-xs text-red-500">{form.errors.mac_address}</p>}
                </div>

                <div className="grid gap-1">
                    <label htmlFor="device-office" className="text-xs text-muted-foreground">
                        Office (only for shared equipment)
                    </label>
                    <select id="device-office" className={SELECT} value={form.data.office_id} onChange={(event) => form.setData('office_id', event.target.value)}>
                        <option value="">None: takes the member&apos;s office</option>
                        {offices.map((office) => (
                            <option key={office.id} value={String(office.id)}>
                                {office.name}
                            </option>
                        ))}
                    </select>
                    {form.errors.office_id && <p className="text-xs text-red-500">{form.errors.office_id}</p>}
                </div>
            </div>

            <div className="grid gap-1">
                <label htmlFor="device-notes" className="text-xs text-muted-foreground">
                    Notes (optional)
                </label>
                <Input id="device-notes" value={form.data.notes} onChange={(event) => form.setData('notes', event.target.value)} />
                {form.errors.notes && <p className="text-xs text-red-500">{form.errors.notes}</p>}
            </div>

            {device && (
                <label className="flex items-center gap-2 text-sm">
                    <input type="checkbox" checked={form.data.is_active} onChange={(event) => form.setData('is_active', event.target.checked)} />
                    Active device (switching it off also unassigns it)
                </label>
            )}

            <div className="flex flex-wrap gap-2">
                <Button type="submit" disabled={form.processing}>
                    {device ? 'Save changes' : 'Register device'}
                </Button>
                <Button type="button" variant="ghost" onClick={onDone}>
                    Cancel
                </Button>
            </div>
        </form>
    );
}

export default function RegisteredDevicesIndex({ devices, typeOptions, offices, members, prefill, filters, can }: Props) {
    const [search, setSearch] = useState(filters.search);
    const [editing, setEditing] = useState<DeviceListRow | 'new' | null>(prefill ? 'new' : null);
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

        router.get('/registered-devices', query, { preserveState: true, preserveScroll: true, replace: true, only: RELOAD });
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
        router.get('/registered-devices', {}, { preserveState: true, preserveScroll: true, replace: true, only: RELOAD });
    };

    const assign = (row: DeviceListRow, memberId: string) => {
        if (memberId) {
            router.post(`/registered-devices/${row.id}/assign`, { member_id: memberId }, { preserveScroll: true });
        }
    };

    const unassign = (row: DeviceListRow) => {
        if (window.confirm(`Unassign ${row.name} from ${row.member_name}?`)) {
            router.delete(`/registered-devices/${row.id}/assignment`, { preserveScroll: true });
        }
    };

    const toggleActive = (row: DeviceListRow) => {
        router.patch(
            `/registered-devices/${row.id}`,
            {
                name: row.name,
                device_type: row.device_type,
                notes: row.notes ?? '',
                office_id: row.office_id ?? '',
                is_active: !row.is_active,
            },
            { preserveScroll: true },
        );
    };

    return (
        <AppLayout breadcrumbs={[{ title: 'Connected Members', href: '/connected-members' }, { title: 'Registered devices', href: '/registered-devices' }]}>
            <Head title="Registered devices" />

            <div className="flex flex-col gap-4 p-4">
                <div className="flex flex-wrap items-start justify-between gap-3">
                    <div>
                        <h1 className="text-xl font-semibold">Registered devices</h1>
                        <p className="text-sm text-muted-foreground">
                            Devices an administrator registered by MAC address. A device is linked to a member only by assigning it here, never guessed from an IP address.
                        </p>
                    </div>

                    {can.manage && editing === null && (
                        <Button type="button" onClick={() => setEditing('new')}>
                            <Plus className="size-4" aria-hidden="true" />
                            Register device
                        </Button>
                    )}
                </div>

                <OfficeNetworkTabs current="devices" />
                <FlashMessages />

                {can.manage && editing !== null && (
                    <DeviceForm
                        key={editing === 'new' ? 'new' : editing.id}
                        device={editing === 'new' ? null : editing}
                        prefill={editing === 'new' ? prefill : null}
                        typeOptions={typeOptions}
                        offices={offices}
                        onDone={() => setEditing(null)}
                    />
                )}

                <form role="search" aria-label="Filter registered devices" onSubmit={(event) => event.preventDefault()} className="flex flex-wrap items-end gap-3">
                    <div className="relative min-w-56 flex-1">
                        <label htmlFor="devices-search" className="sr-only">
                            Search registered devices
                        </label>
                        <Search className="pointer-events-none absolute top-2.5 left-2.5 size-4 text-muted-foreground" aria-hidden="true" />
                        <Input id="devices-search" type="search" value={search} onChange={(event) => setSearch(event.target.value)} placeholder="Search device, MAC address or member" className="pl-8" />
                    </div>

                    <div className="grid gap-1">
                        <label htmlFor="devices-type" className="text-xs text-muted-foreground">
                            Type
                        </label>
                        <select id="devices-type" className={SELECT} value={filters.type} onChange={(event) => visit({ type: event.target.value })}>
                            <option value="">All types</option>
                            {typeOptions.map((option) => (
                                <option key={option.value} value={option.value}>
                                    {option.label}
                                </option>
                            ))}
                        </select>
                    </div>

                    <div className="grid gap-1">
                        <label htmlFor="devices-assignment" className="text-xs text-muted-foreground">
                            Assignment
                        </label>
                        <select id="devices-assignment" className={SELECT} value={filters.assignment} onChange={(event) => visit({ assignment: event.target.value })}>
                            <option value="">Assigned and not</option>
                            <option value="assigned">Assigned</option>
                            <option value="unassigned">Not assigned</option>
                        </select>
                    </div>

                    <div className="grid gap-1">
                        <label htmlFor="devices-state" className="text-xs text-muted-foreground">
                            State
                        </label>
                        <select id="devices-state" className={SELECT} value={filters.state} onChange={(event) => visit({ state: event.target.value })}>
                            <option value="">Active and inactive</option>
                            <option value="active">Active</option>
                            <option value="inactive">Inactive</option>
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
                        <EmptyState
                            icon={Laptop}
                            title="No registered devices yet"
                            description={can.manage ? 'Register a device by its MAC address, then assign it to a member.' : 'A Super Administrator needs to register the devices first.'}
                        />
                    )
                ) : (
                    <div className="overflow-x-auto rounded-xl border">
                        <table className="w-full text-sm">
                            <caption className="sr-only">
                                Registered devices, page {devices.current_page} of {devices.last_page}, {devices.total} in total
                            </caption>
                            <thead className="bg-muted/50 text-left text-xs text-muted-foreground">
                                <tr>
                                    <th scope="col" className="px-3 py-2 font-medium">Device</th>
                                    <th scope="col" className="px-3 py-2 font-medium">MAC address</th>
                                    <th scope="col" className="px-3 py-2 font-medium">Member</th>
                                    <th scope="col" className="px-3 py-2 font-medium">Office</th>
                                    <th scope="col" className="px-3 py-2 font-medium">Last seen</th>
                                    <th scope="col" className="px-3 py-2 font-medium">State</th>
                                    {can.manage && <th scope="col" className="px-3 py-2 text-right font-medium">Actions</th>}
                                </tr>
                            </thead>
                            <tbody>
                                {devices.data.map((row) => (
                                    <tr key={row.id} className="border-t align-top">
                                        <td className="px-3 py-2">
                                            <div className="font-medium">{row.name}</div>
                                            <div className="mt-0.5 text-xs text-muted-foreground">{row.device_type_label}</div>
                                        </td>
                                        <td className="px-3 py-2">
                                            <div className="font-mono text-xs">{row.mac_address}</div>
                                            {row.private_mac && <div className="mt-0.5 text-xs text-muted-foreground">Private Wi-Fi address</div>}
                                        </td>
                                        <td className="px-3 py-2">{row.member_name ?? <span className="text-muted-foreground">Not assigned</span>}</td>
                                        <td className="px-3 py-2">{row.office_name ?? '\u2014'}</td>
                                        <td className="px-3 py-2 text-muted-foreground">{row.last_seen_at ? timeAgo(row.last_seen_at) : 'Never seen'}</td>
                                        <td className="px-3 py-2">
                                            <Pill tone={row.is_active ? 'green' : 'gray'}>{row.is_active ? 'Active' : 'Inactive'}</Pill>
                                        </td>
                                        {can.manage && (
                                            <td className="px-3 py-2">
                                                <div className="flex flex-wrap items-center justify-end gap-1">
                                                    {row.is_active && (
                                                        <>
                                                            <label htmlFor={`assign-${row.id}`} className="sr-only">
                                                                {row.member_id ? `Reassign ${row.name}` : `Assign ${row.name}`}
                                                            </label>
                                                            <select
                                                                id={`assign-${row.id}`}
                                                                className={SELECT}
                                                                defaultValue=""
                                                                onChange={(event) => {
                                                                    assign(row, event.target.value);
                                                                    event.target.value = '';
                                                                }}
                                                            >
                                                                <option value="">{row.member_id ? 'Reassign to...' : 'Assign to...'}</option>
                                                                {members
                                                                    .filter((member) => member.id !== row.member_id)
                                                                    .map((member) => (
                                                                        <option key={member.id} value={String(member.id)}>
                                                                            {member.label}
                                                                        </option>
                                                                    ))}
                                                            </select>
                                                            {row.member_id && (
                                                                <Button type="button" variant="ghost" size="sm" onClick={() => unassign(row)}>
                                                                    Unassign
                                                                </Button>
                                                            )}
                                                        </>
                                                    )}
                                                    <Button type="button" variant="ghost" size="sm" onClick={() => setEditing(row)} aria-label={`Edit ${row.name}`}>
                                                        <Pencil className="size-4" aria-hidden="true" />
                                                        Edit
                                                    </Button>
                                                    <Button type="button" variant="ghost" size="sm" onClick={() => toggleActive(row)}>
                                                        {row.is_active ? 'Deactivate' : 'Activate'}
                                                    </Button>
                                                </div>
                                            </td>
                                        )}
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                )}

                {devices.total > 0 && (
                    <nav aria-label="Pagination" className="flex flex-wrap items-center justify-between gap-3 text-sm">
                        <p className="text-muted-foreground">
                            Showing {devices.from ?? 0}&ndash;{devices.to ?? 0} of {devices.total}
                        </p>
                        <div className="flex items-center gap-3">
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