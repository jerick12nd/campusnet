import { CircleCheck, CircleHelp, CircleX, FlaskConical } from 'lucide-react';
import type { ReactNode } from 'react';

import { cn } from '@/lib/utils';
import type { DeviceStatusValue } from '@/types/campusnet';

type Tone = 'green' | 'red' | 'gray' | 'amber' | 'blue';

const TONES: Record<Tone, string> = {
    green: 'bg-emerald-100 text-emerald-900 ring-emerald-700/25 dark:bg-emerald-950 dark:text-emerald-200 dark:ring-emerald-400/30',
    red: 'bg-red-100 text-red-900 ring-red-700/25 dark:bg-red-950 dark:text-red-200 dark:ring-red-400/30',
    gray: 'bg-zinc-100 text-zinc-800 ring-zinc-500/25 dark:bg-zinc-800 dark:text-zinc-200 dark:ring-zinc-400/30',
    amber: 'bg-amber-100 text-amber-950 ring-amber-700/30 dark:bg-amber-950 dark:text-amber-200 dark:ring-amber-400/30',
    blue: 'bg-blue-100 text-blue-900 ring-blue-700/25 dark:bg-blue-950 dark:text-blue-200 dark:ring-blue-400/30',
};

export function Pill({ tone, children, className }: { tone: Tone; children: ReactNode; className?: string }) {
    return (
        <span
            className={cn(
                'inline-flex items-center gap-1 rounded-full px-2 py-0.5 text-xs font-medium ring-1 ring-inset',
                TONES[tone],
                className,
            )}
        >
            {children}
        </span>
    );
}

/** Status is always shown with an icon and text, never by colour alone. */
export function DeviceStatusBadge({ status }: { status: { value: DeviceStatusValue; label: string } }) {
    if (status.value === 'online') {
        return (
            <Pill tone="green">
                <CircleCheck className="size-3" aria-hidden="true" />
                {status.label}
            </Pill>
        );
    }

    if (status.value === 'unreachable') {
        return (
            <Pill tone="red">
                <CircleX className="size-3" aria-hidden="true" />
                {status.label}
            </Pill>
        );
    }

    return (
        <Pill tone="gray">
            <CircleHelp className="size-3" aria-hidden="true" />
            {status.label}
        </Pill>
    );
}

export function EnabledBadge({ enabled }: { enabled: boolean }) {
    return <Pill tone={enabled ? 'blue' : 'gray'}>{enabled ? 'Monitoring on' : 'Disabled'}</Pill>;
}

export function DemoBadge() {
    return (
        <Pill tone="amber">
            <FlaskConical className="size-3" aria-hidden="true" />
            Demo
        </Pill>
    );
}

export function SeverityBadge({ severity }: { severity: 'info' | 'warning' | 'critical' }) {
    const tone: Tone = severity === 'critical' ? 'red' : severity === 'warning' ? 'amber' : 'blue';

    return <Pill tone={tone}>{severity.charAt(0).toUpperCase() + severity.slice(1)}</Pill>;
}