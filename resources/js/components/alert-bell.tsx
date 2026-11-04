import { Link } from '@inertiajs/react';
import { Bell, BellRing, CircleAlert, TriangleAlert, Volume2, VolumeX, X } from 'lucide-react';
import { useCallback, useEffect, useState } from 'react';
import { createPortal } from 'react-dom';

import { Button } from '@/components/ui/button';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuLabel,
    DropdownMenuSeparator,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { playAlertSound, primeAlertSound, setSoundEnabled, soundEnabled } from '@/lib/alert-sound';
import { cn } from '@/lib/utils';

type Severity = 'warning' | 'critical';

type FeedAlert = {
    id: number;
    title: string;
    severity: Severity;
    type: string;
    device: string | null;
    triggered_at: string;
};

type Feed = {
    counts: { critical: number; warning: number; total: number };
    alerts: FeedAlert[];
};

type ToastItem = { key: string; severity: Severity; title: string; detail: string };

const POLL_MS = 15_000;
const HIDDEN_POLL_MS = 60_000;
const SEEN_KEY = 'campusnet.alerts.seen';

const SEVERITY = {
    critical: { label: 'Critical', Icon: CircleAlert, text: 'text-red-500', bar: 'border-l-red-500' },
    warning: { label: 'Warning', Icon: TriangleAlert, text: 'text-amber-500', bar: 'border-l-amber-500' },
} as const;

function ago(iso: string): string {
    const seconds = Math.max(0, Math.round((Date.now() - Date.parse(iso)) / 1000));

    if (Number.isNaN(seconds) || seconds < 60) {
        return 'just now';
    }

    if (seconds < 3600) {
        return `${Math.floor(seconds / 60)} min ago`;
    }

    return seconds < 86400 ? `${Math.floor(seconds / 3600)} h ago` : `${Math.floor(seconds / 86400)} d ago`;
}

/* ---- Which alerts this browser tab has already announced (survives page changes and reloads) ---- */

let seenInMemory: Set<number> | null = null;

function readSeen(): Set<number> | null {
    if (seenInMemory) {
        return seenInMemory;
    }

    try {
        const raw = sessionStorage.getItem(SEEN_KEY);

        if (raw === null) {
            return null;
        }

        const list: unknown = JSON.parse(raw);

        return Array.isArray(list) ? new Set(list.filter((value): value is number => typeof value === 'number')) : null;
    } catch {
        return null;
    }
}

function writeSeen(seen: Set<number>): void {
    seenInMemory = seen;

    try {
        sessionStorage.setItem(SEEN_KEY, JSON.stringify([...seen].slice(-200)));
    } catch {
        // storage unavailable: the in-memory copy still prevents repeats until a reload
    }
}

/* ---- Polling: one timer chain, slower while the tab is hidden, stops when signed out ---- */

function useAlertFeed(): { feed: Feed | null; failed: boolean } {
    const [feed, setFeed] = useState<Feed | null>(null);
    const [failed, setFailed] = useState(false);

    useEffect(() => {
        let timer: number | undefined;
        let controller: AbortController | undefined;
        let active = true;

        const schedule = () => {
            window.clearTimeout(timer);

            if (active) {
                timer = window.setTimeout(() => void load(), document.hidden ? HIDDEN_POLL_MS : POLL_MS);
            }
        };

        const load = async () => {
            controller?.abort();
            controller = new AbortController();

            let keepPolling = true;

            try {
                const response = await fetch('/alerts/feed', {
                    headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                    credentials: 'same-origin',
                    cache: 'no-store',
                    signal: controller.signal,
                });

                if ([401, 403, 419].includes(response.status)) {
                    keepPolling = false;

                    return;
                }

                if (!response.ok) {
                    throw new Error(`HTTP ${response.status}`);
                }

                const body = (await response.json()) as Feed;

                if (active) {
                    setFeed(body);
                    setFailed(false);
                }
            } catch (error) {
                if ((error as Error).name !== 'AbortError' && active) {
                    setFailed(true);
                }
            } finally {
                if (keepPolling) {
                    schedule();
                }
            }
        };

        const onVisible = () => {
            if (!document.hidden) {
                void load();
            }
        };

        void load();
        document.addEventListener('visibilitychange', onVisible);

        return () => {
            active = false;
            window.clearTimeout(timer);
            controller?.abort();
            document.removeEventListener('visibilitychange', onVisible);
        };
    }, []);

    return { feed, failed };
}

/* ---- Popup message ---- */

function Toast({ item, onClose }: { item: ToastItem; onClose: (key: string) => void }) {
    const meta = SEVERITY[item.severity];

    useEffect(() => {
        const timer = window.setTimeout(() => onClose(item.key), item.severity === 'critical' ? 20_000 : 10_000);

        return () => window.clearTimeout(timer);
    }, [item.key, item.severity, onClose]);

    return (
        <div
            role={item.severity === 'critical' ? 'alert' : 'status'}
            className={cn(
                'pointer-events-auto flex w-[22rem] max-w-[calc(100vw-2rem)] items-start gap-3 rounded-lg border border-l-4 bg-popover p-3 text-popover-foreground shadow-lg',
                'motion-safe:animate-in motion-safe:fade-in motion-safe:slide-in-from-right-8 motion-safe:duration-300',
                meta.bar,
            )}
        >
            <meta.Icon className={cn('mt-0.5 size-5 shrink-0', meta.text)} aria-hidden="true" />
            <div className="min-w-0 flex-1">
                <p className={cn('text-xs font-semibold tracking-wide uppercase', meta.text)}>{meta.label} alert</p>
                <p className="text-sm font-medium break-words">{item.title}</p>
                <p className="text-xs text-muted-foreground">{item.detail}</p>
                <Link href="/alerts" className="mt-1 inline-block text-xs font-medium underline-offset-2 hover:underline">
                    View alerts
                </Link>
            </div>
            <button
                type="button"
                aria-label="Dismiss notification"
                onClick={() => onClose(item.key)}
                className="rounded p-1 text-muted-foreground hover:bg-accent hover:text-accent-foreground focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
            >
                <X className="size-4" aria-hidden="true" />
            </button>
        </div>
    );
}

/* ---- The bell ---- */

export function AlertBell() {
    const { feed, failed } = useAlertFeed();
    const [toasts, setToasts] = useState<ToastItem[]>([]);
    const [soundOn, setSoundOn] = useState<boolean>(soundEnabled);
    const [blocked, setBlocked] = useState(false);

    const dismiss = useCallback((key: string) => setToasts((current) => current.filter((toast) => toast.key !== key)), []);

    // The first click or key press unlocks sound for the rest of the visit.
    useEffect(() => {
        const unlock = () => primeAlertSound();

        window.addEventListener('pointerdown', unlock, { once: true });
        window.addEventListener('keydown', unlock, { once: true });

        return () => {
            window.removeEventListener('pointerdown', unlock);
            window.removeEventListener('keydown', unlock);
        };
    }, []);

    // Announce alerts this tab has not seen yet. The first load only records what already exists.
    useEffect(() => {
        if (!feed) {
            return;
        }

        const seen = readSeen();
        const ids = feed.alerts.map((alert) => alert.id);

        if (seen === null) {
            writeSeen(new Set(ids));

            return;
        }

        const fresh = feed.alerts.filter((alert) => !seen.has(alert.id));

        if (fresh.length === 0) {
            return;
        }

        writeSeen(new Set([...seen, ...ids]));

        const items: ToastItem[] = fresh.slice(0, 3).map((alert) => ({
            key: `alert-${alert.id}`,
            severity: alert.severity,
            title: alert.title,
            detail: `${alert.device ?? 'Network-wide'} · ${ago(alert.triggered_at)}`,
        }));

        if (fresh.length > 3) {
            items.push({
                key: `more-${Date.now()}`,
                severity: fresh.some((alert) => alert.severity === 'critical') ? 'critical' : 'warning',
                title: `${fresh.length - 3} more new alerts`,
                detail: 'Open the alerts list to see them.',
            });
        }

        setToasts((current) => [...items, ...current].slice(0, 4));

        void playAlertSound().then((played) => setBlocked(!played && soundEnabled()));
    }, [feed]);

    const total = feed?.counts.total ?? 0;
    const critical = feed?.counts.critical ?? 0;
    const alerts = feed?.alerts ?? [];

    const toggleSound = () => {
        const next = !soundOn;

        setSoundOn(next);
        setSoundEnabled(next);
    };

    const testSound = () => {
        void playAlertSound(true).then((played) => setBlocked(!played));
    };

    return (
        <>
            <DropdownMenu>
                <DropdownMenuTrigger asChild>
                    <Button
                        variant="ghost"
                        size="icon"
                        className="relative size-9"
                        aria-label={total > 0 ? `Notifications: ${total} active alert${total === 1 ? '' : 's'}` : 'Notifications: no active alerts'}
                        data-test="alert-bell"
                    >
                        {total > 0 ? <BellRing className="size-5" aria-hidden="true" /> : <Bell className="size-5" aria-hidden="true" />}
                        {total > 0 && (
                            <span
                                className={cn(
                                    'absolute -top-0.5 -right-0.5 flex h-[1.1rem] min-w-[1.1rem] items-center justify-center rounded-full px-1 text-[0.65rem] font-semibold text-white',
                                    critical > 0 ? 'bg-red-600' : 'bg-amber-600',
                                )}
                            >
                                {total > 99 ? '99+' : total}
                            </span>
                        )}
                    </Button>
                </DropdownMenuTrigger>

                <DropdownMenuContent align="end" className="w-96 max-w-[calc(100vw-2rem)]">
                    <DropdownMenuLabel className="flex items-center justify-between font-semibold">
                        <span>Alerts</span>
                        <span className="text-xs font-normal text-muted-foreground">
                            {total === 0 ? 'Nothing needs attention' : `${critical} critical · ${feed?.counts.warning ?? 0} warning`}
                        </span>
                    </DropdownMenuLabel>
                    <DropdownMenuSeparator />

                    {feed === null ? (
                        <p className="px-2 py-4 text-center text-sm text-muted-foreground">
                            {failed ? 'Cannot load alerts right now.' : 'Loading alerts…'}
                        </p>
                    ) : alerts.length === 0 ? (
                        <p className="px-2 py-4 text-center text-sm text-muted-foreground">No active warnings or critical alerts.</p>
                    ) : (
                        <div className="max-h-80 overflow-y-auto">
                            {alerts.map((alert) => {
                                const meta = SEVERITY[alert.severity];

                                return (
                                    <DropdownMenuItem key={alert.id} asChild>
                                        <Link href="/alerts" className="flex cursor-pointer items-start gap-3 py-2">
                                            <meta.Icon className={cn('mt-0.5 size-4 shrink-0', meta.text)} aria-hidden="true" />
                                            <span className="min-w-0 flex-1">
                                                <span className="block text-sm font-medium break-words">{alert.title}</span>
                                                <span className="block text-xs text-muted-foreground">
                                                    {meta.label} · {alert.device ?? 'Network-wide'} · {ago(alert.triggered_at)}
                                                </span>
                                            </span>
                                        </Link>
                                    </DropdownMenuItem>
                                );
                            })}
                        </div>
                    )}

                    {failed && feed !== null && (
                        <p className="px-2 py-1 text-xs text-amber-600 dark:text-amber-400">Connection lost. Showing the last known alerts.</p>
                    )}

                    <DropdownMenuSeparator />

                    <DropdownMenuItem asChild>
                        <Link href="/alerts" className="cursor-pointer font-medium">
                            View all alerts
                        </Link>
                    </DropdownMenuItem>

                    <DropdownMenuItem
                        onSelect={(event) => {
                            event.preventDefault();
                            toggleSound();
                        }}
                    >
                        {soundOn ? <Volume2 className="mr-2" aria-hidden="true" /> : <VolumeX className="mr-2" aria-hidden="true" />}
                        Alert sound: {soundOn ? 'on' : 'off'}
                    </DropdownMenuItem>

                    <DropdownMenuItem
                        onSelect={(event) => {
                            event.preventDefault();
                            testSound();
                        }}
                    >
                        <Bell className="mr-2" aria-hidden="true" />
                        Test sound
                    </DropdownMenuItem>

                    {blocked && (
                        <p className="px-2 py-1 text-xs text-amber-600 dark:text-amber-400">
                            The browser blocked the sound. Click &ldquo;Test sound&rdquo; once to allow it.
                        </p>
                    )}
                </DropdownMenuContent>
            </DropdownMenu>

            {/* Rendered in <body>: the top bar has a blur effect that would otherwise trap fixed positioning. */}
            {typeof document !== 'undefined' &&
                createPortal(
                    <div aria-label="New alerts" className="pointer-events-none fixed top-4 right-4 z-[100] flex flex-col gap-2">
                        {toasts.map((toast) => (
                            <Toast key={toast.key} item={toast} onClose={dismiss} />
                        ))}
                    </div>,
                    document.body,
                )}
        </>
    );
}