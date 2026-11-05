import { Head, router, useForm, usePage } from '@inertiajs/react';
import { Check, Copy, Eye, EyeOff, Tv } from 'lucide-react';
import { useState } from 'react';
import type { FormEvent } from 'react';

import { FlashMessages } from '@/components/flash-messages';
import { Pill } from '@/components/status-badge';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import AppLayout from '@/layouts/app-layout';
import SettingsLayout from '@/layouts/settings/layout';
import { formatDateTime, timeAgo } from '@/lib/format';
import type { CampusNetShared } from '@/types/campusnet';

type DisplayRow = {
    id: number;
    name: string;
    status: 'waiting' | 'paired' | 'expired' | 'revoked';
    reusable: boolean;
    code: string | null;
    paired_at: string | null;
    last_seen_at: string | null;
};

type Pairing = { name: string; code: string; reusable: boolean; expires_at: string | null } | null;

const STATUS = {
    paired: { label: 'Paired', tone: 'green' },
    waiting: { label: 'Waiting for code', tone: 'blue' },
    expired: { label: 'Code expired', tone: 'gray' },
    revoked: { label: 'Revoked', tone: 'red' },
} as const;

function groupCode(code: string): string {
    return code.replace(/(\d{4})(?=\d)/g, '$1 ');
}

/** A code with Hide/Show and Copy. Visible by default; hide it before sharing your screen. */
function CodeBox({ code }: { code: string }) {
    const [hidden, setHidden] = useState(false);
    const [copied, setCopied] = useState(false);

    const copy = async () => {
        try {
            await navigator.clipboard.writeText(code);
            setCopied(true);
            window.setTimeout(() => setCopied(false), 2000);
        } catch {
            window.prompt('Copy this code:', code);
        }
    };

    return (
        <div className="flex flex-wrap items-center gap-x-3 gap-y-1">
            <span className="font-mono text-2xl font-semibold tracking-wider tabular-nums">{hidden ? '••••••••••' : groupCode(code)}</span>
            <span className="flex items-center gap-1">
                <Button type="button" variant="ghost" size="sm" onClick={() => setHidden((value) => !value)}>
                    {hidden ? <Eye className="size-4" aria-hidden="true" /> : <EyeOff className="size-4" aria-hidden="true" />}
                    {hidden ? 'Show' : 'Hide'}
                </Button>
                <Button type="button" variant="ghost" size="sm" onClick={() => void copy()}>
                    {copied ? <Check className="size-4" aria-hidden="true" /> : <Copy className="size-4" aria-hidden="true" />}
                    {copied ? 'Copied' : 'Copy'}
                </Button>
            </span>
        </div>
    );
}

export default function TvDisplaySettings({ displays, pairing }: { displays: DisplayRow[]; pairing: Pairing }) {
    const timeZone = usePage<CampusNetShared>().props.campusnet?.timezone;
    const form = useForm({ name: '', reusable: false });

    const create = (event: FormEvent) => {
        event.preventDefault();
        form.post('/tv-displays', { preserveScroll: true, onSuccess: () => form.reset() });
    };

    const revoke = (display: DisplayRow) => {
        if (window.confirm(`Revoke "${display.name}"? It will stop showing data immediately.`)) {
            router.delete(`/tv-displays/${display.id}`, { preserveScroll: true });
        }
    };

    return (
        <AppLayout breadcrumbs={[{ title: 'TV Display settings', href: '/settings/tv-display' }]}>
            <Head title="TV Display settings" />

            <SettingsLayout>
                <div className="space-y-6">
                    <div className="flex flex-wrap items-start justify-between gap-3">
                        <header>
                            <h2 className="mb-0.5 text-base font-medium">TV Display</h2>
                            <p className="text-sm text-muted-foreground">
                                Each TV connects with a numeric code. Revoke a display at any time to cut its access.
                            </p>
                        </header>
                        <Button asChild variant="outline" size="sm">
                            <a href="/tv-display" target="_blank" rel="noreferrer">
                                <Tv className="size-4" aria-hidden="true" />
                                Preview
                            </a>
                        </Button>
                    </div>

                    <FlashMessages />

                    {pairing && (
                        <div role="status" className="rounded-lg border-2 border-sky-500/60 bg-sky-500/10 p-4">
                            <p className="text-sm text-muted-foreground">
                                Code for <strong>{pairing.name}</strong>.{' '}
                                {pairing.reusable
                                    ? 'It does not expire and stays visible in the list below.'
                                    : 'It is shown only once and works for 15 minutes.'}
                            </p>
                            <p className="mt-2 font-mono text-3xl font-semibold tracking-wider tabular-nums">{groupCode(pairing.code)}</p>
                            <p className="mt-2 text-sm text-muted-foreground">
                                On the TV, open the CampusNet address, choose “Use a TV Display code” on the sign-in page and type this code.
                                {pairing.expires_at && <> Expires {formatDateTime(pairing.expires_at, timeZone)}.</>}
                            </p>
                        </div>
                    )}

                    <form onSubmit={create} className="space-y-3 rounded-lg border p-4">
                        <div>
                            <label htmlFor="display-name" className="mb-1 block text-sm font-medium">
                                New display name
                            </label>
                            <div className="flex gap-2">
                                <Input
                                    id="display-name"
                                    value={form.data.name}
                                    onChange={(event) => form.setData('name', event.target.value)}
                                    placeholder="e.g. ICT Office TV"
                                    maxLength={80}
                                />
                                <Button type="submit" disabled={form.processing || form.data.name.trim() === ''}>
                                    Generate code
                                </Button>
                            </div>
                            {form.errors.name && <p className="mt-1 text-sm text-red-600">{form.errors.name}</p>}
                        </div>

                        <div className="flex items-start gap-3">
                            <Checkbox
                                id="reusable"
                                checked={form.data.reusable}
                                onCheckedChange={(checked) => form.setData('reusable', checked === true)}
                            />
                            <label htmlFor="reusable" className="text-sm">
                                <span className="font-medium">Keep this code (reusable, does not expire)</span>
                                <span className="block text-muted-foreground">
                                    A 10-digit code that stays visible here. Anyone who knows it can open the wallboard until you revoke it.
                                    Using it on a new device disconnects the old one.
                                </span>
                            </label>
                        </div>
                    </form>

                    <ul className="space-y-3">
                        {displays.length === 0 && (
                            <li className="rounded-lg border p-6 text-center text-sm text-muted-foreground">
                                No displays yet. Enter a name above to generate a code.
                            </li>
                        )}

                        {displays.map((display) => {
                            const status = STATUS[display.status];

                            return (
                                <li key={display.id} className="space-y-3 rounded-lg border p-4">
                                    <div className="flex flex-wrap items-center justify-between gap-2">
                                        <span className="font-medium">{display.name}</span>
                                        <Pill tone={status.tone}>{status.label}</Pill>
                                    </div>

                                    {display.reusable && display.code ? (
                                        <CodeBox code={display.code} />
                                    ) : (
                                        <p className="text-sm text-muted-foreground">
                                            {display.reusable ? 'No code available.' : 'One-time code (shown only when it was generated).'}
                                        </p>
                                    )}

                                    <div className="flex flex-wrap items-center justify-between gap-2 text-xs text-muted-foreground">
                                        <span>
                                            Paired: {display.paired_at ? formatDateTime(display.paired_at, timeZone) : '—'} · Last seen:{' '}
                                            {display.last_seen_at ? timeAgo(display.last_seen_at) : '—'}
                                        </span>
                                        {display.status !== 'revoked' && (
                                            <Button type="button" variant="outline" size="sm" onClick={() => revoke(display)}>
                                                Revoke
                                            </Button>
                                        )}
                                    </div>
                                </li>
                            );
                        })}
                    </ul>
                </div>
            </SettingsLayout>
        </AppLayout>
    );
}