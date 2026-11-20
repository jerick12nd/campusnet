import { Link } from '@inertiajs/react';

import { cn } from '@/lib/utils';

const TABS = [
    { key: 'connected', label: 'Connected', href: '/connected-members' },
    { key: 'members', label: 'Members', href: '/members' },
    { key: 'devices', label: 'Registered devices', href: '/registered-devices' },
] as const;

export type OfficeNetworkTab = (typeof TABS)[number]['key'];

export function OfficeNetworkTabs({ current }: { current: OfficeNetworkTab }) {
    return (
        <nav aria-label="Connected members sections" className="flex gap-1 border-b">
            {TABS.map((tab) => (
                <Link
                    key={tab.key}
                    href={tab.href}
                    aria-current={tab.key === current ? 'page' : undefined}
                    className={cn(
                        '-mb-px border-b-2 px-3 py-2 text-sm',
                        tab.key === current ? 'border-primary font-medium' : 'border-transparent text-muted-foreground hover:text-foreground',
                    )}
                >
                    {tab.label}
                </Link>
            ))}
        </nav>
    );
}