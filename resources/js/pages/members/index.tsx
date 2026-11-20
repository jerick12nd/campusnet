import { Head, Link, router, useForm } from '@inertiajs/react';
import { Pencil, Plus, Search, Users } from 'lucide-react';
import { useEffect, useRef, useState, type FormEvent } from 'react';

import { EmptyState } from '@/components/empty-state';
import { FlashMessages } from '@/components/flash-messages';
import { OfficeNetworkTabs } from '@/components/office-network-tabs';
import { Pill } from '@/components/status-badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import AppLayout from '@/layouts/app-layout';
import { timeAgo } from '@/lib/format';
import type { Paginated } from '@/types/campusnet';
import type { MemberListRow, OfficeOption } from '@/types/connected-members';

type Filters = { search: string; office: string; status: string };

type Props = {
    members: Paginated<MemberListRow>;
    offices: OfficeOption[];
    filters: Filters;
    can: { manage: boolean };
};

const SELECT =
    'border-input bg-background h-9 rounded-md border px-2 text-sm shadow-xs outline-none focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50';

const FILTER_KEYS = ['search', 'office', 'status'] as const;
const RELOAD = ['members', 'filters'];

function MemberForm({ member, offices, onDone }: { member: MemberListRow | null; offices: OfficeOption[]; onDone: () => void }) {
    const form = useForm({
        office_id: member ? String(member.office_id) : '',
        full_name: member?.full_name ?? '',
        employee_id: member?.employee_id ?? '',
        position: member?.position ?? '',
        is_active: member?.is_active ?? true,
    });

    const submit = (event: FormEvent) => {
        event.preventDefault();
        const options = { preserveScroll: true, onSuccess: onDone };

        if (member) {
            form.patch(`/members/${member.id}`, options);
        } else {
            form.post('/members', options);
        }
    };

    return (
        <form onSubmit={submit} className="grid gap-3 rounded-xl border p-4" aria-label={member ? `Edit ${member.full_name}` : 'Add member'}>
            <h2 className="text-sm font-semibold">{member ? `Edit ${member.full_name}` : 'Add member'}</h2>

            <div className="grid gap-3 sm:grid-cols-2">
                <div className="grid gap-1">
                    <label htmlFor="member-name" className="text-xs text-muted-foreground">
                        Full name
                    </label>
                    <Input id="member-name" value={form.data.full_name} onChange={(event) => form.setData('full_name', event.target.value)} required aria-invalid={Boolean(form.errors.full_name)} />
                    {form.errors.full_name && <p className="text-xs text-red-500">{form.errors.full_name}</p>}
                </div>

                <div className="grid gap-1">
                    <label htmlFor="member-office" className="text-xs text-muted-foreground">
                        Office
                    </label>
                    <select id="member-office" className={SELECT} value={form.data.office_id} onChange={(event) => form.setData('office_id', event.target.value)} required>
                        <option value="">Choose an office</option>
                        {offices.map((office) => (
                            <option key={office.id} value={String(office.id)}>
                                {office.name}
                            </option>
                        ))}
                    </select>
                    {form.errors.office_id && <p className="text-xs text-red-500">{form.errors.office_id}</p>}
                </div>

                <div className="grid gap-1">
                    <label htmlFor="member-employee" className="text-xs text-muted-foreground">
                        Employee ID (optional)
                    </label>
                    <Input id="member-employee" value={form.data.employee_id} onChange={(event) => form.setData('employee_id', event.target.value)} />
                    {form.errors.employee_id && <p className="text-xs text-red-500">{form.errors.employee_id}</p>}
                </div>

                <div className="grid gap-1">
                    <label htmlFor="member-position" className="text-xs text-muted-foreground">
                        Position (optional)
                    </label>
                    <Input id="member-position" value={form.data.position} onChange={(event) => form.setData('position', event.target.value)} />
                    {form.errors.position && <p className="text-xs text-red-500">{form.errors.position}</p>}
                </div>
            </div>

            {member && (
                <label className="flex items-center gap-2 text-sm">
                    <input type="checkbox" checked={form.data.is_active} onChange={(event) => form.setData('is_active', event.target.checked)} />
                    Active member
                </label>
            )}

            <div className="flex flex-wrap gap-2">
                <Button type="submit" disabled={form.processing}>
                    {member ? 'Save changes' : 'Add member'}
                </Button>
                <Button type="button" variant="ghost" onClick={onDone}>
                    Cancel
                </Button>
            </div>
        </form>
    );
}

export default function MembersIndex({ members, offices, filters, can }: Props) {
    const [search, setSearch] = useState(filters.search);
    const [editing, setEditing] = useState<MemberListRow | 'new' | null>(null);
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

        router.get('/members', query, { preserveState: true, preserveScroll: true, replace: true, only: RELOAD });
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
        router.get('/members', {}, { preserveState: true, preserveScroll: true, replace: true, only: RELOAD });
    };

    return (
        <AppLayout breadcrumbs={[{ title: 'Connected Members', href: '/connected-members' }, { title: 'Members', href: '/members' }]}>
            <Head title="Members" />

            <div className="flex flex-col gap-4 p-4">
                <div className="flex flex-wrap items-start justify-between gap-3">
                    <div>
                        <h1 className="text-xl font-semibold">Members</h1>
                        <p className="text-sm text-muted-foreground">The people of each office. Their devices are linked to them on the Registered devices tab.</p>
                    </div>

                    {can.manage && editing === null && (
                        <Button type="button" onClick={() => setEditing('new')}>
                            <Plus className="size-4" aria-hidden="true" />
                            Add member
                        </Button>
                    )}
                </div>

                <OfficeNetworkTabs current="members" />
                <FlashMessages />

                {editing !== null && (
                    <MemberForm key={editing === 'new' ? 'new' : editing.id} member={editing === 'new' ? null : editing} offices={offices} onDone={() => setEditing(null)} />
                )}

                <form role="search" aria-label="Filter members" onSubmit={(event) => event.preventDefault()} className="flex flex-wrap items-end gap-3">
                    <div className="relative min-w-56 flex-1">
                        <label htmlFor="members-search" className="sr-only">
                            Search members
                        </label>
                        <Search className="pointer-events-none absolute top-2.5 left-2.5 size-4 text-muted-foreground" aria-hidden="true" />
                        <Input id="members-search" type="search" value={search} onChange={(event) => setSearch(event.target.value)} placeholder="Search name, employee ID or position" className="pl-8" />
                    </div>

                    <div className="grid gap-1">
                        <label htmlFor="members-office" className="text-xs text-muted-foreground">
                            Office
                        </label>
                        <select id="members-office" className={SELECT} value={filters.office} onChange={(event) => visit({ office: event.target.value })}>
                            <option value="">All offices</option>
                            {offices.map((office) => (
                                <option key={office.id} value={String(office.id)}>
                                    {office.name}
                                </option>
                            ))}
                        </select>
                    </div>

                    <div className="grid gap-1">
                        <label htmlFor="members-status" className="text-xs text-muted-foreground">
                            Status
                        </label>
                        <select id="members-status" className={SELECT} value={filters.status} onChange={(event) => visit({ status: event.target.value })}>
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

                {members.data.length === 0 ? (
                    hasFilters ? (
                        <EmptyState icon={Search} title="No members match these filters" description="Try a different search term or clear the filters.">
                            <Button type="button" variant="outline" onClick={clearFilters}>
                                Clear filters
                            </Button>
                        </EmptyState>
                    ) : (
                        <EmptyState
                            icon={Users}
                            title="No members yet"
                            description={can.manage ? 'Add the members of each office. Then register their devices so CampusNet can recognise them.' : 'A Super Administrator needs to add the members first.'}
                        />
                    )
                ) : (
                    <div className="overflow-x-auto rounded-xl border">
                        <table className="w-full text-sm">
                            <caption className="sr-only">
                                Members, page {members.current_page} of {members.last_page}, {members.total} in total
                            </caption>
                            <thead className="bg-muted/50 text-left text-xs text-muted-foreground">
                                <tr>
                                    <th scope="col" className="px-3 py-2 font-medium">Member</th>
                                    <th scope="col" className="px-3 py-2 font-medium">Office</th>
                                    <th scope="col" className="px-3 py-2 font-medium">Devices</th>
                                    <th scope="col" className="px-3 py-2 font-medium">Last seen</th>
                                    <th scope="col" className="px-3 py-2 font-medium">Status</th>
                                    <th scope="col" className="px-3 py-2 text-right font-medium">Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                {members.data.map((row) => (
                                    <tr key={row.id} className="border-t align-top">
                                        <td className="px-3 py-2">
                                            <Link href={`/members/${row.id}`} className="font-medium underline-offset-2 hover:underline">
                                                {row.full_name}
                                            </Link>
                                            <div className="mt-0.5 text-xs text-muted-foreground">{[row.employee_id, row.position].filter(Boolean).join(' \u00b7 ') || '\u2014'}</div>
                                        </td>
                                        <td className="px-3 py-2">{row.office_name ?? '\u2014'}</td>
                                        <td className="px-3 py-2 tabular-nums">{row.devices_count}</td>
                                        <td className="px-3 py-2 text-muted-foreground">{row.last_seen_at ? timeAgo(row.last_seen_at) : 'Never seen'}</td>
                                        <td className="px-3 py-2">
                                            <Pill tone={row.is_active ? 'green' : 'gray'}>{row.is_active ? 'Active' : 'Inactive'}</Pill>
                                        </td>
                                        <td className="px-3 py-2 text-right">
                                            <div className="flex justify-end gap-1">
                                                <Button asChild variant="ghost" size="sm">
                                                    <Link href={`/members/${row.id}`}>History</Link>
                                                </Button>
                                                {can.manage && (
                                                    <Button type="button" variant="ghost" size="sm" onClick={() => setEditing(row)} aria-label={`Edit ${row.full_name}`}>
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

                {members.total > 0 && (
                    <nav aria-label="Pagination" className="flex flex-wrap items-center justify-between gap-3 text-sm">
                        <p className="text-muted-foreground">
                            Showing {members.from ?? 0}&ndash;{members.to ?? 0} of {members.total}
                        </p>
                        <div className="flex items-center gap-3">
                            <Button type="button" variant="outline" size="sm" disabled={members.current_page <= 1} onClick={() => visit({ page: members.current_page - 1 })}>
                                Previous
                            </Button>
                            <span aria-current="page">
                                Page {members.current_page} of {members.last_page}
                            </span>
                            <Button type="button" variant="outline" size="sm" disabled={members.current_page >= members.last_page} onClick={() => visit({ page: members.current_page + 1 })}>
                                Next
                            </Button>
                        </div>
                    </nav>
                )}
            </div>
        </AppLayout>
    );
}