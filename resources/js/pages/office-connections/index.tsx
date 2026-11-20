import { Head, Link, router, useForm } from '@inertiajs/react';
import { Building2, Info, Pencil, Plus, Search } from 'lucide-react';
import { useEffect, useRef, useState, type FormEvent } from 'react';

import { EmptyState } from '@/components/empty-state';
import { FlashMessages } from '@/components/flash-messages';
import { OFFICE_STATUS_OPTIONS, OfficeStatusPill } from '@/components/office-status-pill';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import AppLayout from '@/layouts/app-layout';
import { timeAgo } from '@/lib/format';
import { formatBytes } from '@/lib/office-network';
import type { OfficeRow, OfficeSource, OfficeTotals } from '@/types/office-network';

type Filters = { search: string; status: string };

type Props = {
    offices: OfficeRow[];
    totals: OfficeTotals;
    source: OfficeSource;
    filters: Filters;
    can: { manage: boolean; members: boolean };
};

const SELECT =
    'border-input bg-background h-9 rounded-md border px-2 text-sm shadow-xs outline-none focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50';

const RELOAD = ['offices', 'totals', 'filters'];

function Notice({ children }: { children: React.ReactNode }) {
    return (
        <div role="status" className="flex items-start gap-2 rounded-xl border bg-muted/40 p-3 text-sm text-muted-foreground">
            <Info className="mt-0.5 size-4 shrink-0" aria-hidden="true" />
            <p>{children}</p>
        </div>
    );
}

function SourceNotice({ source }: { source: OfficeSource }) {
    if (!source.enabled) {
        return (
            <Notice>
                No connection data source is enabled, so CampusNet cannot tell which devices are connected and shows no counts. Connection data comes
                from the MikroTik DHCP lease source. Nothing is estimated while it is off.
            </Notice>
        );
    }

    if (source.failing) {
        return <Notice>The last attempt to read the DHCP leases failed. The numbers below may be out of date.</Notice>;
    }

    if (source.stale) {
        return (
            <Notice>
                Connection data may be out of date.{' '}
                {source.last_run_at ? `It was last collected ${timeAgo(source.last_run_at)}.` : 'It has not been collected yet.'}
            </Notice>
        );
    }

    return null;
}

function OfficeForm({ office, onDone }: { office: OfficeRow | null; onDone: () => void }) {
    const form = useForm({
        name: office?.name ?? '',
        code: office?.code ?? '',
        department: office?.department ?? '',
        subnet_cidr: office?.subnet_cidr ?? '',
        description: office?.description ?? '',
    });

    const fields = [
        { key: 'name', label: 'Office name', placeholder: 'CIDS Office', required: true },
        { key: 'code', label: 'Code', placeholder: 'CIDS' },
        { key: 'department', label: 'Department', placeholder: 'College of ...' },
        { key: 'subnet_cidr', label: 'Subnet (optional)', placeholder: '192.168.10.0/24' },
    ] as const;

    const submit = (event: FormEvent) => {
        event.preventDefault();
        const options = { preserveScroll: true, onSuccess: onDone };

        if (office) {
            form.patch(`/offices/${office.id}`, options);
        } else {
            form.post('/offices', options);
        }
    };

    const archive = () => {
        if (office && window.confirm(`Archive ${office.name}? Its history is kept.`)) {
            router.delete(`/offices/${office.id}`, { preserveScroll: true, onSuccess: onDone });
        }
    };

    return (
        <form onSubmit={submit} className="grid gap-3 rounded-xl border p-4" aria-label={office ? `Edit ${office.name}` : 'Add office'}>
            <h2 className="text-sm font-semibold">{office ? `Edit ${office.name}` : 'Add office'}</h2>

            <div className="grid gap-3 sm:grid-cols-2">
                {fields.map((field) => (
                    <div key={field.key} className="grid gap-1">
                        <label htmlFor={`office-${field.key}`} className="text-xs text-muted-foreground">
                            {field.label}
                        </label>
                        <Input
                            id={`office-${field.key}`}
                            value={form.data[field.key]}
                            onChange={(event) => form.setData(field.key, event.target.value)}
                            placeholder={field.placeholder}
                            aria-invalid={Boolean(form.errors[field.key])}
                            required={'required' in field}
                        />
                        {form.errors[field.key] && <p className="text-xs text-red-500">{form.errors[field.key]}</p>}
                    </div>
                ))}
            </div>

            <div className="grid gap-1">
                <label htmlFor="office-description" className="text-xs text-muted-foreground">
                    Description (optional)
                </label>
                <textarea
                    id="office-description"
                    rows={2}
                    value={form.data.description}
                    onChange={(event) => form.setData('description', event.target.value)}
                    className={`${SELECT} h-auto py-2`}
                />
                {form.errors.description && <p className="text-xs text-red-500">{form.errors.description}</p>}
            </div>

            <div className="flex flex-wrap items-center gap-2">
                <Button type="submit" disabled={form.processing}>
                    {office ? 'Save changes' : 'Add office'}
                </Button>
                <Button type="button" variant="ghost" onClick={onDone}>
                    Cancel
                </Button>
                {office && (
                    <Button type="button" variant="ghost" className="ml-auto text-red-500" onClick={archive}>
                        Archive office
                    </Button>
                )}
            </div>
        </form>
    );
}

export default function OfficeConnectionsIndex({ offices, totals, source, filters, can }: Props) {
    const [search, setSearch] = useState(filters.search);
    const [editing, setEditing] = useState<OfficeRow | 'new' | null>(null);
    const first = useRef(true);

    const visit = (changes: Partial<Filters>) => {
        const next = { ...filters, ...changes };
        const query: Record<string, string> = {};

        (['search', 'status'] as const).forEach((key) => {
            if (next[key]) {
                query[key] = next[key];
            }
        });

        router.get('/office-connections', query, { preserveState: true, preserveScroll: true, replace: true, only: RELOAD });
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

    const hasFilters = Boolean(filters.search || filters.status);

    const clearFilters = () => {
        setSearch('');
        router.get('/office-connections', {}, { preserveState: true, preserveScroll: true, replace: true, only: RELOAD });
    };

    const known = source.enabled;
    const cards = [
        { label: 'Offices', value: totals.offices, hint: 'Active offices and departments' },
        { label: 'Devices online', value: known ? totals.online_devices : '\u2014', hint: 'Registered devices seen recently' },
        { label: 'Registered devices', value: totals.registered_devices, hint: 'Assigned to a member or an office' },
        { label: 'Unidentified online', value: known ? totals.unidentified_online : '\u2014', hint: 'Connected but not registered' },
    ];

    return (
        <AppLayout breadcrumbs={[{ title: 'Office Connections', href: '/office-connections' }]}>
            <Head title="Office Connections" />

            <div className="flex flex-col gap-4 p-4">
                <div className="flex flex-wrap items-start justify-between gap-3">
                    <div>
                        <h1 className="text-xl font-semibold">Office Connections</h1>
                        <p className="text-sm text-muted-foreground">
                            Devices connected to the campus network, grouped by office. Counts come from stored connection records, never from estimates.
                        </p>
                    </div>

                    {can.manage && editing === null && (
                        <Button type="button" onClick={() => setEditing('new')}>
                            <Plus className="size-4" aria-hidden="true" />
                            Add office
                        </Button>
                    )}
                </div>

                <FlashMessages />
                <SourceNotice source={source} />

                {editing !== null && (
                    <OfficeForm key={editing === 'new' ? 'new' : editing.id} office={editing === 'new' ? null : editing} onDone={() => setEditing(null)} />
                )}

                <dl className="grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
                    {cards.map((card) => (
                        <div key={card.label} className="rounded-xl border p-4">
                            <dt className="text-xs text-muted-foreground">{card.label}</dt>
                            <dd className="mt-1 text-2xl font-semibold tabular-nums">{card.value}</dd>
                            <p className="mt-1 text-xs text-muted-foreground">{card.hint}</p>
                        </div>
                    ))}
                </dl>

                <form role="search" aria-label="Filter offices" onSubmit={(event) => event.preventDefault()} className="flex flex-wrap items-end gap-3">
                    <div className="relative min-w-56 flex-1">
                        <label htmlFor="office-search" className="sr-only">
                            Search offices
                        </label>
                        <Search className="pointer-events-none absolute top-2.5 left-2.5 size-4 text-muted-foreground" aria-hidden="true" />
                        <Input
                            id="office-search"
                            type="search"
                            value={search}
                            onChange={(event) => setSearch(event.target.value)}
                            placeholder="Search office, code or department"
                            className="pl-8"
                        />
                    </div>

                    <div className="grid gap-1">
                        <label htmlFor="filter-status" className="text-xs text-muted-foreground">
                            Network status
                        </label>
                        <select id="filter-status" className={SELECT} value={filters.status} onChange={(event) => visit({ status: event.target.value })}>
                            <option value="">Any status</option>
                            {OFFICE_STATUS_OPTIONS.map((option) => (
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

                {offices.length === 0 ? (
                    hasFilters ? (
                        <EmptyState icon={Search} title="No offices match these filters" description="Try a different search term or clear the filters.">
                            <Button type="button" variant="outline" onClick={clearFilters}>
                                Clear filters
                            </Button>
                        </EmptyState>
                    ) : (
                        <EmptyState
                            icon={Building2}
                            title="No offices yet"
                            description={
                                can.manage
                                    ? 'Add the offices and departments of the campus first, for example CIDS Office. Members and devices are linked to them next.'
                                    : 'A Super Administrator needs to add the offices and departments first.'
                            }
                        >
                            {can.manage && (
                                <Button type="button" onClick={() => setEditing('new')}>
                                    <Plus className="size-4" aria-hidden="true" />
                                    Add office
                                </Button>
                            )}
                        </EmptyState>
                    )
                ) : (
                    <div className="overflow-x-auto rounded-xl border">
                        <table className="w-full text-sm">
                            <caption className="sr-only">Offices with their connected device counts</caption>
                            <thead className="bg-muted/50 text-left text-xs text-muted-foreground">
                                <tr>
                                    <th scope="col" className="px-3 py-2 font-medium">Office</th>
                                    <th scope="col" className="px-3 py-2 font-medium">Members</th>
                                    <th scope="col" className="px-3 py-2 font-medium">Registered</th>
                                    <th scope="col" className="px-3 py-2 font-medium">Online</th>
                                    <th scope="col" className="px-3 py-2 font-medium">Offline</th>
                                    <th scope="col" className="px-3 py-2 font-medium">Traffic</th>
                                    <th scope="col" className="px-3 py-2 font-medium">Status</th>
                                    <th scope="col" className="px-3 py-2 font-medium">Last update</th>
                                    <th scope="col" className="px-3 py-2 text-right font-medium">Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                {offices.map((row) => (
                                    <tr key={row.id} className="border-t align-top">
                                        <td className="px-3 py-2">
                                            <Link href={`/office-connections/${row.id}`} className="font-medium underline-offset-2 hover:underline">
                                                {row.name}
                                            </Link>
                                            <div className="mt-0.5 text-xs text-muted-foreground">
                                                {[row.code, row.department, row.subnet_cidr].filter(Boolean).join(' \u00b7 ') || '\u2014'}
                                            </div>
                                        </td>
                                        <td className="px-3 py-2 tabular-nums">{row.members}</td>
                                        <td className="px-3 py-2 tabular-nums">{row.registered_devices}</td>
                                        <td className="px-3 py-2 tabular-nums">{known ? row.online_devices : '\u2014'}</td>
                                        <td className="px-3 py-2 tabular-nums">{known ? row.offline_devices : '\u2014'}</td>
                                        <td className="px-3 py-2">
                                            {row.rx_bytes === null || row.tx_bytes === null ? (
                                                <span className="text-muted-foreground" title="The enabled sources do not measure traffic per device">
                                                    Not measured
                                                </span>
                                            ) : (
                                                <span className="tabular-nums" title="Transferred by the devices connected now">
                                                    <span aria-label="Download">&darr;</span> {formatBytes(row.rx_bytes)} &middot;{' '}
                                                    <span aria-label="Upload">&uarr;</span> {formatBytes(row.tx_bytes)}
                                                </span>
                                            )}
                                        </td>
                                        <td className="px-3 py-2">
                                            <OfficeStatusPill status={row.status} />
                                        </td>
                                        <td className="px-3 py-2 text-muted-foreground">{row.last_seen_at ? timeAgo(row.last_seen_at) : 'No connection recorded'}</td>
                                        <td className="px-3 py-2 text-right">
                                            <div className="flex justify-end gap-1">
                                                <Button asChild variant="ghost" size="sm">
                                                    <Link href={`/office-connections/${row.id}`}>View</Link>
                                                </Button>
                                                {can.manage && (
                                                    <Button type="button" variant="ghost" size="sm" onClick={() => setEditing(row)} aria-label={`Edit ${row.name}`}>
                                                        <Pencil className="size-4" aria-hidden="true" />
                                                        Edit
                                                    </Button>
                                                )}
                                            </div>
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                )}
            </div>
        </AppLayout>
    );
}