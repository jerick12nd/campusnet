import { Head, Link, router } from '@inertiajs/react';
import { Download, Globe, Info, Search, Settings2 } from 'lucide-react';
import { useEffect, useRef, useState, type ReactNode } from 'react';

import { EmptyState } from '@/components/empty-state';
import { FlashMessages } from '@/components/flash-messages';
import { Pill } from '@/components/status-badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import AppLayout from '@/layouts/app-layout';
import { timeAgo } from '@/lib/format';
import { formatBytes } from '@/lib/office-network';
import type { Option, Paginated } from '@/types/campusnet';
import type { OfficeOption } from '@/types/connected-members';
import type { OfficeShare, SourceStatus, TopDomain, TrendDay, WebsiteFilters, WebsiteRecord } from '@/types/website-activity';

type Props = {
    records: Paginated<WebsiteRecord>;
    summary: { hits: number; domains: number; records: number };
    topDomains: TopDomain[];
    byOffice: OfficeShare[];
    trend: TrendDay[];
    sources: SourceStatus[];
    offices: OfficeOption[];
    members: { id: number; full_name: string }[];
    typeOptions: Option[];
    filters: WebsiteFilters;
    defaultRange: { from: string; to: string };
    maxRangeDays: number;
    can: { memberLevel: boolean; export: boolean; manageSources: boolean };
};

const SELECT =
    'border-input bg-background h-9 rounded-md border px-2 text-sm shadow-xs outline-none focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50';

const FILTER_KEYS = ['search', 'office', 'type', 'member', 'from', 'to'] as const;
const RELOAD = ['records', 'summary', 'topDomains', 'byOffice', 'trend', 'filters'];

function Panel({ title, hint, children }: { title: string; hint?: string; children: ReactNode }) {
    return (
        <section className="rounded-xl border p-4">
            <h2 className="text-sm font-semibold">{title}</h2>
            {hint && <p className="mt-0.5 text-xs text-muted-foreground">{hint}</p>}
            <div className="mt-3">{children}</div>
        </section>
    );
}

function Bar({ value, max }: { value: number; max: number }) {
    const width = max > 0 ? Math.max(2, Math.round((value / max) * 100)) : 0;

    return (
        <div className="h-1.5 w-full rounded-full bg-muted" aria-hidden="true">
            <div className="h-1.5 rounded-full bg-primary" style={{ width: `${width}%` }} />
        </div>
    );
}

function SourceState({ source }: { source: SourceStatus }) {
    if (!source.is_enabled) {
        return <Pill tone="gray">Disabled</Pill>;
    }

    if (source.last_status === 'failed') {
        return <Pill tone="red">Failing</Pill>;
    }

    if (source.last_run_at === null) {
        return <Pill tone="gray">Not run yet</Pill>;
    }

    return <Pill tone="green">Collecting</Pill>;
}

export default function WebsiteActivityIndex({
    records,
    summary,
    topDomains,
    byOffice,
    trend,
    sources,
    offices,
    members,
    typeOptions,
    filters,
    defaultRange,
    maxRangeDays,
    can,
}: Props) {
    const [search, setSearch] = useState(filters.search);
    const first = useRef(true);

    const visit = (changes: Partial<WebsiteFilters & { page: number }>) => {
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

        router.get('/website-activity', query, { preserveState: true, preserveScroll: true, replace: true, only: RELOAD });
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

    const hasFilters =
        Boolean(filters.search || filters.office || filters.type || filters.member) || filters.from !== defaultRange.from || filters.to !== defaultRange.to;

    const clearFilters = () => {
        setSearch('');
        router.get('/website-activity', {}, { preserveState: true, preserveScroll: true, replace: true, only: RELOAD });
    };

    const params = new URLSearchParams();
    FILTER_KEYS.forEach((key) => {
        if (filters[key]) {
            params.set(key, filters[key]);
        }
    });
    const exportHref = `/website-activity/export?${params.toString()}`;

    const anyEnabled = sources.some((source) => source.is_enabled);
    const maxDomain = Math.max(0, ...topDomains.map((row) => row.hits));
    const maxOffice = Math.max(0, ...byOffice.map((row) => row.hits));
    const maxDay = Math.max(0, ...trend.map((row) => row.hits));

    return (
        <AppLayout breadcrumbs={[{ title: 'Website Activity', href: '/website-activity' }]}>
            <Head title="Website Activity" />

            <div className="flex flex-col gap-4 p-4">
                <div className="flex flex-wrap items-start justify-between gap-3">
                    <div>
                        <h1 className="text-xl font-semibold">Website Activity</h1>
                        <p className="text-sm text-muted-foreground">
                            Domain names that the network recorded, for troubleshooting and bandwidth management. Only domain names are collected. Never page contents, messages, passwords or full addresses.
                        </p>
                    </div>

                    <div className="flex flex-wrap gap-2">
                        {can.manageSources && (
                            <Button asChild variant="outline">
                                <Link href="/activity-sources">
                                    <Settings2 className="size-4" aria-hidden="true" />
                                    Data sources
                                </Link>
                            </Button>
                        )}
                        {can.export && (
                            <Button asChild variant="outline">
                                <a href={exportHref}>
                                    <Download className="size-4" aria-hidden="true" />
                                    Export CSV
                                </a>
                            </Button>
                        )}
                    </div>
                </div>

                <FlashMessages />

                <div role="note" className="flex items-start gap-2 rounded-xl border bg-muted/40 p-3 text-sm text-muted-foreground">
                    <Info className="mt-0.5 size-4 shrink-0" aria-hidden="true" />
                    <p>
                        A DNS lookup shows that a device asked for a name. It does not prove that the website was opened. Encrypted DNS and some apps hide lookups from the router, so the numbers are a lower bound.
                        Network metadata may be monitored under school policy, and every view and export is recorded in the audit log.
                    </p>
                </div>

                {sources.length > 0 && (
                    <ul className="flex flex-wrap gap-2" aria-label="Collection sources">
                        {sources.map((source) => (
                            <li key={source.id} className="flex items-center gap-2 rounded-lg border px-3 py-1.5 text-xs">
                                <span className="font-medium">{source.name}</span>
                                <span className="text-muted-foreground">{source.type_label}</span>
                                <SourceState source={source} />
                                {source.is_enabled && source.last_run_at && <span className="text-muted-foreground">{timeAgo(source.last_run_at)}</span>}
                            </li>
                        ))}
                    </ul>
                )}

                {!anyEnabled ? (
                    <EmptyState
                        icon={Globe}
                        title="Website activity collection is not set up"
                        description="CampusNet only shows domain names that your network equipment records. Enable a DNS query log source and make the router the DNS server of your devices. Domains then appear here. Nothing is estimated while no source is collecting."
                    >
                        {can.manageSources && (
                            <Button asChild variant="outline">
                                <Link href="/activity-sources">Open data sources</Link>
                            </Button>
                        )}
                    </EmptyState>
                ) : (
                    <>
                        <form role="search" aria-label="Filter website activity" onSubmit={(event) => event.preventDefault()} className="flex flex-wrap items-end gap-3">
                            <div className="relative min-w-56 flex-1">
                                <label htmlFor="wa-search" className="sr-only">
                                    Search domains
                                </label>
                                <Search className="pointer-events-none absolute top-2.5 left-2.5 size-4 text-muted-foreground" aria-hidden="true" />
                                <Input id="wa-search" type="search" value={search} onChange={(event) => setSearch(event.target.value)} placeholder="Search domain names" className="pl-8" />
                            </div>

                            <div className="grid gap-1">
                                <label htmlFor="wa-office" className="text-xs text-muted-foreground">
                                    Office
                                </label>
                                <select id="wa-office" className={SELECT} value={filters.office} onChange={(event) => visit({ office: event.target.value })}>
                                    <option value="">All offices</option>
                                    {offices.map((office) => (
                                        <option key={office.id} value={String(office.id)}>
                                            {office.name}
                                        </option>
                                    ))}
                                </select>
                            </div>

                            <div className="grid gap-1">
                                <label htmlFor="wa-type" className="text-xs text-muted-foreground">
                                    Source type
                                </label>
                                <select id="wa-type" className={SELECT} value={filters.type} onChange={(event) => visit({ type: event.target.value })}>
                                    <option value="">All types</option>
                                    {typeOptions.map((option) => (
                                        <option key={option.value} value={option.value}>
                                            {option.label}
                                        </option>
                                    ))}
                                </select>
                            </div>

                            {can.memberLevel && (
                                <div className="grid gap-1">
                                    <label htmlFor="wa-member" className="text-xs text-muted-foreground">
                                        Member
                                    </label>
                                    <select id="wa-member" className={SELECT} value={filters.member} onChange={(event) => visit({ member: event.target.value })}>
                                        <option value="">All members</option>
                                        {members.map((member) => (
                                            <option key={member.id} value={String(member.id)}>
                                                {member.full_name}
                                            </option>
                                        ))}
                                    </select>
                                </div>
                            )}

                            <div className="grid gap-1">
                                <label htmlFor="wa-from" className="text-xs text-muted-foreground">
                                    From
                                </label>
                                <input id="wa-from" type="date" className={SELECT} value={filters.from} max={filters.to} onChange={(event) => event.target.value && visit({ from: event.target.value })} />
                            </div>

                            <div className="grid gap-1">
                                <label htmlFor="wa-to" className="text-xs text-muted-foreground">
                                    To
                                </label>
                                <input id="wa-to" type="date" className={SELECT} value={filters.to} min={filters.from} onChange={(event) => event.target.value && visit({ to: event.target.value })} />
                            </div>

                            {hasFilters && (
                                <Button type="button" variant="ghost" onClick={clearFilters}>
                                    Clear filters
                                </Button>
                            )}
                        </form>
                        <p className="-mt-2 text-xs text-muted-foreground">Reports cover at most {maxRangeDays} days.</p>

                        <dl className="grid gap-3 sm:grid-cols-3">
                            <div className="rounded-xl border p-4">
                                <dt className="text-xs text-muted-foreground">Lookups recorded</dt>
                                <dd className="mt-1 text-2xl font-semibold tabular-nums">{summary.hits.toLocaleString()}</dd>
                            </div>
                            <div className="rounded-xl border p-4">
                                <dt className="text-xs text-muted-foreground">Different domains</dt>
                                <dd className="mt-1 text-2xl font-semibold tabular-nums">{summary.domains.toLocaleString()}</dd>
                            </div>
                            <div className="rounded-xl border p-4">
                                <dt className="text-xs text-muted-foreground">Records</dt>
                                <dd className="mt-1 text-2xl font-semibold tabular-nums">{summary.records.toLocaleString()}</dd>
                            </div>
                        </dl>

                        {summary.records === 0 ? (
                            <EmptyState
                                icon={Search}
                                title="No domain activity in this period"
                                description={hasFilters ? 'Nothing matches these filters. Try a wider date range or clear the filters.' : 'The data source is enabled but has not recorded any domain in this period.'}
                            >
                                {hasFilters && (
                                    <Button type="button" variant="outline" onClick={clearFilters}>
                                        Clear filters
                                    </Button>
                                )}
                            </EmptyState>
                        ) : (
                            <>
                                <div className="grid gap-4 lg:grid-cols-2">
                                    <Panel title="Most observed domains" hint="Grouped by main domain, for example www.google.com counts under google.com.">
                                        <ol className="grid gap-2">
                                            {topDomains.map((row) => (
                                                <li key={row.domain} className="grid gap-1">
                                                    <div className="flex items-baseline justify-between gap-3 text-sm">
                                                        <span className="truncate font-medium">{row.domain}</span>
                                                        <span className="shrink-0 text-xs text-muted-foreground tabular-nums">
                                                            {row.hits.toLocaleString()} lookups &middot; {row.clients} client{row.clients === 1 ? '' : 's'}
                                                        </span>
                                                    </div>
                                                    <Bar value={row.hits} max={maxDomain} />
                                                </li>
                                            ))}
                                        </ol>
                                    </Panel>

                                    <Panel title="Activity by office" hint="Devices that are not registered to a member are listed as unidentified.">
                                        <ol className="grid gap-2">
                                            {byOffice.map((row) => (
                                                <li key={row.office_id ?? 'none'} className="grid gap-1">
                                                    <div className="flex items-baseline justify-between gap-3 text-sm">
                                                        <span className="truncate font-medium">{row.name}</span>
                                                        <span className="shrink-0 text-xs text-muted-foreground tabular-nums">
                                                            {row.hits.toLocaleString()} lookups &middot; {row.domains} domain{row.domains === 1 ? '' : 's'}
                                                        </span>
                                                    </div>
                                                    <Bar value={row.hits} max={maxOffice} />
                                                </li>
                                            ))}
                                        </ol>
                                    </Panel>
                                </div>

                                <Panel title="Daily trend" hint="Lookups per day, in campus time.">
                                    <div className="flex h-24 items-end gap-1" role="img" aria-label="Lookups per day">
                                        {trend.map((day) => (
                                            <div key={day.day} className="flex h-full flex-1 items-end" title={`${day.day}: ${day.hits.toLocaleString()} lookups`}>
                                                <div className="w-full rounded-t bg-primary" style={{ height: `${maxDay > 0 ? Math.max(day.hits > 0 ? 3 : 0, Math.round((day.hits / maxDay) * 100)) : 0}%` }} />
                                            </div>
                                        ))}
                                    </div>
                                    <div className="mt-1 flex justify-between text-xs text-muted-foreground">
                                        <span>{trend[0]?.day}</span>
                                        <span>{trend[trend.length - 1]?.day}</span>
                                    </div>
                                </Panel>

                                <div className="overflow-x-auto rounded-xl border">
                                    <table className="w-full text-sm">
                                        <caption className="sr-only">
                                            Domain activity, page {records.current_page} of {records.last_page}, {records.total} in total
                                        </caption>
                                        <thead className="bg-muted/50 text-left text-xs text-muted-foreground">
                                            <tr>
                                                <th scope="col" className="px-3 py-2 font-medium">Domain</th>
                                                <th scope="col" className="px-3 py-2 font-medium">Observed as</th>
                                                <th scope="col" className="px-3 py-2 font-medium">Office</th>
                                                {can.memberLevel && <th scope="col" className="px-3 py-2 font-medium">Member</th>}
                                                {can.memberLevel && <th scope="col" className="px-3 py-2 font-medium">Device</th>}
                                                {can.memberLevel && <th scope="col" className="px-3 py-2 font-medium">IP address</th>}
                                                <th scope="col" className="px-3 py-2 font-medium">Lookups</th>
                                                <th scope="col" className="px-3 py-2 font-medium">Traffic</th>
                                                <th scope="col" className="px-3 py-2 font-medium">Source</th>
                                                <th scope="col" className="px-3 py-2 font-medium">Time</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            {records.data.map((row) => (
                                                <tr key={row.id} className="border-t align-top">
                                                    <td className="px-3 py-2">
                                                        <div className="font-medium break-all">{row.domain}</div>
                                                        {row.base_domain && row.base_domain !== row.domain && <div className="mt-0.5 text-xs text-muted-foreground">{row.base_domain}</div>}
                                                    </td>
                                                    <td className="px-3 py-2">
                                                        <Pill tone="blue">{row.observation_label}</Pill>
                                                    </td>
                                                    <td className="px-3 py-2">{row.office_name ?? <span className="text-muted-foreground">Unidentified</span>}</td>
                                                    {can.memberLevel && <td className="px-3 py-2">{row.member_name ?? '\u2014'}</td>}
                                                    {can.memberLevel && <td className="px-3 py-2">{row.device_name ?? '\u2014'}</td>}
                                                    {can.memberLevel && <td className="px-3 py-2 font-mono text-xs">{row.client_ip ?? '\u2014'}</td>}
                                                    <td className="px-3 py-2 tabular-nums">{row.hits.toLocaleString()}</td>
                                                    <td className="px-3 py-2 text-muted-foreground">{row.traffic_bytes !== null ? formatBytes(row.traffic_bytes) : 'Not measured'}</td>
                                                    <td className="px-3 py-2 text-muted-foreground">{row.source_name ?? '\u2014'}</td>
                                                    <td className="px-3 py-2 text-muted-foreground">{timeAgo(row.observed_at)}</td>
                                                </tr>
                                            ))}
                                        </tbody>
                                    </table>
                                </div>

                                <nav aria-label="Pagination" className="flex flex-wrap items-center justify-between gap-3 text-sm">
                                    <p className="text-muted-foreground">
                                        Showing {records.from ?? 0}&ndash;{records.to ?? 0} of {records.total}
                                    </p>
                                    <div className="flex items-center gap-3">
                                        <Button type="button" variant="outline" size="sm" disabled={records.current_page <= 1} onClick={() => visit({ page: records.current_page - 1 })}>
                                            Previous
                                        </Button>
                                        <span aria-current="page">
                                            Page {records.current_page} of {records.last_page}
                                        </span>
                                        <Button type="button" variant="outline" size="sm" disabled={records.current_page >= records.last_page} onClick={() => visit({ page: records.current_page + 1 })}>
                                            Next
                                        </Button>
                                    </div>
                                </nav>
                            </>
                        )}
                    </>
                )}
            </div>
        </AppLayout>
    );
}