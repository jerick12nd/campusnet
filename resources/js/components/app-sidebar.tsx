import { Link, usePage } from '@inertiajs/react';
import {
    Activity,
    Bell,
    Building2,
    FileText,
    Globe,
    History,
    LayoutDashboard,
    Network,
    Radar,
    Router,
    ScrollText,
    UserCheck,
    Users,
    type LucideIcon,
} from 'lucide-react';

import {
    Sidebar,
    SidebarContent,
    SidebarGroup,
    SidebarGroupLabel,
    SidebarHeader,
    SidebarMenu,
    SidebarMenuBadge,
    SidebarMenuButton,
    SidebarMenuItem,
} from '@/components/ui/sidebar';

type CampusNetShared = {
    access?: {
        role: string | null;
        role_label: string | null;
        permissions: string[];
    };
    campusnet?: { campus_name: string };
    /** Optional: the red badge on Alerts only shows once the server shares this. */
    sidebar?: { active_alerts?: number };
};

type NavEntry = {
    title: string;
    icon: LucideIcon;
    /** Permission the backend grants to the roles that may see this item. */
    permission: string;
    /** null = the page is not built yet (shown disabled, never a dead link). */
    href: string | null;
    /** Other paths that belong to this item, so it stays highlighted on them. */
    also?: string[];
    /** Red count badge, fed by the server (see HandleInertiaRequests). */
    badge?: 'active_alerts';
};

type NavGroup = {
    label: string;
    items: NavEntry[];
};

// Visibility is filtered by the permissions the server shares with the page; the server
// still authorizes every request, so this list is a convenience, not security.
// System Settings lives in the account menu (top right), not here.
export const NAV_GROUPS: NavGroup[] = [
    {
        label: 'Monitoring',
        items: [
            { title: 'Dashboard', icon: LayoutDashboard, permission: 'dashboard.view', href: '/dashboard' },
            { title: 'Network Devices', icon: Router, permission: 'devices.view', href: '/devices' },
            { title: 'Online Devices', icon: Radar, permission: 'devices.view', href: '/network' },
            { title: 'Interfaces', icon: Network, permission: 'interfaces.view', href: '/interfaces' },
            { title: 'Bandwidth Analytics', icon: Activity, permission: 'traffic.view', href: '/bandwidth-analytics' },
            { title: 'Traffic History', icon: History, permission: 'traffic.view', href: '/traffic-history' },
            { title: 'Alerts', icon: Bell, permission: 'alerts.view', href: '/alerts', badge: 'active_alerts' },
            { title: 'Reports', icon: FileText, permission: 'reports.view', href: '/reports' },
            { title: 'User Management', icon: Users, permission: 'users.manage', href: '/users' },
            { title: 'Audit Logs', icon: ScrollText, permission: 'audit.view', href: '/audit-logs' },
        ],
    },
    {
        label: 'Office Network Activity',
        items: [
            { title: 'Office Connections', icon: Building2, permission: 'office-connections.view', href: '/office-connections' },
            {
                title: 'Connected Members',
                icon: UserCheck,
                permission: 'members.view',
                href: '/connected-members',
                also: ['/members', '/registered-devices'],
            },
            {
                title: 'Website Activity',
                icon: Globe,
                permission: 'website-activity.view',
                href: '/website-activity',
                also: ['/activity-sources'],
            },
        ],
    },
];

export function isActive(currentPath: string, item: NavEntry): boolean {
    const paths = [item.href, ...(item.also ?? [])].filter((path): path is string => path !== null);

    return paths.some((path) => currentPath === path || currentPath.startsWith(`${path}/`));
}

export function AppSidebar() {
    const page = usePage<CampusNetShared>();
    const permissions = page.props.access?.permissions ?? [];
    const campusName = page.props.campusnet?.campus_name;
    const activeAlerts = page.props.sidebar?.active_alerts ?? 0;
    const currentPath = page.url.split('?')[0];

    // Read-only viewers use the top navigation bar instead (see app-topnav.tsx).
    if (page.props.access?.role === 'viewer') {
        return null;
    }

    const groups = NAV_GROUPS.map((group) => ({
        ...group,
        items: group.items.filter((item) => permissions.includes(item.permission)),
    })).filter((group) => group.items.length > 0);

    return (
        <Sidebar collapsible="icon" variant="inset">
            <SidebarHeader>
                <SidebarMenu>
                    <SidebarMenuItem>
                        <SidebarMenuButton size="lg" asChild>
                            <Link href="/dashboard" prefetch>
                                <img src="/images/dssc-seal.png" alt="" width={32} height={32} className="size-8 shrink-0 object-contain" />
                                <div className="grid flex-1 text-left text-sm leading-tight">
                                    <span className="truncate font-semibold tracking-wide">DSSC CAMPUSNET</span>
                                    <span className="truncate text-xs text-muted-foreground">
                                        {campusName ?? 'Bandwidth monitoring'}
                                    </span>
                                </div>
                            </Link>
                        </SidebarMenuButton>
                    </SidebarMenuItem>
                </SidebarMenu>
            </SidebarHeader>

            <SidebarContent>
                {groups.map((group, index) => (
                    <SidebarGroup key={group.label} className={index === 0 ? 'px-2 py-0' : 'px-2 pt-2 pb-0'}>
                        <SidebarGroupLabel>{group.label}</SidebarGroupLabel>
                        <SidebarMenu>
                            {group.items.map((item) => {
                                const Icon = item.icon;

                                if (item.href === null) {
                                    return (
                                        <SidebarMenuItem key={item.title}>
                                            <SidebarMenuButton
                                                disabled
                                                tooltip={`${item.title} (coming soon)`}
                                                className="cursor-not-allowed opacity-60"
                                            >
                                                <Icon aria-hidden="true" />
                                                <span>{item.title}</span>
                                            </SidebarMenuButton>
                                            <SidebarMenuBadge>Soon</SidebarMenuBadge>
                                        </SidebarMenuItem>
                                    );
                                }

                                const count = item.badge === 'active_alerts' ? activeAlerts : 0;

                                return (
                                    <SidebarMenuItem key={item.title}>
                                        <SidebarMenuButton asChild isActive={isActive(currentPath, item)} tooltip={item.title}>
                                            <Link href={item.href} prefetch>
                                                <Icon aria-hidden="true" />
                                                <span>{item.title}</span>
                                            </Link>
                                        </SidebarMenuButton>

                                        {count > 0 && (
                                            <SidebarMenuBadge className="rounded-full bg-red-500 px-1.5 text-white! peer-hover/menu-button:text-white! peer-data-[active=true]/menu-button:text-white!">
                                                {count > 99 ? '99+' : count}
                                            </SidebarMenuBadge>
                                        )}
                                    </SidebarMenuItem>
                                );
                            })}
                        </SidebarMenu>
                    </SidebarGroup>
                ))}
            </SidebarContent>
        </Sidebar>
    );
}