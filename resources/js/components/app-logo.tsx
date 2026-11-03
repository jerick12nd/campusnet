import { usePage } from '@inertiajs/react';

import AppLogoIcon from '@/components/app-logo-icon';

export default function AppLogo() {
    return (
        <>
            <img src="/images/dssc-seal.png" alt="" width={32} height={32} className="size-8 shrink-0 object-contain" />
            <div className="ml-1 grid flex-1 text-left text-sm">
                <span className="truncate leading-tight font-semibold tracking-wide">DSSC CAMPUSNET</span>
            </div>
        </>
    );
}