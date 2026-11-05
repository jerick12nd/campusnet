import { Link, router, usePage } from '@inertiajs/react';
import { LogOut, Settings, UserCog } from 'lucide-react';
import {
    DropdownMenuGroup,
    DropdownMenuItem,
    DropdownMenuLabel,
    DropdownMenuSeparator,
} from '@/components/ui/dropdown-menu';
import { NotificationSoundMenu } from '@/components/notification-sound-menu';
import { UserInfo } from '@/components/user-info';
import { useMobileNavigation } from '@/hooks/use-mobile-navigation';
import { logout } from '@/routes';
import { edit } from '@/routes/profile';
import type { User } from '@/types';

type Props = {
    user: User;
};

export function UserMenuContent({ user }: Props) {
    const cleanup = useMobileNavigation();
    const { access } = usePage<{ access?: { permissions: string[] } }>().props;
    const permissions = access?.permissions ?? [];

    // The server still authorizes every page; these only decide whether the menu item is shown.
    const canViewSystemSettings = permissions.includes('settings.view');
    const canHearAlerts = permissions.includes('alerts.view');

    const handleLogout = () => {
        cleanup();
        router.flushAll();
    };

    return (
        <>
            <DropdownMenuLabel className="p-0 font-normal">
                <div className="flex items-center gap-2 px-1 py-1.5 text-left text-sm">
                    <UserInfo user={user} showEmail={true} />
                </div>
            </DropdownMenuLabel>
            <DropdownMenuSeparator />
            <DropdownMenuGroup>
                <DropdownMenuItem asChild>
                    <Link
                        className="block w-full cursor-pointer"
                        href={edit()}
                        prefetch
                        onClick={cleanup}
                    >
                        <UserCog className="mr-2" />
                        Account settings
                    </Link>
                </DropdownMenuItem>

                {canViewSystemSettings && (
                    <DropdownMenuItem asChild>
                        <Link
                            className="block w-full cursor-pointer"
                            href="/system-settings"
                            prefetch
                            onClick={cleanup}
                            data-test="system-settings-link"
                        >
                            <Settings className="mr-2" />
                            System Settings
                        </Link>
                    </DropdownMenuItem>
                )}

                {canHearAlerts && <NotificationSoundMenu />}
            </DropdownMenuGroup>
            <DropdownMenuSeparator />
            <DropdownMenuItem asChild>
                <Link
                    className="block w-full cursor-pointer"
                    href={logout()}
                    as="button"
                    onClick={handleLogout}
                    data-test="logout-button"
                >
                    <LogOut className="mr-2" />
                    Log out
                </Link>
            </DropdownMenuItem>
        </>
    );
}