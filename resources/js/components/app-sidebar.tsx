import { Link, usePage } from '@inertiajs/react';
import {
    Activity,
    Bell,
    FileText,
    History,
    LayoutDashboard,
    Network,
    Router,
    ScrollText,
    Settings,
    Users,
    type LucideIcon,
} from 'lucide-react';

import { NavUser } from '@/components/nav-user';
import {
    Sidebar,
    SidebarContent,
    SidebarFooter,
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
};

type NavEntry = {
    title: string;
    icon: LucideIcon;
    /** Permission the backend grants to the roles that may see this item. */
    permission: string;
    /** null = the page is not built yet (shown disabled, never a dead link). */
    href: string | null;
    /** Implementation phase in which the page arrives. */
    phase?: number;
};

// Order matches the CampusNet specification. Visibility is filtered by the
// permissions the server shares with the page; the server still authorizes
// every request, so this list is a convenience, not security.
const NAVIGATION: NavEntry[] = [
    { title: 'Dashboard', icon: LayoutDashboard, permission: 'dashboard.view', href: '/dashboard' },
    { title: 'Network Devices', icon: Router, permission: 'devices.view', href: '/devices' },
    { title: 'Interfaces', icon: Network, permission: 'interfaces.view', href: '/interfaces' },
    { title: 'Bandwidth Analytics', icon: Activity, permission: 'traffic.view', href: '/bandwidth-analytics' },
    { title: 'Traffic History', icon: History, permission: 'traffic.view', href: '/traffic-history' },
    { title: 'Alerts', icon: Bell, permission: 'alerts.view', href: '/alerts' },
    { title: 'Reports', icon: FileText, permission: 'reports.view', href: '/reports' },
    { title: 'User Management', icon: Users, permission: 'users.manage', href: null, phase: 6 },
    { title: 'Audit Logs', icon: ScrollText, permission: 'audit.view', href: null, phase: 6 },
    { title: 'System Settings', icon: Settings, permission: 'settings.view', href: null, phase: 6 },
];

export function AppSidebar() {
    const page = usePage<CampusNetShared>();
    const permissions = page.props.access?.permissions ?? [];
    const campusName = page.props.campusnet?.campus_name;
    const currentPath = page.url.split('?')[0];

    const visible = NAVIGATION.filter((item) => permissions.includes(item.permission));

    return (
        <Sidebar collapsible="icon" variant="inset">
            <SidebarHeader>
                <SidebarMenu>
                    <SidebarMenuItem>
                        <SidebarMenuButton size="lg" asChild>
                            <Link href="/dashboard" prefetch>
                                <div className="flex aspect-square size-8 items-center justify-center rounded-md bg-sidebar-primary text-sidebar-primary-foreground">
                                    <Network className="size-4" aria-hidden="true" />
                                </div>
                                <div className="grid flex-1 text-left text-sm leading-tight">
                                    <span className="truncate font-semibold">CampusNet</span>
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
                <SidebarGroup className="px-2 py-0">
                    <SidebarGroupLabel>Monitoring</SidebarGroupLabel>
                    <SidebarMenu>
                        {visible.map((item) => {
                            const Icon = item.icon;

                            if (item.href === null) {
                                return (
                                    <SidebarMenuItem key={item.title}>
                                        <SidebarMenuButton
                                            disabled
                                            tooltip={`${item.title} (arrives in phase ${item.phase})`}
                                            className="cursor-not-allowed opacity-60"
                                        >
                                            <Icon aria-hidden="true" />
                                            <span>{item.title}</span>
                                        </SidebarMenuButton>
                                        <SidebarMenuBadge>Soon</SidebarMenuBadge>
                                    </SidebarMenuItem>
                                );
                            }

                            return (
                                <SidebarMenuItem key={item.title}>
                                    <SidebarMenuButton
                                        asChild
                                        isActive={currentPath === item.href || currentPath.startsWith(`${item.href}/`)}
                                        tooltip={item.title}
                                    >
                                        <Link href={item.href} prefetch>
                                            <Icon aria-hidden="true" />
                                            <span>{item.title}</span>
                                        </Link>
                                    </SidebarMenuButton>
                                </SidebarMenuItem>
                            );
                        })}
                    </SidebarMenu>
                </SidebarGroup>
            </SidebarContent>

            <SidebarFooter>
                <NavUser />
            </SidebarFooter>
        </Sidebar>
    );
}