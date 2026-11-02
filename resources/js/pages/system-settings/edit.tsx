import { Head, useForm } from '@inertiajs/react';
import { FlaskConical } from 'lucide-react';
import type { ChangeEvent, FormEvent, ReactNode } from 'react';

import InputError from '@/components/input-error';
import { FlashMessages } from '@/components/flash-messages';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import AppLayout from '@/layouts/app-layout';
import type { SystemSettingsValues } from '@/types/campusnet';

type Props = {
    settings: SystemSettingsValues;
    defaults: SystemSettingsValues;
    retention: { traffic_samples_days: number; audit_logs_days: number };
    driver: { name: string; demo: boolean };
    timezones: string[];
    can: { manage: boolean };
};

const SELECT =
    'border-input bg-background h-9 w-full rounded-md border px-2 text-sm shadow-xs outline-none focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50 disabled:cursor-not-allowed disabled:opacity-60';

function Field({ id, label, hint, error, children }: { id: string; label: string; hint?: string; error?: string; children: ReactNode }) {
    return (
        <div className="grid gap-1.5">
            <Label htmlFor={id}>{label}</Label>
            {children}
            {hint && (
                <p id={`${id}-hint`} className="text-xs text-muted-foreground">
                    {hint}
                </p>
            )}
            <InputError message={error} />
        </div>
    );
}

export default function SystemSettingsEdit({ settings, defaults, retention, driver, timezones, can }: Props) {
    const form = useForm<SystemSettingsValues>({ ...settings });

        const submit = (event: FormEvent) => {
        event.preventDefault();
        form.patch('/system-settings', { preserveScroll: true, onSuccess: () => form.setDefaults() });
    };

    const number = (key: keyof SystemSettingsValues) => (event: ChangeEvent<HTMLInputElement>) => form.setData(key, (event.target.value === '' ? '' : Number(event.target.value)) as never);

    const numberInput = (key: Exclude<keyof SystemSettingsValues, 'campus_name' | 'timezone'>, hint: string, label: string, min: number, max: number, unit: string) => (
        <Field id={key} label={label} hint={`${hint} Default: ${defaults[key]} ${unit}.`} error={form.errors[key]}>
            <Input
                id={key}
                type="number"
                inputMode="numeric"
                min={min}
                max={max}
                step={1}
                value={form.data[key]}
                onChange={number(key)}
                disabled={!can.manage}
                aria-describedby={`${key}-hint`}
            />
        </Field>
    );

    return (
        <AppLayout breadcrumbs={[{ title: 'System Settings', href: '/system-settings' }]}>
            <Head title="System Settings" />

            <div className="flex max-w-3xl flex-col gap-4 p-4">
                <div>
                    <h1 className="text-xl font-semibold">System Settings</h1>
                    <p className="text-sm text-muted-foreground">
                        These values drive the dashboard, the analytics and the alert rules. Changes apply within a minute and are written to the audit log.
                    </p>
                </div>

                <FlashMessages />

                {!can.manage && (
                    <p role="status" className="rounded-lg border bg-muted/40 p-3 text-sm">
                        You can view the settings, but only a super administrator can change them.
                    </p>
                )}

                <form onSubmit={submit} className="flex flex-col gap-6" noValidate>
                    <section aria-labelledby="general" className="grid gap-4 rounded-xl border p-4">
                        <h2 id="general" className="text-base font-semibold">
                            General
                        </h2>

                        <Field id="campus_name" label="Campus name" hint="Shown on the dashboard and the login page." error={form.errors.campus_name}>
                            <Input id="campus_name" value={form.data.campus_name} onChange={(event) => form.setData('campus_name', event.target.value)} maxLength={120} disabled={!can.manage} aria-describedby="campus_name-hint" />
                        </Field>

                        <Field
                            id="timezone"
                            label="Time zone"
                            hint="Used to group traffic by day, for the reports and for the times shown to everyone. Stored times stay in UTC."
                            error={form.errors.timezone}
                        >
                            <select id="timezone" className={SELECT} value={form.data.timezone} onChange={(event) => form.setData('timezone', event.target.value)} disabled={!can.manage} aria-describedby="timezone-hint">
                                {timezones.map((zone) => (
                                    <option key={zone} value={zone}>
                                        {zone}
                                    </option>
                                ))}
                            </select>
                        </Field>
                    </section>

                    <section aria-labelledby="capacity" className="grid gap-4 rounded-xl border p-4">
                        <h2 id="capacity" className="text-base font-semibold">
                            Bandwidth and alert thresholds
                        </h2>

                        {numberInput('bandwidth_capacity_mbps', 'Internet capacity of the campus, used for utilization when an interface does not report its own speed.', 'Internet capacity (Mbps)', 1, 1000000, 'Mbps')}

                        <div className="grid gap-4 sm:grid-cols-2">
                            {numberInput('warning_threshold_percent', 'A WAN interface above this average raises a warning.', 'Warning threshold (%)', 1, 99, '%')}
                            {numberInput('critical_threshold_percent', 'Above this average the alert becomes critical. Must be higher than the warning threshold.', 'Critical threshold (%)', 2, 100, '%')}
                        </div>
                    </section>

                    <section aria-labelledby="freshness" className="grid gap-4 rounded-xl border p-4">
                        <h2 id="freshness" className="text-base font-semibold">
                            Refresh and data freshness
                        </h2>

                        <div className="grid gap-4 sm:grid-cols-2">
                            {numberInput('dashboard_refresh_seconds', 'How often the dashboard reloads its numbers.', 'Dashboard refresh (seconds)', 5, 600, 'seconds')}
                            {numberInput('stale_data_minutes', 'If no valid measurement arrives for this long, the dashboard says the data is stale and an alert is raised.', 'Stale data after (minutes)', 1, 1440, 'minutes')}
                        </div>

                        <div className="grid gap-4 sm:grid-cols-2">
                            {numberInput('monitoring_interval_seconds', 'Saved for the polling pipeline. Each device keeps its own interval on the Devices page.', 'Default polling interval (seconds)', 15, 3600, 'seconds')}
                            {numberInput('polling_timeout_seconds', 'Saved for the polling pipeline. Must be shorter than the polling interval.', 'Polling timeout (seconds)', 1, 60, 'seconds')}
                        </div>
                    </section>

                    {can.manage && (
                        <div className="flex items-center gap-3">
                            <Button type="submit" disabled={form.processing || !form.isDirty}>
                                {form.processing ? 'Saving…' : 'Save settings'}
                            </Button>
                            {form.isDirty && (
                                <Button type="button" variant="ghost" onClick={() => form.reset()}>
                                    Discard changes
                                </Button>
                            )}
                        </div>
                    )}
                </form>

                <section aria-labelledby="readonly" className="grid gap-2 rounded-xl border bg-muted/30 p-4 text-sm">
                    <h2 id="readonly" className="text-base font-semibold">
                        Set in the server configuration
                    </h2>
                    <p className="text-muted-foreground">These are not editable here, because they decide what is kept or measured and should be changed deliberately in the .env file.</p>
                    <dl className="grid gap-1 sm:grid-cols-[14rem_1fr]">
                        <dt className="text-muted-foreground">Monitoring driver</dt>
                        <dd className="flex items-center gap-2 font-mono">
                            {driver.name}
                            {driver.demo && (
                                <span className="inline-flex items-center gap-1 font-sans text-xs text-amber-900 dark:text-amber-200">
                                    <FlaskConical className="size-3" aria-hidden="true" />
                                    demo data
                                </span>
                            )}
                        </dd>
                        <dt className="text-muted-foreground">Keep traffic samples</dt>
                        <dd>{retention.traffic_samples_days} days</dd>
                        <dt className="text-muted-foreground">Keep audit logs</dt>
                        <dd>{retention.audit_logs_days} days</dd>
                    </dl>
                </section>
            </div>
        </AppLayout>
    );
}