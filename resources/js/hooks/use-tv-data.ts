import { useEffect, useRef, useState } from 'react';

import type { TvData } from '@/types/tv-display';

export type Connection = 'live' | 'retrying' | 'unauthorized';

const REQUEST_TIMEOUT_MS = 10_000;

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
            if (cancelled || document.hidden) {
                return; // resumed by the visibilitychange handler below
            }

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
                setData(next);
                setLastOkAt(lastOkRef.current);
                setSkewMs(Date.parse(next.generated_at) - Date.now() || 0);
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
                clearTimeout(abortTimer);
            }
        };

        const onVisibility = () => {
            if (!document.hidden) {
                clearTimeout(timer);
                void run();
            }
        };

        document.addEventListener('visibilitychange', onVisibility);

        // Wait out whatever is left of the interval since the last good update (first paint already has fresh data).
        const sinceOk = lastOkRef.current === null ? Infinity : Date.now() - lastOkRef.current;
        schedule(Math.max(0, interval - sinceOk));

        return () => {
            cancelled = true;
            clearTimeout(timer);
            controller?.abort();
            document.removeEventListener('visibilitychange', onVisibility);
        };
    }, [intervalSeconds, paused]);

    return { data, lastOkAt, connection, skewMs };
}

export function useNow(intervalMs = 1000): number {
    const [now, setNow] = useState(() => Date.now());

    useEffect(() => {
        const id = setInterval(() => setNow(Date.now()), intervalMs);

        return () => clearInterval(id);
    }, [intervalMs]);

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