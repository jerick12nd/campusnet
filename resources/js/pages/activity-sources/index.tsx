import { Head, Link, useForm } from '@inertiajs/react';
import { ArrowLeft, Info } from 'lucide-react';
import type { FormEvent } from 'react';

import { FlashMessages } from '@/components/flash-messages';
import { Pill } from '@/components/status-badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import AppLayout from '@/layouts/app-layout';
import { timeAgo } from '@/lib/format';
import type { SourceRow } from '@/types/website-activity';

type Props = {
    sources: SourceRow[];
    devices: { id: number; name: string }[];
};

const SELECT =
    'border-input bg-background h-9 rounded-md border px-2 text-sm shadow-xs outline-none focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50';

function SourceCard({ source, devices }: { source: SourceRow; devices: { id: number; name: string }[] }) {
    const form = useForm({
        is_enabled: source.is_enabled,
        retention_days: String(source.retention_days),
        monitored_device_id: source.monitored_device_id ? String(source.monitored_device_id) : '',
    });

    const submit = (event: FormEvent) => {
        event.preventDefault();

        if (form.data.is_enabled && !source.is_enabled) {
            const ok = window.confirm(
                `Enable "${source.name}"? CampusNet will start reading network metadata from the router. This must be covered by your school policy and the Data Privacy Act notice.`,
            );

            if (!ok) {
                return;
            }
        }

        form.patch(`/activity-sources/${source.id}`, { preserveScroll: true });
    };

    return (
        <form onSubmit={submit} className="grid gap-3 rounded-xl border p-4" aria-label={source.name}>
            <div className="flex flex-wrap items-center justify-between gap-2">
                <h2 className="text-base font-semibold">{source.name}</h2>
                <div className="flex items-center gap-2">
                    <Pill tone={source.is_enabled ? 'green' : 'gray'}>{source.is_enabled ? 'Enabled' : 'Disabled'}</Pill>
                    {source.last_status === 'failed' && <Pill tone="red">Last run failed</Pill>}
                </div>
            </div>

            {source.description && <p className="text-sm text-muted-foreground">{source.description}</p>}

            <p className="text-xs text-muted-foreground">
                {source.last_run_at ? `Last collected ${timeAgo(source.last_run_at)}.` : 'Not collected yet.'}
                {source.last_error ? ` Last error: ${source.last_error}` : ''}
            </p>

            <label className="flex items-center gap-2 text-sm">
                <input type="checkbox" checked={form.data.is_enabled} onChange={(event) => form.setData('is_enabled', event.target.checked)} />
                Collect from this source
            </label>

            <div className="grid gap-3 sm:grid-cols-2">
                <div className="grid gap-1">
                    <label htmlFor={`router-${source.id}`} className="text-xs text-muted-foreground">
                        Router this source reads from (read-only)
                    </label>
                    <select id={`router-${source.id}`} className={SELECT} value={form.data.monitored_device_id} onChange={(event) => form.setData('monitored_device_id', event.target.value)}>
                        <option value="">Choose a device</option>
                        {devices.map((device) => (
                            <option key={device.id} value={String(device.id)}>
                                {device.name}
                            </option>
                        ))}
                    </select>
                    {form.errors.monitored_device_id && <p className="text-xs text-red-500">{form.errors.monitored_device_id}</p>}
                </div>

                <div className="grid gap-1">
                    <label htmlFor={`retention-${source.id}`} className="text-xs text-muted-foreground">
                        Keep records for (days)
                    </label>
                    <Input id={`retention-${source.id}`} type="number" min={1} max={3650} value={form.data.retention_days} onChange={(event) => form.setData('retention_days', event.target.value)} />
                    {form.errors.retention_days && <p className="text-xs text-red-500">{form.errors.retention_days}</p>}
                </div>
            </div>

            <div>
                <Button type="submit" disabled={form.processing}>
                    Save
                </Button>
            </div>
        </form>
    );
}

export default function ActivitySourcesIndex({ sources, devices }: Props) {
    return (
        <AppLayout breadcrumbs={[{ title: 'Website Activity', href: '/website-activity' }, { title: 'Data sources', href: '/activity-sources' }]}>
            <Head title="Data sources" />

            <div className="flex flex-col gap-4 p-4">
                <div>
                    <Link href="/website-activity" className="inline-flex items-center gap-1 text-sm text-muted-foreground hover:text-foreground">
                        <ArrowLeft className="size-4" aria-hidden="true" />
                        Website Activity
                    </Link>
                    <h1 className="mt-2 text-xl font-semibold">Data sources</h1>
                    <p className="text-sm text-muted-foreground">
                        Where connection and domain data comes from. Every source is off until you enable it, reads from the router with read-only access, and deletes its records after the retention period.
                    </p>
                </div>

                <FlashMessages />

                <div role="note" className="flex items-start gap-2 rounded-xl border bg-muted/40 p-3 text-sm text-muted-foreground">
                    <Info className="mt-0.5 size-4 shrink-0" aria-hidden="true" />
                    <p>
                        Collect only what network administration needs. Domain names and connection times are metadata, but they can still describe a person&apos;s habits, so keep retention short and limit who can see member-level data.
                    </p>
                </div>

                {sources.length === 0 ? (
                    <p className="text-sm text-muted-foreground">No data sources exist yet. Run php artisan db:seed --class=ActivityDataSourceSeeder.</p>
                ) : (
                    sources.map((source) => <SourceCard key={source.id} source={source} devices={devices} />)
                )}
            </div>
        </AppLayout>
    );
}