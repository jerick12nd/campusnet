import { Head, router, useForm } from '@inertiajs/react';
import { Check, Copy, Eye, EyeOff, KeyRound, Plus, Search, Users } from 'lucide-react';
import { useEffect, useRef, useState } from 'react';
import type { FormEvent } from 'react';

import { EmptyState } from '@/components/empty-state';
import { FlashMessages } from '@/components/flash-messages';
import { Pill } from '@/components/status-badge';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle, DialogTrigger } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import AppLayout from '@/layouts/app-layout';
import { formatDateTime, timeAgo } from '@/lib/format';
import type { Option, Paginated, UserFilters, UserRow, UserStats } from '@/types/campusnet';

type Props = {
    users: Paginated<UserRow>;
    filters: UserFilters;
    stats: UserStats;
    roles: Option[];
};

const SELECT =
    'border-input bg-background h-9 rounded-md border px-2 text-sm shadow-xs outline-none focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50';

const RELOAD = ['users', 'filters', 'stats'];

/** A random password that always has upper case, lower case, a digit and a symbol (no look-alike characters). */
function generatePassword(length = 14): string {
    const sets = ['ABCDEFGHJKLMNPQRSTUVWXYZ', 'abcdefghijkmnopqrstuvwxyz', '23456789', '!@#$%&*?'];
    const all = sets.join('');
    const random = (max: number) => crypto.getRandomValues(new Uint32Array(1))[0] % max;
    const pick = (chars: string) => chars[random(chars.length)];

    const chars = sets.map(pick);

    while (chars.length < length) {
        chars.push(pick(all));
    }

    for (let i = chars.length - 1; i > 0; i--) {
        const j = random(i + 1);

        [chars[i], chars[j]] = [chars[j], chars[i]];
    }

    return chars.join('');
}

/** "Add account" button and the form it opens. Only a Super Administrator reaches this page. */
function AddAccount({ roles }: { roles: Option[] }) {
    const defaultRole = roles.find((role) => role.value === 'viewer')?.value ?? roles[0]?.value ?? 'viewer';

    const [open, setOpen] = useState(false);
    const [showPassword, setShowPassword] = useState(false);
    const [copied, setCopied] = useState(false);
    const form = useForm({ name: '', email: '', role: defaultRole, password: '' });

    const changeOpen = (next: boolean) => {
        setOpen(next);

        if (!next) {
            form.reset();
            form.clearErrors();
            setShowPassword(false);
            setCopied(false);
        }
    };

    const generate = () => {
        form.setData('password', generatePassword());
        setShowPassword(true);
        setCopied(false);
    };

    const copyPassword = async () => {
        try {
            await navigator.clipboard.writeText(form.data.password);
            setCopied(true);
            window.setTimeout(() => setCopied(false), 2000);
        } catch {
            window.prompt('Copy this password:', form.data.password);
        }
    };

    const submit = (event: FormEvent) => {
        event.preventDefault();

        form.post('/users', {
            preserveScroll: true,
            onSuccess: () => changeOpen(false),
        });
    };

    return (
        <Dialog open={open} onOpenChange={changeOpen}>
            <DialogTrigger asChild>
                <Button type="button">
                    <Plus className="size-4" aria-hidden="true" />
                    Add account
                </Button>
            </DialogTrigger>

            <DialogContent className="sm:max-w-md">
                <DialogHeader>
                    <DialogTitle>Add account</DialogTitle>
                    <DialogDescription>
                        Create a sign-in for a new person. Give them the email and password after you save; the password cannot be shown again.
                    </DialogDescription>
                </DialogHeader>

                <form onSubmit={submit} className="grid gap-4" autoComplete="off">
                    <div className="grid gap-1.5">
                        <label htmlFor="new-name" className="text-sm font-medium">
                            Full name
                        </label>
                        <Input
                            id="new-name"
                            value={form.data.name}
                            onChange={(event) => form.setData('name', event.target.value)}
                            placeholder="e.g. Juan Dela Cruz"
                            maxLength={255}
                            autoFocus
                            required
                        />
                        {form.errors.name && <p className="text-sm text-red-600">{form.errors.name}</p>}
                    </div>

                    <div className="grid gap-1.5">
                        <label htmlFor="new-email" className="text-sm font-medium">
                            Email
                        </label>
                        <Input
                            id="new-email"
                            type="email"
                            value={form.data.email}
                            onChange={(event) => form.setData('email', event.target.value)}
                            placeholder="name@dssc.edu.ph"
                            maxLength={255}
                            autoComplete="off"
                            required
                        />
                        {form.errors.email && <p className="text-sm text-red-600">{form.errors.email}</p>}
                    </div>

                    <div className="grid gap-1.5">
                        <label htmlFor="new-role" className="text-sm font-medium">
                            Role
                        </label>
                        <select id="new-role" className={SELECT} value={form.data.role} onChange={(event) => form.setData('role', event.target.value)}>
                            {roles.map((role) => (
                                <option key={role.value} value={role.value}>
                                    {role.label}
                                </option>
                            ))}
                        </select>
                        {form.errors.role && <p className="text-sm text-red-600">{form.errors.role}</p>}
                    </div>

                    <div className="grid gap-1.5">
                        <label htmlFor="new-password" className="text-sm font-medium">
                            Password
                        </label>
                        <div className="flex gap-2">
                            <Input
                                id="new-password"
                                type={showPassword ? 'text' : 'password'}
                                value={form.data.password}
                                onChange={(event) => form.setData('password', event.target.value)}
                                autoComplete="new-password"
                                required
                            />
                            <Button
                                type="button"
                                variant="outline"
                                size="icon"
                                onClick={() => setShowPassword((value) => !value)}
                                aria-label={showPassword ? 'Hide password' : 'Show password'}
                            >
                                {showPassword ? <EyeOff className="size-4" aria-hidden="true" /> : <Eye className="size-4" aria-hidden="true" />}
                            </Button>
                        </div>
                        <div className="flex flex-wrap items-center gap-2">
                            <Button type="button" variant="outline" size="sm" onClick={generate}>
                                <KeyRound className="size-4" aria-hidden="true" />
                                Generate password
                            </Button>
                            {form.data.password !== '' && (
                                <Button type="button" variant="ghost" size="sm" onClick={() => void copyPassword()}>
                                    {copied ? <Check className="size-4" aria-hidden="true" /> : <Copy className="size-4" aria-hidden="true" />}
                                    {copied ? 'Copied' : 'Copy'}
                                </Button>
                            )}
                        </div>
                        {form.errors.password && <p className="text-sm text-red-600">{form.errors.password}</p>}
                    </div>

                    <DialogFooter>
                        <Button type="button" variant="outline" onClick={() => changeOpen(false)}>
                            Cancel
                        </Button>
                        <Button type="submit" disabled={form.processing}>
                            {form.processing ? 'Creating…' : 'Create account'}
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}

export default function UsersIndex({ users, filters, stats, roles }: Props) {
    const [search, setSearch] = useState(filters.search);
    const first = useRef(true);

    const visit = (changes: Partial<UserFilters & { page: number }>) => {
        const next = { ...filters, ...changes };
        const query: Record<string, string | number> = {};

        (['search', 'role', 'status'] as const).forEach((key) => {
            if (next[key]) {
                query[key] = next[key];
            }
        });

        if (changes.page && changes.page > 1) {
            query.page = changes.page;
        }

        router.get('/users', query, { preserveState: true, preserveScroll: true, replace: true, only: RELOAD });
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

    const hasFilters = Boolean(filters.search || filters.role || filters.status);

    const clearFilters = () => {
        setSearch('');
        router.get('/users', {}, { preserveState: true, preserveScroll: true, replace: true, only: RELOAD });
    };

    const changeRole = (row: UserRow, role: string) => {
        const label = roles.find((option) => option.value === role)?.label ?? role;

        if (window.confirm(`Change the role of ${row.name} to "${label}"?`)) {
            router.patch(`/users/${row.id}`, { role }, { preserveScroll: true });
        }
    };

    const toggleActive = (row: UserRow) => {
        const message = row.is_active
            ? `Deactivate ${row.name}? They will be signed out and cannot sign in until the account is activated again.`
            : `Activate ${row.name}? They will be able to sign in again.`;

        if (window.confirm(message)) {
            router.patch(`/users/${row.id}`, { is_active: !row.is_active }, { preserveScroll: true });
        }
    };

    return (
        <AppLayout breadcrumbs={[{ title: 'User Management', href: '/users' }]}>
            <Head title="User Management" />

            <div className="flex flex-col gap-4 p-4">
                <div className="flex flex-wrap items-start justify-between gap-3">
                    <div>
                        <h1 className="text-xl font-semibold">User Management</h1>
                        <p className="text-sm text-muted-foreground">
                            Decide who can do what. A deactivated person keeps their history but is signed out and cannot sign in. Every change is written to the audit log.
                        </p>
                    </div>

                    <AddAccount roles={roles} />
                </div>

                <FlashMessages />

                <p className="text-sm text-muted-foreground" aria-live="polite">
                    {stats.total} account{stats.total === 1 ? '' : 's'} &middot; {stats.active} active &middot; {stats.inactive} inactive &middot; {stats.super_admins} active super
                    administrator{stats.super_admins === 1 ? '' : 's'}
                </p>

                <form role="search" aria-label="Filter users" onSubmit={(event) => event.preventDefault()} className="flex flex-wrap items-end gap-3">
                    <div className="relative min-w-56 flex-1">
                        <label htmlFor="user-search" className="sr-only">
                            Search users
                        </label>
                        <Search className="pointer-events-none absolute top-2.5 left-2.5 size-4 text-muted-foreground" aria-hidden="true" />
                        <Input id="user-search" type="search" value={search} onChange={(event) => setSearch(event.target.value)} placeholder="Search name or email" className="pl-8" />
                    </div>

                    <div className="grid gap-1">
                        <label htmlFor="filter-role" className="text-xs text-muted-foreground">
                            Role
                        </label>
                        <select id="filter-role" className={SELECT} value={filters.role} onChange={(event) => visit({ role: event.target.value })}>
                            <option value="">All roles</option>
                            {roles.map((role) => (
                                <option key={role.value} value={role.value}>
                                    {role.label}
                                </option>
                            ))}
                        </select>
                    </div>

                    <div className="grid gap-1">
                        <label htmlFor="filter-status" className="text-xs text-muted-foreground">
                            Status
                        </label>
                        <select id="filter-status" className={SELECT} value={filters.status} onChange={(event) => visit({ status: event.target.value })}>
                            <option value="">Active and inactive</option>
                            <option value="active">Active only</option>
                            <option value="inactive">Inactive only</option>
                        </select>
                    </div>

                    {hasFilters && (
                        <Button type="button" variant="ghost" onClick={clearFilters}>
                            Clear filters
                        </Button>
                    )}
                </form>

                {users.data.length === 0 ? (
                    <EmptyState icon={hasFilters ? Search : Users} title={hasFilters ? 'No users match these filters' : 'No users yet'} description={hasFilters ? 'Try a different search term or clear the filters.' : undefined}>
                        {hasFilters && (
                            <Button type="button" variant="outline" onClick={clearFilters}>
                                Clear filters
                            </Button>
                        )}
                    </EmptyState>
                ) : (
                    <div className="overflow-x-auto rounded-xl border">
                        <table className="w-full text-sm">
                            <caption className="sr-only">
                                Users, page {users.current_page} of {users.last_page}, {users.total} in total
                            </caption>
                            <thead className="bg-muted/50 text-left text-xs text-muted-foreground">
                                <tr>
                                    <th scope="col" className="px-3 py-2 font-medium">Person</th>
                                    <th scope="col" className="px-3 py-2 font-medium">Role</th>
                                    <th scope="col" className="px-3 py-2 font-medium">Status</th>
                                    <th scope="col" className="px-3 py-2 font-medium">Last sign-in</th>
                                    <th scope="col" className="px-3 py-2 text-right font-medium">Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                {users.data.map((row) => (
                                    <tr key={row.id} className="border-t align-top">
                                        <td className="px-3 py-2">
                                            <div className="font-medium">
                                                {row.name}
                                                {row.is_self && <span className="ml-2 text-xs font-normal text-muted-foreground">(you)</span>}
                                            </div>
                                            <div className="mt-0.5 text-xs text-muted-foreground">{row.email}</div>
                                        </td>
                                        <td className="px-3 py-2">
                                            {row.is_self ? (
                                                row.role.label
                                            ) : (
                                                <select className={SELECT} aria-label={`Role of ${row.name}`} value={row.role.value} onChange={(event) => changeRole(row, event.target.value)}>
                                                    {roles.map((role) => (
                                                        <option key={role.value} value={role.value}>
                                                            {role.label}
                                                        </option>
                                                    ))}
                                                </select>
                                            )}
                                        </td>
                                        <td className="px-3 py-2">
                                            <Pill tone={row.is_active ? 'green' : 'gray'}>{row.is_active ? 'Active' : 'Inactive'}</Pill>
                                        </td>
                                        <td className="px-3 py-2">
                                            {row.last_login_at ? (
                                                <>
                                                    <div>{timeAgo(row.last_login_at)}</div>
                                                    <div className="mt-0.5 text-xs text-muted-foreground">{formatDateTime(row.last_login_at)}</div>
                                                </>
                                            ) : (
                                                <span className="text-muted-foreground">Never signed in</span>
                                            )}
                                        </td>
                                        <td className="px-3 py-2 text-right">
                                            {row.is_self ? (
                                                <span className="text-xs text-muted-foreground">Ask another super administrator</span>
                                            ) : (
                                                <Button type="button" size="sm" variant="outline" onClick={() => toggleActive(row)} aria-label={`${row.is_active ? 'Deactivate' : 'Activate'} ${row.name}`}>
                                                    {row.is_active ? 'Deactivate' : 'Activate'}
                                                </Button>
                                            )}
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                )}

                {users.total > 0 && (
                    <nav aria-label="Pagination" className="flex flex-wrap items-center justify-between gap-3 text-sm">
                        <p className="text-muted-foreground">
                            Showing {users.from ?? 0}&ndash;{users.to ?? 0} of {users.total}
                        </p>

                        <div className="flex items-center gap-3">
                            <Button type="button" variant="outline" size="sm" disabled={users.current_page <= 1} onClick={() => visit({ page: users.current_page - 1 })}>
                                Previous
                            </Button>
                            <span aria-current="page">
                                Page {users.current_page} of {users.last_page}
                            </span>
                            <Button type="button" variant="outline" size="sm" disabled={users.current_page >= users.last_page} onClick={() => visit({ page: users.current_page + 1 })}>
                                Next
                            </Button>
                        </div>
                    </nav>
                )}

                <p className="text-xs text-muted-foreground">
                    Roles: a Read-Only Viewer can see everything but change nothing. An ICT / Network Administrator manages devices, interfaces and alerts and exports reports. A Super
                    Administrator also manages settings and accounts and reads the audit log.
                </p>
            </div>
        </AppLayout>
    );
}