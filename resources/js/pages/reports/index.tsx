import { Head, router } from '@inertiajs/react';
import { Download, FileText, FlaskConical } from 'lucide-react';
import { useState } from 'react';

import { EmptyState } from '@/components/empty-state';
import { FlashMessages } from '@/components/flash-messages';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import AppLayout from '@/layouts/app-layout';
import type { ReportData } from '@/types/campusnet';

type Props = {
    report: ReportData;
    can: { export: boolean };
};

const SELECT =
    'border-input bg-background h-9 rounded-md border px-2 text-sm shadow-xs outline-none focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50';

/** Local calendar date as YYYY-MM-DD, shifted by a number of days. */
function dateOffset(days: number): string {
    const date = new Date();
    date.setDate(date.getDate() + days);

    const pad = (value: number) => String(value).padStart(2, '0');

    return `${date.getFullYear()}-${pad(date.getMonth() + 1)}-${pad(date.getDate())}`;
}

function formatCell(value: string | number | null): string {
    if (value === null || value === '') {
        return '—';
    }

    return String(value);
}

export default function ReportsIndex({ report, can }: Props) {
    const [type, setType] = useState(report.type);
    const [from, setFrom] = useState(report.from_date);
    const [to, setTo] = useState(report.to_date);

    const selected = report.types.find((option) => option.value === type) ?? report.types[0];
    const query = { type, from, to };
    const downloadHref = `/reports/download?${new URLSearchParams(query).toString()}`;

    const preview = (next: { type?: string; from?: string; to?: string }) => {
        const merged = { type, from, to, ...next };

        router.get('/reports', merged, { preserveState: true, preserveScroll: true, replace: true, only: ['report'] });
    };

    const preset = (days: number) => {
        const nextFrom = dateOffset(-(days - 1));
        const nextTo = dateOffset(0);

        setFrom(nextFrom);
        setTo(nextTo);
        preview({ from: nextFrom, to: nextTo });
    };

    return (
        <AppLayout breadcrumbs={[{ title: 'Reports', href: '/reports' }]}>
            <Head title="Reports" />

            <div className="flex flex-col gap-4 p-4">
                <div>
                    <h1 className="text-xl font-semibold">Reports</h1>
                    <p className="text-sm text-muted-foreground">
                        Export what CampusNet has measured as a CSV file that opens in Excel or Google Sheets. Reports contain stored measurements only; nothing is estimated.
                    </p>
                </div>

                <FlashMessages />

                {report.mode.demo && (
                    <div role="status" className="flex items-start gap-2 rounded-lg border border-amber-500/40 bg-amber-50 p-3 text-sm text-amber-950 dark:bg-amber-950/40 dark:text-amber-100">
                        <FlaskConical className="mt-0.5 size-4 shrink-0" aria-hidden="true" />
                        <p>Demo mode: this report is built from simulated demo data, and the file name says so. It is not real campus traffic.</p>
                    </div>
                )}

                <form
                    aria-label="Report options"
                    onSubmit={(event) => {
                        event.preventDefault();
                        preview({});
                    }}
                    className="flex flex-wrap items-end gap-3"
                >
                    <div className="grid gap-1">
                        <label htmlFor="report-type" className="text-xs text-muted-foreground">
                            Report
                        </label>
                        <select
                            id="report-type"
                            className={SELECT}
                            value={type}
                            onChange={(event) => {
                                setType(event.target.value);
                                preview({ type: event.target.value });
                            }}
                        >
                            {report.types.map((option) => (
                                <option key={option.value} value={option.value}>
                                    {option.label}
                                </option>
                            ))}
                        </select>
                    </div>

                    <div className="grid gap-1">
                        <label htmlFor="report-from" className="text-xs text-muted-foreground">
                            From
                        </label>
                        <Input id="report-from" type="date" value={from} max={to} onChange={(event) => setFrom(event.target.value)} />
                    </div>

                    <div className="grid gap-1">
                        <label htmlFor="report-to" className="text-xs text-muted-foreground">
                            To
                        </label>
                        <Input id="report-to" type="date" value={to} min={from} max={dateOffset(0)} onChange={(event) => setTo(event.target.value)} />
                    </div>

                    <Button type="submit" variant="outline">
                        Preview
                    </Button>

                    <div className="flex items-center gap-1" role="group" aria-label="Quick periods">
                        <Button type="button" variant="ghost" size="sm" onClick={() => preset(7)}>
                            Last 7 days
                        </Button>
                        <Button type="button" variant="ghost" size="sm" onClick={() => preset(30)}>
                            Last 30 days
                        </Button>
                        <Button type="button" variant="ghost" size="sm" onClick={() => preset(90)}>
                            Last 90 days
                        </Button>
                    </div>
                </form>

                <p className="text-sm text-muted-foreground">
                    {selected.description} Days are counted in {report.timezone}. A period can be at most {report.max_days} days.
                </p>

                <section aria-label="Report preview" className="rounded-xl border">
                    <div className="flex flex-wrap items-center justify-between gap-3 border-b p-3">
                        <div>
                            <h2 className="text-base font-semibold">{selected.label}</h2>
                            <p className="text-xs text-muted-foreground" aria-live="polite">
                                {report.from_date} to {report.to_date} &middot; {report.total_rows} row{report.total_rows === 1 ? '' : 's'}
                                {report.total_rows > report.rows.length ? ` (first ${report.rows.length} shown)` : ''}
                            </p>
                        </div>

                        {can.export ? (
                            report.total_rows === 0 ? (
                                <Button type="button" disabled>
                                    <Download className="size-4" aria-hidden="true" />
                                    Download CSV
                                </Button>
                            ) : (
                                <Button asChild>
                                    <a href={downloadHref} download aria-label={`Download ${report.filename}`}>
                                        <Download className="size-4" aria-hidden="true" />
                                        Download CSV
                                    </a>
                                </Button>
                            )
                        ) : (
                            <p className="text-xs text-muted-foreground">Your role can view reports but not export them.</p>
                        )}
                    </div>

                    {report.rows.length === 0 ? (
                        <div className="p-4">
                            <EmptyState
                                icon={FileText}
                                title="Nothing to report for this period"
                                description="There are no stored records in these dates. Try a longer period, or check that polling is running (php artisan schedule:work)."
                            />
                        </div>
                    ) : (
                        <div className="overflow-x-auto">
                            <table className="w-full text-sm">
                                <caption className="sr-only">Preview of the {selected.label} report</caption>
                                <thead className="bg-muted/50 text-left text-xs text-muted-foreground">
                                    <tr>
                                        {report.columns.map((column) => (
                                            <th key={column} scope="col" className="px-3 py-2 font-medium whitespace-nowrap">
                                                {column}
                                            </th>
                                        ))}
                                    </tr>
                                </thead>
                                <tbody>
                                    {report.rows.map((row, index) => (
                                        <tr key={index} className="border-t">
                                            {row.map((cell, cellIndex) => (
                                                <td key={cellIndex} className="px-3 py-2 whitespace-nowrap tabular-nums">
                                                    {formatCell(cell)}
                                                </td>
                                            ))}
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        </div>
                    )}
                </section>

                <p className="text-xs text-muted-foreground">
                    The file is named <span className="font-mono">{report.filename}</span>. Every export is written to the audit log.
                </p>
            </div>
        </AppLayout>
    );
}