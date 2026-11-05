import { useEffect, useRef, useState } from 'react';

import type { TvData } from '@/types/tv-display';

export type Connection = 'live' | 'retrying' | 'unauthorized';

const REQUEST_TIMEOUT_MS = 10_000;

/** The TV's clock and the server's never match exactly. A smaller change is noise, not news. */
const SKEW_TOLERANCE_MS = 1_500;

function isRecord(value: unknown): value is Record<string, unknown> {
    return typeof value === 'object' && value !== null && !Array.isArray(value);
}

/**
 * Returns `next`, but keeps the OLD object for every part that did not change.
 *
 * Every response is a brand new object, even when only `generated_at` moved. With this, the
 * history, the device list and so on keep the same identity, so React can skip the panels that
 * show them (the React Compiler does that by comparing props) instead of redrawing all of them
 * every few seconds on a weak TV box.
 */
function share<T>(previous: unknown, next: T): T {
    if (previous === next) {
        return next;
    }

    if (Array.isArray(previous) && Array.isArray(next)) {
        const merged = next.map((item, index) => share(previous[index], item));
        const same = merged.length === previous.length && merged.every((item, index) => item === previous[index]);

        return (same ? previous : merged) as T;
    }

    if (isRecord(previous) && isRecord(next)) {
        const keys = Object.keys(next);
        const merged: Record<string, unknown> = {};
        let same = keys.length === Object.keys(previous).length;

        for (const key of keys) {
            merged[key] = share(previous[key], next[key]);
            same &&= merged[key] === previous[key];
        }

        return (same ? previous : merged) as T;
    }

    return next;
}

/**
 * Polls the TV data endpoint with ONE timer chain (never setInterval), so requests cannot pile up.
 * A failed cycle keeps the previous data untouched: nothing is invented to fill the gap.
 */
export function useTvData(initial: TvData | null, intervalSeconds: number, paused: boolean) {
    const [data, setData] = useState<TvData | null>(initial);
    const [lastOkAt, setLastOkAt] = useState<number | null>(initial ? Date.now() : null);
    const [connection, setConnection] = useState<Connection>('live');
    const [skewMs, setSkewMs] = useState(0); // server time minus this device's clock

    const lastOkRef = useRef<number | null>(initial ? Date.now() : null);
    const failures = useRef(0);

    useEffect(() => {
        if (paused) {
            return;
        }

        let cancelled = false;
        let inFlight = false;
        let timer: ReturnType<typeof setTimeout> | undefined;
        let controller: AbortController | undefined;
        const interval = intervalSeconds * 1000;

        const schedule = (ms: number) => {
            clearTimeout(timer);

            if (!cancelled) {
                timer = setTimeout(() => void run(), ms);
            }
        };

        const run = async () => {
            // Never start a second request while one is running: a wake-up (tab visible again,
            // network back) during a request would otherwise start a second timer chain for good.
            if (cancelled || document.hidden || inFlight) {
                return; // a hidden page is resumed by the wake-up handler below
            }

            inFlight = true;
            controller = new AbortController();
            const abortTimer = setTimeout(() => controller?.abort(), REQUEST_TIMEOUT_MS);

            try {
                const response = await fetch('/tv-display/data', {
                    headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                    credentials: 'same-origin',
                    cache: 'no-store',
                    signal: controller.signal,
                });

                if (cancelled) {
                    return;
                }

                if (response.status === 401 || response.status === 419) {
                    failures.current += 1;
                    setConnection('unauthorized');
                    schedule(15_000);

                    return;
                }

                if (!response.ok) {
                    throw new Error(`HTTP ${response.status}`);
                }

                const next = (await response.json()) as TvData;

                if (cancelled) {
                    return;
                }

                failures.current = 0;
                lastOkRef.current = Date.now();
                setData((current) => (current === null ? next : share(current, next)));
                setLastOkAt(lastOkRef.current);

                const skew = Date.parse(next.generated_at) - Date.now();

                // Only a real clock difference changes the state, so network jitter does not redraw the clock panels.
                setSkewMs((current) => (Number.isNaN(skew) || Math.abs(skew - current) < SKEW_TOLERANCE_MS ? current : skew));
                setConnection('live');
                schedule(interval);
            } catch {
                if (cancelled) {
                    return;
                }

                failures.current += 1;
                setConnection('retrying');
                schedule(Math.min(30_000, interval * 2 ** Math.min(failures.current, 4)));
            } finally {
                inFlight = false;
                clearTimeout(abortTimer);
            }
        };

        // The tab came back, or the network did: ask now instead of waiting out the timer.
        const wakeUp = () => {
            if (!document.hidden) {
                clearTimeout(timer);
                void run();
            }
        };

        document.addEventListener('visibilitychange', wakeUp);
        window.addEventListener('online', wakeUp);

        // Wait out whatever is left of the interval since the last good update (first paint already has fresh data).
        const sinceOk = lastOkRef.current === null ? Infinity : Date.now() - lastOkRef.current;
        schedule(Math.max(0, interval - sinceOk));

        return () => {
            cancelled = true;
            clearTimeout(timer);
            controller?.abort();
            document.removeEventListener('visibilitychange', wakeUp);
            window.removeEventListener('online', wakeUp);
        };
    }, [intervalSeconds, paused]);

    return { data, lastOkAt, connection, skewMs };
}

// One shared timer per interval. Every clock and "x s ago" label that asks for the same interval is
// updated in the same tick, so React draws them in ONE render instead of one render per component.
const tickers = new Map<number, { listeners: Set<() => void>; id: ReturnType<typeof setInterval> }>();

function subscribeTick(intervalMs: number, listener: () => void): () => void {
    let ticker = tickers.get(intervalMs);

    if (!ticker) {
        const listeners = new Set<() => void>();

        ticker = { listeners, id: setInterval(() => listeners.forEach((notify) => notify()), intervalMs) };
        tickers.set(intervalMs, ticker);
    }

    ticker.listeners.add(listener);

    return () => {
        ticker.listeners.delete(listener);

        if (ticker.listeners.size === 0) {
            clearInterval(ticker.id);
            tickers.delete(intervalMs);
        }
    };
}

export function useNow(intervalMs = 1000): number {
    const [now, setNow] = useState(() => Date.now());

    useEffect(() => subscribeTick(intervalMs, () => setNow(Date.now())), [intervalMs]);

    return now;
}

/** True when no successful update has arrived for far longer than the refresh interval. Re-renders only when it flips. */
export function useConnectionStale(lastOkAt: number | null, intervalSeconds: number, paused: boolean): boolean {
    const [stale, setStale] = useState(false);

    useEffect(() => {
        const limit = Math.max(3 * intervalSeconds * 1000, 20_000);
        const check = () => setStale(!paused && lastOkAt !== null && Date.now() - lastOkAt > limit);

        check();
        const id = setInterval(check, 1000);

        return () => clearInterval(id);
    }, [lastOkAt, intervalSeconds, paused]);

    return stale;
}

/** Cycles through pages of a long list, because a wall display has nobody to scroll it. */
export function useRotatingPage(total: number, pageSize: number, everyMs = 10_000) {
    const pages = Math.max(1, Math.ceil(total / pageSize));
    const [page, setPage] = useState(0);

    useEffect(() => {
        if (pages <= 1) {
            setPage(0);

            return;
        }

        const id = setInterval(() => setPage((current) => (current + 1) % pages), everyMs);

        return () => clearInterval(id);
    }, [pages, everyMs]);

    return { page: Math.min(page, pages - 1), pages };
}