import { Head, router } from '@inertiajs/react';
import { ScrollText, Search } from 'lucide-react';
import { useEffect, useRef, useState } from 'react';

import { EmptyState } from '@/components/empty-state';
import { FlashMessages } from '@/components/flash-messages';
import { Pill } from '@/components/status-badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import AppLayout from '@/layouts/app-layout';
import { formatDateTime } from '@/lib/format';
import type { AuditLogFilters, AuditLogRow, AuditLogStats, Paginated } from '@/types/campusnet';

type Props = {
    logs: Paginated<AuditLogRow>;
    filters: AuditLogFilters;
    actions: string[];
    stats: AuditLogStats;
    timezone: string;
    retention_days: number;
};

const SELECT =
    'border-input bg-background h-9 rounded-md border px-2 text-sm shadow-xs outline-none focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50';

const RELOAD = ['logs', 'filters', 'stats', 'actions'];

/** The action is always written out; the colour only helps to scan the list. */
function ActionPill({ action }: { action: string }) {
    const tone = action === 'auth.login_failed' ? 'red' : action.startsWith('auth.') ? 'gray' : action.startsWith('user.') || action.startsWith('settings.') ? 'amber' : 'blue';

    return <Pill tone={tone}>{action}</Pill>;
}

export default function AuditLogsIndex({ logs, filters, actions, stats, timezone, retention_days }: Props) {
    const [search, setSearch] = useState(filters.search);
    const first = useRef(true);

    const visit = (changes: Partial<AuditLogFilters & { page: number }>) => {
        const next = { ...filters, ...changes };
        const query: Record<string, string | number> = {};

        (['action', 'search', 'from', 'to'] as const).forEach((key) => {
            if (next[key]) {
                query[key] = next[key];
            }
        });

        if (changes.page && changes.page > 1) {
            query.page = changes.page;
        }

        router.get('/audit-logs', query, { preserveState: true, preserveScroll: true, replace: true, only: RELOAD });
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

    const hasFilters = Boolean(filters.action || filters.search || filters.from || filters.to);

    const clearFilters = () => {
        setSearch('');
        router.get('/audit-logs', {}, { preserveState: true, preserveScroll: true, replace: true, only: RELOAD });
    };

    return (
        <AppLayout breadcrumbs={[{ title: 'Audit Logs', href: '/audit-logs' }]}>
            <Head title="Audit Logs" />

            <div className="flex flex-col gap-4 p-4">
                <div>
                    <h1 className="text-xl font-semibold">Audit Logs</h1>
                    <p className="text-sm text-muted-foreground">
                        A permanent record of who signed in and who changed devices, interfaces, alerts, settings and accounts. Passwords, SNMP credentials and tokens are never stored
                        here. Entries older than {retention_days} days are removed automatically.
                    </p>
                </div>

                <FlashMessages />

                <p className="text-sm text-muted-foreground" aria-live="polite">
                    Last 24 hours: {stats.events_24h} event{stats.events_24h === 1 ? '' : 's'} &middot; {stats.changes_24h} change{stats.changes_24h === 1 ? '' : 's'} &middot;{' '}
                    {stats.failed_logins_24h} failed sign-in{stats.failed_logins_24h === 1 ? '' : 's'}
                </p>

                <form role="search" aria-label="Filter audit logs" onSubmit={(event) => event.preventDefault()} className="flex flex-wrap items-end gap-3">
                    <div className="relative min-w-56 flex-1">
                        <label htmlFor="audit-search" className="sr-only">
                            Search audit logs
                        </label>
                        <Search className="pointer-events-none absolute top-2.5 left-2.5 size-4 text-muted-foreground" aria-hidden="true" />
                        <Input
                            id="audit-search"
                            type="search"
                            value={search}
                            onChange={(event) => setSearch(event.target.value)}
                            placeholder="Search person, action, description or IP address"
                            className="pl-8"
                        />
                    </div>

                    <div className="grid gap-1">
                        <label htmlFor="filter-action" className="text-xs text-muted-foreground">
                            Action
                        </label>
                        <select id="filter-action" className={SELECT} value={filters.action} onChange={(event) => visit({ action: event.target.value })}>
                            <option value="">All actions</option>
                            {actions.map((action) => (
                                <option key={action} value={action}>
                                    {action}
                                </option>
                            ))}
                        </select>
                    </div>

                    <div className="grid gap-1">
                        <label htmlFor="filter-from" className="text-xs text-muted-foreground">
                            From
                        </label>
                        <Input id="filter-from" type="date" value={filters.from} max={filters.to || undefined} onChange={(event) => visit({ from: event.target.value })} />
                    </div>

                    <div className="grid gap-1">
                        <label htmlFor="filter-to" className="text-xs text-muted-foreground">
                            To
                        </label>
                        <Input id="filter-to" type="date" value={filters.to} min={filters.from || undefined} onChange={(event) => visit({ to: event.target.value })} />
                    </div>

                    {hasFilters && (
                        <Button type="button" variant="ghost" onClick={clearFilters}>
                            Clear filters
                        </Button>
                    )}
                </form>

                {logs.data.length === 0 ? (
                    hasFilters ? (
                        <EmptyState icon={Search} title="No entries match these filters" description="Try a different search term or period, or clear the filters.">
                            <Button type="button" variant="outline" onClick={clearFilters}>
                                Clear filters
                            </Button>
                        </EmptyState>
                    ) : (
                        <EmptyState icon={ScrollText} title="Nothing recorded yet" description="Sign-ins and changes will appear here as they happen." />
                    )
                ) : (
                    <div className="overflow-x-auto rounded-xl border">
                        <table className="w-full text-sm">
                            <caption className="sr-only">
                                Audit log, page {logs.current_page} of {logs.last_page}, {logs.total} entries in total. Times are in {timezone}.
                            </caption>
                            <thead className="bg-muted/50 text-left text-xs text-muted-foreground">
                                <tr>
                                    <th scope="col" className="px-3 py-2 font-medium">When ({timezone})</th>
                                    <th scope="col" className="px-3 py-2 font-medium">Who</th>
                                    <th scope="col" className="px-3 py-2 font-medium">Action</th>
                                    <th scope="col" className="px-3 py-2 font-medium">What happened</th>
                                    <th scope="col" className="px-3 py-2 font-medium">From</th>
                                </tr>
                            </thead>
                            <tbody>
                                {logs.data.map((row) => (
                                    <tr key={row.id} className="border-t align-top">
                                        <td className="px-3 py-2 whitespace-nowrap tabular-nums">{formatDateTime(row.created_at, timezone)}</td>
                                        <td className="px-3 py-2">{row.actor}</td>
                                        <td className="px-3 py-2">
                                            <ActionPill action={row.action} />
                                        </td>
                                        <td className="max-w-lg px-3 py-2">
                                            <div>{row.description ?? '—'}</div>
                                            {row.subject && <div className="mt-0.5 text-xs text-muted-foreground">{row.subject}</div>}
                                            {(row.metadata || row.user_agent) && (
                                                <details className="mt-1 text-xs">
                                                    <summary className="cursor-pointer text-muted-foreground">Details</summary>
                                                    {row.metadata && (
                                                        <pre className="mt-1 max-h-48 overflow-auto rounded-md bg-muted p-2 font-mono text-xs break-words whitespace-pre-wrap">
                                                            {JSON.stringify(row.metadata, null, 2)}
                                                        </pre>
                                                    )}
                                                    {row.user_agent && <p className="mt-1 break-words text-muted-foreground">{row.user_agent}</p>}
                                                </details>
                                            )}
                                        </td>
                                        <td className="px-3 py-2 whitespace-nowrap text-muted-foreground tabular-nums">{row.ip_address ?? '—'}</td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                )}

                {logs.total > 0 && (
                    <nav aria-label="Pagination" className="flex flex-wrap items-center justify-between gap-3 text-sm">
                        <p className="text-muted-foreground">
                            Showing {logs.from ?? 0}&ndash;{logs.to ?? 0} of {logs.total}
                        </p>

                        <div className="flex items-center gap-3">
                            <Button type="button" variant="outline" size="sm" disabled={logs.current_page <= 1} onClick={() => visit({ page: logs.current_page - 1 })}>
                                Previous
                            </Button>
                            <span aria-current="page">
                                Page {logs.current_page} of {logs.last_page}
                            </span>
                            <Button type="button" variant="outline" size="sm" disabled={logs.current_page >= logs.last_page} onClick={() => visit({ page: logs.current_page + 1 })}>
                                Next
                            </Button>
                        </div>
                    </nav>
                )}
            </div>
        </AppLayout>
    );
}