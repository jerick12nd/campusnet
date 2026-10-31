/** Display helpers. Timestamps arrive as ISO 8601 strings and are shown in the campus time zone. */

export function safeTimeZone(timeZone?: string | null): string {
    if (!timeZone) {
        return 'UTC';
    }

    try {
        new Intl.DateTimeFormat('en', { timeZone });

        return timeZone;
    } catch {
        return 'UTC';
    }
}

export function formatMbps(value: number | null | undefined): string {
    if (value === null || value === undefined || Number.isNaN(value)) {
        return '—';
    }

    return Math.abs(value) >= 1000 ? `${(value / 1000).toFixed(2)} Gbps` : `${value.toFixed(2)} Mbps`;
}

export function formatPercent(value: number | null | undefined, digits = 1): string {
    if (value === null || value === undefined || Number.isNaN(value)) {
        return '—';
    }

    return `${value.toFixed(digits)}%`;
}

export function formatDateTime(iso: string | null | undefined, timeZone?: string | null): string {
    if (!iso) {
        return '—';
    }

    const date = new Date(iso);

    if (Number.isNaN(date.getTime())) {
        return '—';
    }

    return new Intl.DateTimeFormat('en-PH', {
        dateStyle: 'medium',
        timeStyle: 'short',
        timeZone: safeTimeZone(timeZone),
    }).format(date);
}

export function formatClock(iso: string | null | undefined, timeZone?: string | null): string {
    if (!iso) {
        return '—';
    }

    const date = new Date(iso);

    if (Number.isNaN(date.getTime())) {
        return '—';
    }

    return new Intl.DateTimeFormat('en-PH', {
        hour: '2-digit',
        minute: '2-digit',
        second: '2-digit',
        hour12: false,
        timeZone: safeTimeZone(timeZone),
    }).format(date);
}

export function timeAgo(iso: string | null | undefined, now: number = Date.now()): string {
    if (!iso) {
        return 'never';
    }

    const then = new Date(iso).getTime();

    if (Number.isNaN(then)) {
        return 'never';
    }

    const seconds = Math.max(0, Math.round((now - then) / 1000));

    if (seconds < 45) {
        return 'just now';
    }

    if (seconds < 3600) {
        return `${Math.round(seconds / 60)} min ago`;
    }

    if (seconds < 86400) {
        return `${Math.round(seconds / 3600)} h ago`;
    }

    return `${Math.round(seconds / 86400)} d ago`;
}

/** Tick label for the chart time axis: clock time for short ranges, date for long ones. */
export function formatAxisTime(timestamp: number, range: string, timeZone?: string | null): string {
    const options: Intl.DateTimeFormatOptions =
        range === '7d' || range === '30d'
            ? { month: 'short', day: 'numeric' }
            : { hour: '2-digit', minute: '2-digit', hour12: false };

    return new Intl.DateTimeFormat('en-PH', { ...options, timeZone: safeTimeZone(timeZone) }).format(new Date(timestamp));
}

export function rangeLabel(range: string): string {
    return (
        { '1h': 'Last hour', '24h': 'Last 24 hours', '7d': 'Last 7 days', '30d': 'Last 30 days' }[range] ?? range
    );
}