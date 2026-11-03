import { Link } from '@inertiajs/react';
import { Card, CardContent } from '@/components/ui/card';
import { home } from '@/routes';
import type { AuthLayoutProps } from '@/types';

export default function AuthCampusNetLayout({
    children,
    title,
    description,
}: AuthLayoutProps) {
    return (
        <div className="flex min-h-svh flex-col items-center justify-center bg-muted p-6 md:p-10">
            <div className="w-full max-w-sm md:max-w-4xl">
                <div className="flex flex-col gap-6">
                    <Card className="overflow-hidden p-0">
                        <CardContent className="grid p-0 md:grid-cols-2">
                            <div className="flex flex-col gap-6 p-6 md:p-10">
                                <div className="flex flex-col items-center gap-3 text-center">
                                    {/* The seal sits above the title on small screens, where the side panel is hidden. */}
                                    <Link
                                        href={home()}
                                        className="md:hidden"
                                        aria-label="DSSC CampusNet home page"
                                    >
                                        <img
                                            src="/images/dssc-seal.png"
                                            alt=""
                                            width={64}
                                            height={64}
                                            className="size-16"
                                        />
                                    </Link>

                                    <h1 className="text-2xl font-bold">
                                        {title}
                                    </h1>
                                    <p className="text-sm text-balance text-muted-foreground">
                                        {description}
                                    </p>
                                </div>

                                {children}
                            </div>

                            <div className="relative hidden flex-col items-center justify-center gap-5 bg-zinc-900 p-10 text-center text-white md:flex">
                                <div
                                    className="pointer-events-none absolute inset-0 bg-[radial-gradient(70%_60%_at_50%_0%,rgba(16,185,129,0.22),transparent)]"
                                    aria-hidden="true"
                                />

                                <Link
                                    href={home()}
                                    className="relative"
                                    aria-label="DSSC CampusNet home page"
                                >
                                    <img
                                        src="/images/dssc-seal.png"
                                        alt="Seal of Davao del Sur State College"
                                        width={176}
                                        height={176}
                                        className="size-44"
                                    />
                                </Link>

                                <div className="relative space-y-1">
                                    <p className="text-2xl font-semibold tracking-wide">
                                        DSSC CAMPUSNET
                                    </p>
                                    <p className="text-sm text-zinc-300">
                                        Davao del Sur State College
                                    </p>
                                </div>

                                <p className="relative max-w-xs text-sm text-balance text-zinc-400">
                                    Campus bandwidth monitoring and network
                                    usage analytics.
                                </p>
                            </div>
                        </CardContent>
                    </Card>

                    <p className="text-center text-sm text-muted-foreground">
                        <Link
                            href={home()}
                            className="underline underline-offset-4 hover:text-foreground"
                        >
                            About CampusNet
                        </Link>
                    </p>
                </div>
            </div>
        </div>
    );
}