import { Link } from '@inertiajs/react';
import type { CSSProperties, PropsWithChildren } from 'react';

/**
 * DSSC seal palette: navy blue, red, green, white.
 * Ang mga variable gi-override diri lang (login page), dili apektado ang ubang bahin sa app.
 */
const dsscTheme = {
    '--background': '#071a36',
    '--foreground': '#f3f6fb',
    '--card': '#0c2750',
    '--card-foreground': '#f3f6fb',
    '--popover': '#0c2750',
    '--popover-foreground': '#f3f6fb',
    '--primary': '#1f5fbf',
    '--primary-foreground': '#ffffff',
    '--secondary': '#12335f',
    '--secondary-foreground': '#f3f6fb',
    '--muted': '#12335f',
    '--muted-foreground': '#9fb3d1',
    '--accent': '#12335f',
    '--accent-foreground': '#f3f6fb',
    '--destructive': '#ef4a55',
    '--border': '#254a7d',
    '--input': '#254a7d',
    '--ring': '#5b9bf0',
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
            style={dsscTheme}
            className="dark grid min-h-svh bg-background text-foreground lg:grid-cols-2"
        >
            {/* LEFT: design / branding panel (makita sa dako nga screen) */}
            <div className="relative hidden flex-col items-center justify-center overflow-hidden bg-[linear-gradient(160deg,#0b3270_0%,#071f45_55%,#04122a_100%)] p-10 text-center text-white lg:flex">
                {/* Soft glows: blue (taas), green (ubos), red (gamay) */}
                <div className="pointer-events-none absolute -top-32 -left-32 size-112 rounded-full bg-[#2f7be5]/30 blur-3xl" />
                <div className="pointer-events-none absolute -right-24 -bottom-32 size-104 rounded-full bg-[#4f8a1e]/30 blur-3xl" />
                <div className="pointer-events-none absolute top-1/2 -right-16 size-48 rounded-full bg-[#c8202f]/20 blur-3xl" />

                {/* Tricolor accent bar gikan sa seal */}
                <div className="absolute inset-x-0 top-0 flex h-1.5">
                    <span className="flex-1 bg-[#c8202f]" />
                    <span className="flex-1 bg-[#ffffff]" />
                    <span className="flex-1 bg-[#4f8a1e]" />
                </div>

                <img
                    src="/images/dssc-seal.png"
                    alt="Davao del Sur State College seal"
                    className="relative size-56 object-contain drop-shadow-[0_0_2rem_rgba(0,0,0,0.55)]"
                />
                <h2 className="relative mt-8 text-4xl font-bold tracking-tight">
                    DSSC CAMPUSNET
                </h2>
                <p className="relative mt-2 text-lg text-white/85">
                    Davao del Sur State College
                </p>
                <p className="relative mt-6 max-w-xs text-base text-white/65">
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