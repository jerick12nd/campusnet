import { Head, router, useForm, usePage } from '@inertiajs/react';
import { Tv } from 'lucide-react';
import type { FormEvent } from 'react';

import { FlashMessages } from '@/components/flash-messages';
import { Pill } from '@/components/status-badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import AppLayout from '@/layouts/app-layout';
import { formatDateTime, timeAgo } from '@/lib/format';
import type { CampusNetShared } from '@/types/campusnet';

type DisplayRow = {
    id: number;
    name: string;
    status: 'waiting' | 'paired' | 'expired' | 'revoked';
    paired_at: string | null;
    last_seen_at: string | null;
};

type Pairing = { name: string; code: string; expires_at: string } | null;

const STATUS = {
    paired: { label: 'Paired', tone: 'green' },
    waiting: { label: 'Waiting for code', tone: 'blue' },
    expired: { label: 'Code expired', tone: 'gray' },
    revoked: { label: 'Revoked', tone: 'red' },
} as const;

export default function TvDisplaysIndex({ displays, pairing }: { displays: DisplayRow[]; pairing: Pairing }) {
    const timeZone = usePage<CampusNetShared>().props.campusnet?.timezone;
    const form = useForm({ name: '' });

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
        <AppLayout breadcrumbs={[{ title: 'TV Displays', href: '/tv-displays' }]}>
            <Head title="TV Displays" />

            <div className="flex flex-col gap-4 p-4">
                <div className="flex flex-wrap items-center justify-between gap-3">
                    <div>
                        <h1 className="text-xl font-semibold">TV displays</h1>
                        <p className="text-sm text-muted-foreground">
                            Each TV gets its own one-time 8-digit code. Revoke a display at any time to cut its access.
                        </p>
                    </div>
                    <Button asChild variant="outline">
                        <a href="/tv-display" target="_blank" rel="noreferrer">
                            <Tv className="size-4" aria-hidden="true" />
                            Preview display
                        </a>
                    </Button>
                </div>

                <FlashMessages />

                {pairing && (
                    <div role="status" className="rounded-lg border-2 border-sky-500/60 bg-sky-500/10 p-5">
                        <p className="text-sm text-muted-foreground">
                            Pairing code for <strong>{pairing.name}</strong>. It is shown only once and works for 15 minutes.
                        </p>
                        <p className="mt-2 text-5xl font-semibold tracking-[0.2em] tabular-nums">
                            {pairing.code.slice(0, 4)} {pairing.code.slice(4)}
                        </p>
                        <p className="mt-2 text-sm text-muted-foreground">
                            On the TV, open the CampusNet address, choose “TV Display” on the sign-in page and type this code.
                            Expires {formatDateTime(pairing.expires_at, timeZone)}.
                        </p>
                    </div>
                )}

                <form onSubmit={create} className="flex flex-wrap items-end gap-3">
                    <div className="min-w-64 flex-1">
                        <label htmlFor="display-name" className="mb-1 block text-sm font-medium">
                            New display name
                        </label>
                        <Input
                            id="display-name"
                            value={form.data.name}
                            onChange={(event) => form.setData('name', event.target.value)}
                            placeholder="e.g. ICT Office TV"
                            maxLength={80}
                        />
                        {form.errors.name && <p className="mt-1 text-sm text-red-600">{form.errors.name}</p>}
                    </div>
                    <Button type="submit" disabled={form.processing || form.data.name.trim() === ''}>
                        Generate code
                    </Button>
                </form>

                <div className="overflow-x-auto rounded-lg border">
                    <table className="w-full text-left text-sm">
                        <thead className="bg-muted/50 text-muted-foreground">
                            <tr>
                                <th className="px-3 py-2 font-medium">Display</th>
                                <th className="px-3 py-2 font-medium">Status</th>
                                <th className="px-3 py-2 font-medium">Paired</th>
                                <th className="px-3 py-2 font-medium">Last seen</th>
                                <th className="px-3 py-2" />
                            </tr>
                        </thead>
                        <tbody className="divide-y">
                            {displays.length === 0 && (
                                <tr>
                                    <td colSpan={5} className="px-3 py-6 text-center text-muted-foreground">
                                        No displays yet. Enter a name above to generate a pairing code.
                                    </td>
                                </tr>
                            )}
                            {displays.map((display) => {
                                const status = STATUS[display.status];

                                return (
                                    <tr key={display.id}>
                                        <td className="px-3 py-2 font-medium">{display.name}</td>
                                        <td className="px-3 py-2">
                                            <Pill tone={status.tone}>{status.label}</Pill>
                                        </td>
                                        <td className="px-3 py-2">{display.paired_at ? formatDateTime(display.paired_at, timeZone) : '—'}</td>
                                        <td className="px-3 py-2">{display.last_seen_at ? timeAgo(display.last_seen_at) : '—'}</td>
                                        <td className="px-3 py-2 text-right">
                                            {display.status !== 'revoked' && (
                                                <Button type="button" variant="outline" size="sm" onClick={() => revoke(display)}>
                                                    Revoke
                                                </Button>
                                            )}
                                        </td>
                                    </tr>
                                );
                            })}
                        </tbody>
                    </table>
                </div>
            </div>
        </AppLayout>
    );
}