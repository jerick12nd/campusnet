import { usePage } from '@inertiajs/react';
import { ChevronDown } from 'lucide-react';

import { Breadcrumbs } from '@/components/breadcrumbs';
import { Avatar, AvatarFallback, AvatarImage } from '@/components/ui/avatar';
import { Button } from '@/components/ui/button';
import { DropdownMenu, DropdownMenuContent, DropdownMenuTrigger } from '@/components/ui/dropdown-menu';
import { SidebarTrigger } from '@/components/ui/sidebar';
import { UserMenuContent } from '@/components/user-menu-content';
import { useInitials } from '@/hooks/use-initials';
import type { BreadcrumbItem as BreadcrumbItemType } from '@/types';

function TopbarUser() {
    const props = usePage().props;
    const user = props.auth.user;
    const roleLabel = (props as { access?: { role_label: string | null } }).access?.role_label;
    const getInitials = useInitials();

    if (!user) {
        return null;
    }

    const showAvatar = Boolean(user.avatar && user.avatar !== '');

    return (
        <DropdownMenu>
            <DropdownMenuTrigger asChild>
                <Button
                    variant="ghost"
                    className="ml-auto h-auto gap-2 px-2 py-1.5"
                    aria-label={`Account menu for ${user.name}`}
                    data-test="sidebar-menu-button"
                >
                    <Avatar className="size-8 overflow-hidden rounded-lg">
                        {showAvatar ? <AvatarImage src={user.avatar} alt="" /> : null}
                        <AvatarFallback className="rounded-lg text-black dark:text-white">{getInitials(user.name)}</AvatarFallback>
                    </Avatar>
                    <span className="hidden text-left text-sm leading-tight sm:grid">
                        <span className="max-w-40 truncate font-medium">{user.name}</span>
                        {roleLabel ? <span className="max-w-40 truncate text-xs text-muted-foreground">{roleLabel}</span> : null}
                    </span>
                    <ChevronDown className="size-4 text-muted-foreground" aria-hidden="true" />
                </Button>
            </DropdownMenuTrigger>
            <DropdownMenuContent className="min-w-56 rounded-lg" align="end" side="bottom">
                <UserMenuContent user={user} />
            </DropdownMenuContent>
        </DropdownMenu>
    );
}

export function AppSidebarHeader({
    breadcrumbs = [],
}: {
    breadcrumbs?: BreadcrumbItemType[];
}) {
    return (
        <header className="flex h-16 shrink-0 items-center gap-2 border-b border-sidebar-border/50 px-6 transition-[width,height] ease-linear group-has-data-[collapsible=icon]/sidebar-wrapper:h-12 md:px-4">
            <div className="flex items-center gap-2">
                <SidebarTrigger className="-ml-1" />
                <Breadcrumbs breadcrumbs={breadcrumbs} />
            </div>

            <TopbarUser />
        </header>
    );
}