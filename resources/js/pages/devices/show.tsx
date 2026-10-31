import { Head, Link, router, usePage } from '@inertiajs/react';
import { CircleAlert, CircleCheck, FlaskConical, Pencil, PlugZap, Power, Trash2 } from 'lucide-react';
import { useState } from 'react';
import type { ReactNode } from 'react';

import { EmptyState } from '@/components/empty-state';
import { FlashMessages } from '@/components/flash-messages';
import { DemoBadge, DeviceStatusBadge, EnabledBadge, SeverityBadge } from '@/components/status-badge';
import { Button } from '@/components/ui/button';
import AppLayout from '@/layouts/app-layout';
import { formatDateTime, timeAgo } from '@/lib/format';
import type { AlertRow, CampusNetShared, DeviceDetail, InterfaceRow } from '@/types/campusnet';

type Props = {
    device: DeviceDetail;
    interfaces: InterfaceRow[];
    alerts: AlertRow[];
    can: { manage: boolean };
};

function Detail({ label, children }: { label: string; children: ReactNode }) {
    return (
        <div>
            <dt className="text-xs text-muted-foreground">{label}</dt>
            <dd className="mt-0.5 text-sm wrap-break-word">{children}</dd>
        </div>
    );
}

export default function ShowDevice({ device, interfaces, alerts, can }: Props) {
    const { campusnet } = usePage<CampusNetShared>().props;
    const timeZone = campusnet?.timezone;
    const [testing, setTesting] = useState(false);

    // The result is flashed once by the server and only shown for the device it belongs to.
    const test = campusnet?.flash?.connection_test;
    const result = test && test.device_id === device.id ? test : null;

    const runTest = () => {
        router.post(
            `/devices/${device.id}/test-connection`,
            {},
            {
                preserveScroll: true,
                onStart: () => setTesting(true),
                onFinish: () => setTesting(false),
            },
        );
    };

    const toggle = () => router.patch(`/devices/${device.id}/toggle`, {}, { preserveScroll: true });

    const archive = () => {
        if (window.confirm(`Archive ${device.name}? It will stop being monitored and disappear from the list. Its history is kept.`)) {
            router.delete(`/devices/${device.id}`);
        }
    };

    return (
        <AppLayout
            breadcrumbs={[
                { title: 'Network Devices', href: '/devices' },
                { title: device.name, href: `/devices/${device.id}` },
            ]}
        >
            <Head title={device.name} />

            <div className="flex flex-col gap-6 p-4">
                <div className="flex flex-wrap items-start justify-between gap-3">
                    <div>
                        <div className="flex flex-wrap items-center gap-2">
                            <h1 className="text-xl font-semibold">{device.name}</h1>
                            <DeviceStatusBadge status={device.last_status} />
                            <EnabledBadge enabled={device.is_enabled} />
                            {device.is_demo && <DemoBadge />}
                        </div>
                        <p className="mt-1 font-mono text-sm text-muted-foreground">{device.management_ip}</p>
                    </div>

                    {can.manage && (
                        <div className="flex flex-wrap gap-2">
                            <Button type="button" onClick={runTest} disabled={testing}>
                                <PlugZap className="size-4" aria-hidden="true" />
                                {testing ? 'Testing…' : 'Test connection'}
                            </Button>
                            <Button asChild variant="outline">
                                <Link href={`/devices/${device.id}/edit`}>
                                    <Pencil className="size-4" aria-hidden="true" />
                                    Edit
                                </Link>
                            </Button>
                            <Button type="button" variant="outline" onClick={toggle}>
                                <Power className="size-4" aria-hidden="true" />
                                {device.is_enabled ? 'Disable' : 'Enable'}
                            </Button>
                            <Button type="button" variant="outline" onClick={archive}>
                                <Trash2 className="size-4" aria-hidden="true" />
                                Archive
                            </Button>
                        </div>
                    )}
                </div>

                <FlashMessages />

                {result && (
                    <section
                        aria-label="Connection test result"
                        role={result.success ? 'status' : 'alert'}
                        className={`rounded-lg border px-4 py-3 text-sm ${
                            result.simulated
                                ? 'border-amber-600/40 bg-amber-50 text-amber-950 dark:bg-amber-950/40 dark:text-amber-100'
                                : result.success
                                  ? 'border-emerald-600/30 bg-emerald-50 text-emerald-950 dark:bg-emerald-950/40 dark:text-emerald-100'
                                  : 'border-red-600/30 bg-red-50 text-red-950 dark:bg-red-950/40 dark:text-red-100'
                        }`}
                    >
                        <p className="flex items-center gap-2 font-medium">
                            {result.simulated ? <FlaskConical className="size-4" aria-hidden="true" /> : result.success ? <CircleCheck className="size-4" aria-hidden="true" /> : <CircleAlert className="size-4" aria-hidden="true" />}
                            {result.simulated ? 'Demo mode: nothing was sent to the device' : result.success ? 'Connection test passed' : 'Connection test did not pass'}
                        </p>
                        <p className="mt-1">{result.message}</p>
                        {result.latency_ms !== null && <p className="mt-1 text-xs">Response time: {result.latency_ms} ms</p>}
                        {Object.keys(result.details).length > 0 && (
                            <dl className="mt-2 grid gap-x-4 gap-y-1 text-xs sm:grid-cols-2">
                                {Object.entries(result.details).map(([key, value]) => (
                                    <div key={key}>
                                        <dt className="font-medium">{key}</dt>
                                        <dd className="wrap-break-word">{value === null ? '—' : String(value)}</dd>
                                    </div>
                                ))}
                            </dl>
                        )}
                        <p className="mt-2 text-xs opacity-80">Tested {formatDateTime(result.tested_at, timeZone)}</p>
                    </section>
                )}

                {device.last_error && device.last_status.value === 'unreachable' && (
                    <div role="alert" className="flex items-start gap-2 rounded-lg border border-red-600/30 bg-red-50 px-3 py-2 text-sm text-red-950 dark:bg-red-950/40 dark:text-red-100">
                        <CircleAlert className="mt-0.5 size-4 shrink-0" aria-hidden="true" />
                        <span>
                            <span className="font-medium">Last error:</span> {device.last_error}
                        </span>
                    </div>
                )}

                <section aria-label="Device details" className="rounded-xl border bg-card p-4 text-card-foreground shadow-xs">
                    <h2 className="mb-3 text-base font-semibold">Details</h2>
                    <dl className="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                        <Detail label="Type">{device.device_type.label}</Detail>
                        <Detail label="Location">{device.location ?? '—'}</Detail>
                        <Detail label="Vendor and model">{[device.vendor, device.model].filter(Boolean).join(' ') || '—'}</Detail>
                        <Detail label="SNMP version">{device.snmp_version.label}</Detail>
                        <Detail label="SNMP port">{device.snmp_port}</Detail>
                        <Detail label="Polling interval">{device.polling_interval_seconds} seconds</Detail>
                        <Detail label="Credentials">{device.has_credentials ? 'Stored encrypted (never shown)' : 'None stored'}</Detail>
                        <Detail label="Last polled">
                            {formatDateTime(device.last_polled_at, timeZone)}
                            {device.last_polled_at ? ` (${timeAgo(device.last_polled_at)})` : ''}
                        </Detail>
                        <Detail label="Last successful poll">
                            {formatDateTime(device.last_successful_poll_at, timeZone)}
                            {device.last_successful_poll_at ? ` (${timeAgo(device.last_successful_poll_at)})` : ''}
                        </Detail>
                        <Detail label="Added">{formatDateTime(device.created_at, timeZone)}</Detail>
                        <Detail label="Last changed">{formatDateTime(device.updated_at, timeZone)}</Detail>
                        <Detail label="Open alerts">{device.open_alerts_count}</Detail>
                    </dl>
                    {device.description && (
                        <div className="mt-4 border-t pt-3">
                            <h3 className="text-xs text-muted-foreground">Description</h3>
                            <p className="mt-0.5 text-sm whitespace-pre-line">{device.description}</p>
                        </div>
                    )}
                </section>

                <div className="grid gap-4 xl:grid-cols-2">
                    <section aria-label="Interfaces" className="rounded-xl border bg-card p-4 text-card-foreground shadow-xs">
                        <h2 className="mb-3 text-base font-semibold">Interfaces ({device.interfaces_count})</h2>
                        {interfaces.length === 0 ? (
                            <EmptyState icon={PlugZap} title="No interfaces recorded" description="Interfaces are listed here after the first successful poll. Interface discovery arrives with SNMP polling (Phase 3)." />
                        ) : (
                            <div className="overflow-x-auto">
                                <table className="w-full text-sm">
                                    <caption className="sr-only">Interfaces of {device.name}</caption>
                                    <thead className="text-left text-xs text-muted-foreground">
                                        <tr>
                                            <th scope="col" className="py-1 pr-3 font-medium">Index</th>
                                            <th scope="col" className="py-1 pr-3 font-medium">Name</th>
                                            <th scope="col" className="py-1 pr-3 font-medium">Role</th>
                                            <th scope="col" className="py-1 pr-3 font-medium">Link</th>
                                            <th scope="col" className="py-1 font-medium">Monitored</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        {interfaces.map((row) => (
                                            <tr key={row.id} className="border-t">
                                                <td className="py-2 pr-3 tabular-nums">{row.if_index}</td>
                                                <td className="py-2 pr-3 font-medium">{row.name}</td>
                                                <td className="py-2 pr-3">{row.role.label}</td>
                                                <td className="py-2 pr-3">{row.oper_status ?? 'Unknown'}</td>
                                                <td className="py-2">{row.is_monitored ? 'Yes' : 'No'}</td>
                                            </tr>
                                        ))}
                                    </tbody>
                                </table>
                            </div>
                        )}
                    </section>

                    <section aria-label="Recent alerts" className="rounded-xl border bg-card p-4 text-card-foreground shadow-xs">
                        <h2 className="mb-3 text-base font-semibold">Recent alerts</h2>
                        {alerts.length === 0 ? (
                            <EmptyState icon={CircleCheck} title="No alerts for this device" />
                        ) : (
                            <ul className="divide-y">
                                {alerts.map((alert) => (
                                    <li key={alert.id} className="flex items-start justify-between gap-3 py-2">
                                        <div className="min-w-0">
                                            <p className="truncate text-sm font-medium">{alert.title}</p>
                                            <p className="text-xs text-muted-foreground">{formatDateTime(alert.triggered_at, timeZone)}</p>
                                        </div>
                                        <div className="flex shrink-0 items-center gap-2">
                                            <SeverityBadge severity={alert.severity} />
                                            <span className="text-xs capitalize text-muted-foreground">{alert.status}</span>
                                        </div>
                                    </li>
                                ))}
                            </ul>
                        )}
                    </section>
                </div>
            </div>
        </AppLayout>
    );
}