import { Link } from '@inertiajs/react';
import { home } from '@/routes';
import type { AuthLayoutProps } from '@/types';

export default function AuthCampusNetLayout({
    children,
    title,
    description,
}: AuthLayoutProps) {
    return (
        <div className="grid min-h-svh lg:grid-cols-2">
            <div className="flex flex-col gap-4 p-6 md:p-10">
                <div className="flex justify-center gap-2 md:justify-start">
                    <Link
                        href={home()}
                        className="flex items-center gap-2.5 font-medium"
                    >
                        <img
                            src="/images/dssc-seal.png"
                            alt=""
                            width={32}
                            height={32}
                            className="size-8"
                        />
                        DSSC CAMPUSNET
                    </Link>
                </div>

                <div className="flex flex-1 items-center justify-center">
                    <div className="flex w-full max-w-sm flex-col gap-6">
                        <div className="flex flex-col items-center gap-1 text-center">
                            <h1 className="text-2xl font-bold">{title}</h1>
                            <p className="text-sm text-balance text-muted-foreground">
                                {description}
                            </p>
                        </div>

                        {children}
                    </div>
                </div>
            </div>

            <div className="relative hidden flex-col items-center justify-center gap-6 overflow-hidden bg-zinc-900 p-10 text-center text-white lg:flex">
                <div
                    className="pointer-events-none absolute inset-0 bg-[radial-gradient(70%_60%_at_50%_0%,rgba(16,185,129,0.25),transparent)]"
                    aria-hidden="true"
                />

                <img
                    src="/images/dssc-seal.png"
                    alt="Seal of Davao del Sur State College"
                    width={224}
                    height={224}
                    className="relative size-56"
                />

                <div className="relative space-y-1">
                    <p className="text-3xl font-semibold tracking-wide">
                        DSSC CAMPUSNET
                    </p>
                    <p className="text-zinc-300">Davao del Sur State College</p>
                </div>

                <p className="relative max-w-sm text-balance text-zinc-400">
                    Campus bandwidth monitoring and network usage analytics.
                </p>
            </div>
        </div>
    );
}