import { Form, Head, Link, usePage } from '@inertiajs/react';
import { FlaskConical, ShieldCheck, Tv } from 'lucide-react';
import InputError from '@/components/input-error';
import PasswordInput from '@/components/password-input';
import TeamInvitationAlert from '@/components/team-invitation-alert';
import TextLink from '@/components/text-link';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { playErrorSound } from '@/hooks/use-error-sound';
import { store } from '@/routes/login';
import { request } from '@/routes/password';
/* @chisel-passkeys */
import PasskeyVerify from '@/components/passkey-verify';
/* @end-chisel-passkeys */
import type { TeamInvitationContext } from '@/types';
import type { CampusNetShared } from '@/types/campusnet';

type Props = {
    status?: string;
    canResetPassword: boolean;
    teamInvitation?: TeamInvitationContext | null;
};

const DISPLAY_FONT = "'Iowan Old Style', 'Palatino Linotype', Palatino, 'Book Antiqua', Georgia, serif";

export default function Login({
    status,
    canResetPassword,
    teamInvitation,
}: Props) {
    const { campusnet } = usePage<CampusNetShared>().props;
    const campusName = campusnet?.campus_name;

    return (
        <>
            <Head title="Sign in" />

            {/* Navy backdrop with the campus photo, in the DSSC colors (the card itself follows the selected theme). */}
            <div className="relative isolate flex min-h-svh flex-col items-center justify-center overflow-hidden bg-[#061a33] p-6 md:p-10">
                <img
                    src="/images/landing/hero.jpg"
                    alt=""
                    aria-hidden="true"
                    className="absolute inset-0 -z-20 size-full object-cover opacity-40"
                />
                <div
                    aria-hidden="true"
                    className="absolute inset-0 -z-10 bg-linear-to-br from-[#030d1b]/95 via-[#061a33]/85 to-[#7e090f]/50"
                />

                <div className="w-full max-w-sm md:max-w-4xl">
                    <div className="grid overflow-hidden rounded-xl border bg-card text-card-foreground shadow-2xl md:grid-cols-2">
                        {/* ---- Form (first in the code so it keeps the focus order; the photo panel is shown on its left) ---- */}
                        <div className="flex flex-col gap-6 p-6 md:p-10">
                            <div className="flex flex-col items-center gap-3 text-center md:items-start md:text-left">
                                <img
                                    src="/images/dssc-seal.png"
                                    alt="Seal of Davao del Sur State College"
                                    width={56}
                                    height={56}
                                    className="size-14 object-contain md:hidden"
                                />
                                <h1 className="text-2xl font-semibold tracking-tight">
                                    Sign in to CampusNet
                                </h1>
                                <p className="text-sm text-balance text-muted-foreground">
                                    Campus bandwidth monitoring and network
                                    usage analytics
                                </p>
                            </div>

                            {teamInvitation && (
                                <TeamInvitationAlert
                                    invitation={teamInvitation}
                                    action="Log in"
                                />
                            )}

                            {status && (
                                <div
                                    role="status"
                                    className="rounded-lg border border-emerald-600/30 bg-emerald-50 px-3 py-2 text-center text-sm font-medium text-emerald-900 dark:bg-emerald-950/40 dark:text-emerald-100"
                                >
                                    {status}
                                </div>
                            )}

                            {/* Honest about the data source before anyone even signs in. */}
                            {campusnet?.demo_mode && (
                                <div
                                    role="status"
                                    className="flex items-start gap-2 rounded-lg border border-amber-600/40 bg-amber-50 px-3 py-2 text-sm text-amber-950 dark:bg-amber-950/40 dark:text-amber-100"
                                >
                                    <FlaskConical
                                        className="mt-0.5 size-4 shrink-0"
                                        aria-hidden="true"
                                    />
                                    <span>
                                        <span className="font-medium">
                                            Demo mode.
                                        </span>{' '}
                                        This system is showing simulated data,
                                        not live network measurements.
                                    </span>
                                </div>
                            )}

                            {/* @chisel-passkeys */}
                            <PasskeyVerify />
                            {/* @end-chisel-passkeys */}

                            <Form
                                {...store.form()}
                                onError={playErrorSound}
                                resetOnSuccess={['password']}
                                className="flex flex-col gap-6"
                            >
                                {({ processing, errors }) => (
                                    <div className="grid gap-6">
                                        <div className="grid gap-2">
                                            <Label htmlFor="email">
                                                Email address
                                            </Label>
                                            <Input
                                                id="email"
                                                type="email"
                                                name="email"
                                                required
                                                autoFocus
                                                tabIndex={1}
                                                autoComplete="email"
                                                placeholder="you@school.edu.ph"
                                            />
                                            <InputError message={errors.email} />
                                        </div>

                                        <div className="grid gap-2">
                                            <div className="flex items-center">
                                                <Label htmlFor="password">
                                                    Password
                                                </Label>
                                                {canResetPassword && (
                                                    <TextLink
                                                        href={request()}
                                                        className="ml-auto text-sm"
                                                        tabIndex={5}
                                                    >
                                                        Forgot password?
                                                    </TextLink>
                                                )}
                                            </div>
                                            <PasswordInput
                                                id="password"
                                                name="password"
                                                required
                                                tabIndex={2}
                                                autoComplete="current-password"
                                                placeholder="Your password"
                                            />
                                            <InputError
                                                message={errors.password}
                                            />
                                        </div>

                                        <div className="flex items-center space-x-3">
                                            <Checkbox
                                                id="remember"
                                                name="remember"
                                                tabIndex={3}
                                            />
                                            <Label htmlFor="remember">
                                                Keep me signed in on this device
                                            </Label>
                                        </div>

                                        <Button
                                            type="submit"
                                            className="mt-2 w-full"
                                            tabIndex={4}
                                            disabled={processing}
                                            data-test="login-button"
                                        >
                                            {processing && <Spinner />}
                                            Sign in
                                        </Button>
                                    </div>
                                )}
                            </Form>

                            {/* A wall TV cannot type a password: it connects with a one-time code from a Super Administrator. */}
                            <div className="flex items-center gap-3 text-xs text-muted-foreground uppercase">
                                <span className="h-px flex-1 bg-border" />
                                or
                                <span className="h-px flex-1 bg-border" />
                            </div>

                            <Button
                                asChild
                                variant="outline"
                                className="w-full"
                                tabIndex={6}
                            >
                                <Link href="/tv-display/pair">
                                    <Tv className="size-4" aria-hidden="true" />
                                    Use a TV Display code
                                </Link>
                            </Button>

                            <p className="flex items-start justify-center gap-2 text-center text-xs text-muted-foreground">
                                <ShieldCheck
                                    className="mt-0.5 size-3.5 shrink-0"
                                    aria-hidden="true"
                                />
                                <span>
                                    Accounts are created by your ICT
                                    administrator.
                                    {campusName && campusName !== 'Campus'
                                        ? ` ${campusName} network monitoring.`
                                        : ''}
                                </span>
                            </p>
                        </div>

                        {/* ---- Photo panel (hidden on phones). md:order-first puts it on the LEFT of the card. ---- */}
                        <div className="relative hidden overflow-hidden bg-[#061a33] md:order-first md:block">
                            {/* lp-drift is the slow zoom defined in landing.css; without it the photo is simply still. */}
                            <img
                                src="/images/landing/building.jpg"
                                alt=""
                                aria-hidden="true"
                                className="absolute inset-0 size-full object-cover object-[62%_42%] motion-safe:animate-[lp-drift_40s_ease-in-out_infinite_alternate]"
                            />
                            <div
                                aria-hidden="true"
                                className="absolute inset-0 bg-[#093a6b] opacity-60 mix-blend-multiply"
                            />
                            <div
                                aria-hidden="true"
                                className="absolute inset-0 bg-linear-to-t from-[#030d1b] via-[#061a33]/35 to-[#030d1b]/55"
                            />

                            <div className="relative flex h-full flex-col justify-between gap-10 p-8 text-[#eaf1fa]">
                                <img
                                    src="/images/dssc-seal.png"
                                    alt="Seal of Davao del Sur State College"
                                    width={112}
                                    height={112}
                                    className="size-28 object-contain drop-shadow-[0_12px_32px_rgb(0_0_0/0.5)]"
                                />

                                <div>
                                    <p className="text-sm text-[#c9d8ec]">
                                        Davao del Sur State College
                                    </p>
                                    <p
                                        className="mt-2 text-4xl leading-[1.05] tracking-tight"
                                        style={{ fontFamily: DISPLAY_FONT }}
                                    >
                                        See every link on campus.
                                    </p>
                                </div>
                            </div>
                        </div>
                    </div>

                    <p className="mt-6 text-center text-sm">
                        <Link
                            href="/"
                            className="text-[#a9bdd7] underline-offset-4 hover:text-white hover:underline"
                        >
                            Back to the home page
                        </Link>
                    </p>
                </div>
            </div>
        </>
    );
}

/*
 * No `Login.layout = { ... }` here: this page draws its own full-screen layout.
 * It must be listed as "no layout" in resources/js/app.tsx (see the instructions).
 */