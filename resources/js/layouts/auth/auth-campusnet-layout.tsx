import { Link } from '@inertiajs/react';
import type { CSSProperties, PropsWithChildren } from 'react';

/**
 * Itom nga base, light blue (DSSC) ra ang accent.
 * Ang mga variable gi-override diri lang (login page), dili apektado ang ubang bahin sa app.
 */
const loginTheme = {
    '--background': '#0a0a0a',
    '--foreground': '#fafafa',
    '--card': '#111113',
    '--card-foreground': '#fafafa',
    '--popover': '#111113',
    '--popover-foreground': '#fafafa',
    '--primary': '#4aa8f0',
    '--primary-foreground': '#06121f',
    '--secondary': '#1f1f23',
    '--secondary-foreground': '#fafafa',
    '--muted': '#1f1f23',
    '--muted-foreground': '#a1a1aa',
    '--accent': '#1f1f23',
    '--accent-foreground': '#fafafa',
    '--destructive': '#ef4444',
    '--border': '#2e2e33',
    '--input': '#2e2e33',
    '--ring': '#4aa8f0',
} as CSSProperties;

export default function AuthCampusnetLayout({
    children,
    title,
    description,
}: PropsWithChildren<{
    title?: string;
    description?: string;
}>) {
    return (
        <div
            style={loginTheme}
            className="dark grid min-h-svh bg-background text-foreground lg:grid-cols-2"
        >
            {/* LEFT: design / branding panel (makita sa dako nga screen) */}
            <div className="relative hidden flex-col items-center justify-center overflow-hidden bg-[#121214] bg-[radial-gradient(ellipse_at_top,rgba(56,160,240,0.22),transparent_60%)] p-10 text-center text-white lg:flex">
                <img
                    src="/images/dssc-seal.png"
                    alt="Davao del Sur State College seal"
                    className="relative size-56 object-contain drop-shadow-[0_0_2rem_rgba(0,0,0,0.55)]"
                />
                <h2 className="relative mt-8 text-4xl font-bold tracking-tight">
                    DSSC CAMPUSNET
                </h2>
                <p className="relative mt-2 text-lg text-white/80">
                    Davao del Sur State College
                </p>
                <p className="relative mt-6 max-w-xs text-base text-white/60">
                    Campus bandwidth monitoring
                    <br />
                    and network usage analytics.
                </p>
            </div>

            {/* RIGHT: login form */}
            <div className="relative flex flex-col p-6 md:p-10">
                <Link href="/" className="flex items-center gap-2 text-sm font-medium">
                    <img
                        src="/images/dssc-seal.png"
                        alt=""
                        className="size-8 shrink-0 object-contain"
                    />
                    <span>DSSC CAMPUSNET</span>
                </Link>

                <div className="flex flex-1 items-center justify-center">
                    <div className="flex w-full max-w-sm flex-col gap-6">
                        {(title || description) && (
                            <div className="space-y-2 text-center">
                                {title && (
                                    <h1 className="text-2xl font-semibold">{title}</h1>
                                )}
                                {description && (
                                    <p className="text-sm text-balance text-muted-foreground">
                                        {description}
                                    </p>
                                )}
                            </div>
                        )}
                        {children}
                    </div>
                </div>
            </div>
        </div>
    );
}