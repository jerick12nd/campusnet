import { Link, usePage } from '@inertiajs/react';
import { ChevronDown, Menu } from 'lucide-react';

import { isActive, NAV_GROUPS } from '@/components/app-sidebar';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuLabel,
    DropdownMenuSeparator,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';

type TopNavShared = {
    access?: { permissions: string[] };
    sidebar?: { active_alerts?: number };
};

// Shorter names so the whole menu fits in one row.
const SHORT_TITLES: Record<string, string> = {
    'Network Devices': 'Devices',
    'Online Devices': 'Online',
    'Bandwidth Analytics': 'Bandwidth',
    'Traffic History': 'History',
};

// Groups after the first one become a single dropdown pill.
const GROUP_TITLES: Record<string, string> = {
    'Office Network Activity': 'Office',
};

const PILL =
    'inline-flex h-9 items-center gap-1.5 rounded-full px-3.5 text-sm font-medium whitespace-nowrap transition-colors outline-none focus-visible:ring-2 focus-visible:ring-ring';
const PILL_IDLE = 'text-muted-foreground hover:bg-accent hover:text-accent-foreground';
const PILL_ACTIVE = 'bg-primary text-primary-foreground';

/** Logo + menu for read-only viewers. Wide screens: pills. Narrow screens: one Menu button. */
export function TopNav() {
    const page = usePage<TopNavShared>();
    const permissions = page.props.access?.permissions ?? [];
    const activeAlerts = page.props.sidebar?.active_alerts ?? 0;
    const currentPath = page.url.split('?')[0];

    const groups = NAV_GROUPS.map((group) => ({
        ...group,
        // Pages that are not built yet (href null) have no place in the top bar.
        items: group.items.filter((item) => item.href !== null && permissions.includes(item.permission)),
    })).filter((group) => group.items.length > 0);

    const [main, ...more] = groups;

    return (
        <div className="flex min-w-0 flex-1 items-center gap-3">
            <Link
                href="/dashboard"
                prefetch
                className="flex shrink-0 items-center gap-2 rounded-full border bg-card py-1 pr-4 pl-1.5 shadow-xs"
            >
                <img src="/images/dssc-seal.png" alt="" width={28} height={28} className="size-7 object-contain" />
                <span className="hidden text-sm font-semibold tracking-wide sm:block">DSSC CAMPUSNET</span>
            </Link>

            {/* Wide screens: pill menu. */}
            <nav aria-label="Main" className="hidden items-center gap-1 rounded-full border bg-card p-1 shadow-xs xl:flex">
                {main?.items.map((item) => {
                    const active = isActive(currentPath, item);
                    const count = item.badge === 'active_alerts' ? activeAlerts : 0;

                    return (
                        <Link
                            key={item.title}
                            href={item.href as string}
                            prefetch
                            aria-current={active ? 'page' : undefined}
                            className={`${PILL} ${active ? PILL_ACTIVE : PILL_IDLE}`}
                        >
                            {SHORT_TITLES[item.title] ?? item.title}
                            {count > 0 && (
                                <span className="rounded-full bg-red-500 px-1.5 text-[10px] leading-4 font-semibold text-white">
                                    {count > 99 ? '99+' : count}
                                </span>
                            )}
                        </Link>
                    );
                })}

                {more.map((group) => {
                    const active = group.items.some((item) => isActive(currentPath, item));

                    return (
                        <DropdownMenu key={group.label}>
                            <DropdownMenuTrigger asChild>
                                <button type="button" className={`${PILL} ${active ? PILL_ACTIVE : PILL_IDLE}`}>
                                    {GROUP_TITLES[group.label] ?? group.label}
                                    <ChevronDown className="size-3.5" aria-hidden="true" />
                                </button>
                            </DropdownMenuTrigger>
                            <DropdownMenuContent align="start" className="min-w-52">
                                {group.items.map((item) => {
                                    const Icon = item.icon;

                                    return (
                                        <DropdownMenuItem key={item.title} asChild>
                                            <Link
                                                href={item.href as string}
                                                prefetch
                                                aria-current={isActive(currentPath, item) ? 'page' : undefined}
                                                className={isActive(currentPath, item) ? 'font-semibold' : ''}
                                            >
                                                <Icon aria-hidden="true" />
                                                {item.title}
                                            </Link>
                                        </DropdownMenuItem>
                                    );
                                })}
                            </DropdownMenuContent>
                        </DropdownMenu>
                    );
                })}
            </nav>

            {/* Narrow screens: one button that opens the whole menu. */}
            <DropdownMenu>
                <DropdownMenuTrigger asChild>
                    <button type="button" className={`${PILL} border bg-card shadow-xs xl:hidden`}>
                        <Menu className="size-4" aria-hidden="true" />
                        Menu
                    </button>
                </DropdownMenuTrigger>
                <DropdownMenuContent align="start" className="min-w-56">
                    {groups.map((group, index) => (
                        <div key={group.label}>
                            {index > 0 && <DropdownMenuSeparator />}
                            <DropdownMenuLabel className="text-xs text-muted-foreground">{group.label}</DropdownMenuLabel>
                            {group.items.map((item) => {
                                const Icon = item.icon;

                                return (
                                    <DropdownMenuItem key={item.title} asChild>
                                        <Link
                                            href={item.href as string}
                                            prefetch
                                            aria-current={isActive(currentPath, item) ? 'page' : undefined}
                                            className={isActive(currentPath, item) ? 'font-semibold' : ''}
                                        >
                                            <Icon aria-hidden="true" />
                                            {item.title}
                                        </Link>
                                    </DropdownMenuItem>
                                );
                            })}
                        </div>
                    ))}
                </DropdownMenuContent>
            </DropdownMenu>
        </div>
    );
}