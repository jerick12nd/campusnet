import { Link, useForm } from '@inertiajs/react';
import { useState } from 'react';
import type { FormEvent, ReactNode } from 'react';

import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import type { Option } from '@/types/campusnet';

type Credentials = {
    community: string;
    username: string;
    security_level: 'authNoPriv' | 'authPriv';
    auth_protocol: 'SHA' | 'MD5';
    auth_password: string;
    priv_protocol: 'AES' | 'DES';
    priv_password: string;
};

type DeviceFormData = {
    name: string;
    management_ip: string;
    vendor: string;
    model: string;
    device_type: string;
    location: string;
    description: string;
    is_enabled: boolean;
    snmp_version: string;
    snmp_port: number | '';
    polling_interval_seconds: number | '';
    credentials: Credentials | null;
};

export type DeviceFormInitial = Omit<DeviceFormData, 'credentials'>;

type Props = {
    mode: 'create' | 'edit';
    deviceId?: number;
    initial: DeviceFormInitial;
    /** Edit only: whether credentials are already stored (their content is never sent to the browser). */
    hasCredentials?: boolean;
    options: { types: Option[]; versions: Option[] };
};

const FIELD =
    'border-input bg-transparent w-full rounded-md border px-3 text-sm shadow-xs outline-none placeholder:text-muted-foreground focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50 aria-invalid:border-destructive disabled:opacity-50';
const SELECT = `${FIELD} h-9`;
const TEXTAREA = `${FIELD} min-h-20 py-2`;

const emptyCredentials = (): Credentials => ({
    community: '',
    username: '',
    security_level: 'authPriv',
    auth_protocol: 'SHA',
    auth_password: '',
    priv_protocol: 'AES',
    priv_password: '',
});

function Field({ id, label, error, hint, required, children }: { id: string; label: string; error?: string; hint?: string; required?: boolean; children: ReactNode }) {
    return (
        <div className="grid gap-1.5">
            <Label htmlFor={id}>
                {label}
                {required && <span aria-hidden="true"> *</span>}
            </Label>
            {children}
            {hint && (
                <p id={`${id}-hint`} className="text-xs text-muted-foreground">
                    {hint}
                </p>
            )}
            {error && (
                <p id={`${id}-error`} role="alert" className="text-sm text-destructive">
                    {error}
                </p>
            )}
        </div>
    );
}

export function DeviceForm({ mode, deviceId, initial, hasCredentials = false, options }: Props) {
    const form = useForm<DeviceFormData>({ ...initial, credentials: emptyCredentials() });
    const [replaceCredentials, setReplaceCredentials] = useState(false);

    const versionChanged = mode === 'edit' && form.data.snmp_version !== initial.snmp_version;
    const mustEnterCredentials = mode === 'create' || !hasCredentials || versionChanged;
    const showCredentials = mustEnterCredentials || replaceCredentials;
    const credentials = form.data.credentials ?? emptyCredentials();
    const isV3 = form.data.snmp_version === 'v3';

    const error = (key: string): string | undefined => (form.errors as Record<string, string | undefined>)[key];
    const describedBy = (id: string, hasHint = false) => [hasHint ? `${id}-hint` : null, error(id) ? `${id}-error` : null].filter(Boolean).join(' ') || undefined;

    const set = <K extends keyof DeviceFormData>(key: K, value: DeviceFormData[K]) => form.setData((data) => ({ ...data, [key]: value }));
    const setCredential = <K extends keyof Credentials>(key: K, value: Credentials[K]) =>
        form.setData((data) => ({ ...data, credentials: { ...(data.credentials ?? emptyCredentials()), [key]: value } }));
    const asNumber = (value: string): number | '' => (value === '' ? '' : Number(value));

    function submit(event: FormEvent) {
        event.preventDefault();

        // Credentials are only sent when they are being entered; otherwise the server keeps the stored ones.
        form.transform((data) => ({ ...data, credentials: showCredentials ? data.credentials : null }));

        if (mode === 'create') {
            form.post('/devices', { preserveScroll: true });
        } else {
            form.put(`/devices/${deviceId}`, { preserveScroll: true });
        }
    }

    return (
        <form onSubmit={submit} className="grid max-w-3xl gap-8" noValidate>
            <fieldset className="grid gap-4 rounded-xl border p-4">
                <legend className="px-1 text-sm font-semibold">Device</legend>

                <div className="grid gap-4 sm:grid-cols-2">
                    <Field id="name" label="Device name" required error={error('name')}>
                        <Input id="name" value={form.data.name} onChange={(e) => set('name', e.target.value)} aria-invalid={!!error('name')} aria-describedby={describedBy('name')} maxLength={120} required />
                    </Field>
                    <Field id="management_ip" label="Management IP address" required error={error('management_ip')} hint="Only this stored address is ever contacted. Loopback and link-local addresses are not allowed.">
                        <Input id="management_ip" value={form.data.management_ip} onChange={(e) => set('management_ip', e.target.value)} aria-invalid={!!error('management_ip')} aria-describedby={describedBy('management_ip', true)} inputMode="decimal" autoComplete="off" required />
                    </Field>
                    <Field id="device_type" label="Device type" required error={error('device_type')}>
                        <select id="device_type" className={SELECT} value={form.data.device_type} onChange={(e) => set('device_type', e.target.value)} aria-invalid={!!error('device_type')}>
                            {options.types.map((type) => (
                                <option key={type.value} value={type.value}>
                                    {type.label}
                                </option>
                            ))}
                        </select>
                    </Field>
                    <Field id="location" label="Building / location" error={error('location')}>
                        <Input id="location" value={form.data.location} onChange={(e) => set('location', e.target.value)} aria-invalid={!!error('location')} maxLength={120} />
                    </Field>
                    <Field id="vendor" label="Vendor" error={error('vendor')}>
                        <Input id="vendor" value={form.data.vendor} onChange={(e) => set('vendor', e.target.value)} aria-invalid={!!error('vendor')} maxLength={80} />
                    </Field>
                    <Field id="model" label="Model" error={error('model')}>
                        <Input id="model" value={form.data.model} onChange={(e) => set('model', e.target.value)} aria-invalid={!!error('model')} maxLength={80} />
                    </Field>
                </div>

                <Field id="description" label="Description" error={error('description')}>
                    <textarea id="description" className={TEXTAREA} value={form.data.description} onChange={(e) => set('description', e.target.value)} aria-invalid={!!error('description')} maxLength={2000} />
                </Field>
            </fieldset>

            <fieldset className="grid gap-4 rounded-xl border p-4">
                <legend className="px-1 text-sm font-semibold">Monitoring</legend>

                <div className="grid gap-4 sm:grid-cols-2">
                    <Field id="polling_interval_seconds" label="Polling interval (seconds)" required error={error('polling_interval_seconds')} hint="Between 30 and 3600 seconds.">
                        <Input id="polling_interval_seconds" type="number" min={30} max={3600} value={form.data.polling_interval_seconds} onChange={(e) => set('polling_interval_seconds', asNumber(e.target.value))} aria-invalid={!!error('polling_interval_seconds')} aria-describedby={describedBy('polling_interval_seconds', true)} required />
                    </Field>
                    <Field id="snmp_port" label="SNMP port" required error={error('snmp_port')}>
                        <Input id="snmp_port" type="number" min={1} max={65535} value={form.data.snmp_port} onChange={(e) => set('snmp_port', asNumber(e.target.value))} aria-invalid={!!error('snmp_port')} required />
                    </Field>
                </div>

                <label className="flex items-center gap-2 text-sm">
                    <input type="checkbox" className="size-4" checked={form.data.is_enabled} onChange={(e) => set('is_enabled', e.target.checked)} />
                    Monitor this device
                </label>
            </fieldset>

            <fieldset className="grid gap-4 rounded-xl border p-4">
                <legend className="px-1 text-sm font-semibold">SNMP access (read-only)</legend>

                <Field id="snmp_version" label="SNMP version" required error={error('snmp_version')} hint="SNMPv3 with SHA authentication and AES privacy is recommended.">
                    <select id="snmp_version" className={SELECT} value={form.data.snmp_version} onChange={(e) => set('snmp_version', e.target.value)} aria-invalid={!!error('snmp_version')} aria-describedby={describedBy('snmp_version', true)}>
                        {options.versions.map((version) => (
                            <option key={version.value} value={version.value}>
                                {version.label}
                            </option>
                        ))}
                    </select>
                </Field>

                {mode === 'edit' && hasCredentials && !versionChanged && (
                    <div className="rounded-lg bg-muted/50 p-3 text-sm">
                        <p>Credentials are stored encrypted and are never shown. Leave them alone to keep them.</p>
                        <label className="mt-2 flex items-center gap-2">
                            <input type="checkbox" className="size-4" checked={replaceCredentials} onChange={(e) => setReplaceCredentials(e.target.checked)} />
                            Replace the stored credentials
                        </label>
                    </div>
                )}

                {versionChanged && (
                    <p role="status" className="rounded-lg bg-amber-50 p-3 text-sm text-amber-950 dark:bg-amber-950/40 dark:text-amber-100">
                        Changing the SNMP version needs new credentials.
                    </p>
                )}

                {error('credentials') && (
                    <p role="alert" className="text-sm text-destructive">
                        {error('credentials')}
                    </p>
                )}

                {showCredentials && !isV3 && (
                    <>
                        <p className="rounded-lg bg-amber-50 p-3 text-sm text-amber-950 dark:bg-amber-950/40 dark:text-amber-100">
                            SNMPv2c sends the community string unencrypted. Use it only on an approved, trusted management network.
                        </p>
                        <Field id="credentials.community" label="Read-only community string" required error={error('credentials.community')} hint='Do not use "public" or "private".'>
                            <Input id="credentials.community" type="password" autoComplete="new-password" value={credentials.community} onChange={(e) => setCredential('community', e.target.value)} aria-invalid={!!error('credentials.community')} aria-describedby={describedBy('credentials.community', true)} />
                        </Field>
                    </>
                )}

                {showCredentials && isV3 && (
                    <div className="grid gap-4 sm:grid-cols-2">
                        <Field id="credentials.username" label="SNMPv3 user name" required error={error('credentials.username')}>
                            <Input id="credentials.username" autoComplete="off" value={credentials.username} onChange={(e) => setCredential('username', e.target.value)} aria-invalid={!!error('credentials.username')} />
                        </Field>
                        <Field id="credentials.security_level" label="Security level" required error={error('credentials.security_level')}>
                            <select id="credentials.security_level" className={SELECT} value={credentials.security_level} onChange={(e) => setCredential('security_level', e.target.value as Credentials['security_level'])}>
                                <option value="authPriv">Authentication and privacy (authPriv)</option>
                                <option value="authNoPriv">Authentication only (authNoPriv)</option>
                            </select>
                        </Field>
                        <Field id="credentials.auth_protocol" label="Authentication protocol" required error={error('credentials.auth_protocol')}>
                            <select id="credentials.auth_protocol" className={SELECT} value={credentials.auth_protocol} onChange={(e) => setCredential('auth_protocol', e.target.value as Credentials['auth_protocol'])}>
                                <option value="SHA">SHA (recommended)</option>
                                <option value="MD5">MD5 (weak, legacy devices only)</option>
                            </select>
                        </Field>
                        <Field id="credentials.auth_password" label="Authentication passphrase" required error={error('credentials.auth_password')} hint="At least 8 characters.">
                            <Input id="credentials.auth_password" type="password" autoComplete="new-password" value={credentials.auth_password} onChange={(e) => setCredential('auth_password', e.target.value)} aria-invalid={!!error('credentials.auth_password')} aria-describedby={describedBy('credentials.auth_password', true)} />
                        </Field>

                        {credentials.security_level === 'authPriv' && (
                            <>
                                <Field id="credentials.priv_protocol" label="Privacy protocol" required error={error('credentials.priv_protocol')}>
                                    <select id="credentials.priv_protocol" className={SELECT} value={credentials.priv_protocol} onChange={(e) => setCredential('priv_protocol', e.target.value as Credentials['priv_protocol'])}>
                                        <option value="AES">AES (recommended)</option>
                                        <option value="DES">DES (weak, legacy devices only)</option>
                                    </select>
                                </Field>
                                <Field id="credentials.priv_password" label="Privacy passphrase" required error={error('credentials.priv_password')} hint="At least 8 characters.">
                                    <Input id="credentials.priv_password" type="password" autoComplete="new-password" value={credentials.priv_password} onChange={(e) => setCredential('priv_password', e.target.value)} aria-invalid={!!error('credentials.priv_password')} aria-describedby={describedBy('credentials.priv_password', true)} />
                                </Field>
                            </>
                        )}
                    </div>
                )}
            </fieldset>

            <div className="flex items-center gap-3">
                <Button type="submit" disabled={form.processing}>
                    {form.processing ? 'Saving…' : mode === 'create' ? 'Add device' : 'Save changes'}
                </Button>
                <Button type="button" variant="outline" asChild>
                    <Link href={mode === 'create' ? '/devices' : `/devices/${deviceId}`}>Cancel</Link>
                </Button>
            </div>
        </form>
    );
}