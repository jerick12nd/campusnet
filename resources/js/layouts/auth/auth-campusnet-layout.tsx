import { Link } from '@inertiajs/react';
import type { PropsWithChildren } from 'react';

export default function AuthCampusnetLayout({
    children,
    title,
    description,
}: PropsWithChildren<{
    title?: string;
    description?: string;
}>) {
    return (
        <div className="grid min-h-svh bg-[#0a0a0a] text-white lg:grid-cols-2">
            {/* LEFT: design / branding panel (hidden on small screens) */}
            <div className="relative hidden flex-col items-center justify-center overflow-hidden bg-[#18181b] bg-[radial-gradient(ellipse_at_top,rgba(16,94,72,0.45),transparent_60%)] p-10 text-center lg:flex">
                <img
                    src="/images/dssc-seal.png"
                    alt="Davao del Sur State College seal"
                    className="size-56 object-contain drop-shadow-[0_0_2rem_rgba(0,0,0,0.5)]"
                />
                <h2 className="mt-8 text-4xl font-bold tracking-tight">DSSC CAMPUSNET</h2>
                <p className="mt-2 text-lg text-white/80">Davao del Sur State College</p>
                <p className="mt-6 max-w-xs text-base text-white/60">
                    Campus bandwidth monitoring
                    <br />
                    and network usage analytics.
                </p>
            </div>

            {/* RIGHT: login form */}
            <div className="relative flex flex-col p-6 md:p-10">
                <Link href="/" className="flex items-center gap-2 text-sm font-medium">
                    <img src="/images/dssc-seal.png" alt="" className="size-8 shrink-0 object-contain" />
                    <span>DSSC CAMPUSNET</span>
                </Link>

                <div className="flex flex-1 items-center justify-center">
                    <div className="w-full max-w-sm space-y-6">
                        {(title || description) && (
                            <div className="space-y-2 text-center">
                                {title && <h1 className="text-2xl font-semibold">{title}</h1>}
                                {description && <p className="text-sm text-balance text-white/60">{description}</p>}
                            </div>
                        )}
                        {children}
                    </div>
                </div>
            </div>
        </div>
    );
}