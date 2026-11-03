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
                        <span className="font-medium">Demo mode.</span> This
                        system is showing simulated data, not live network
                        measurements.
                    </span>
                </div>
            )}

            {/* @chisel-passkeys */}
            <PasskeyVerify />
            {/* @end-chisel-passkeys */}

            <Form
                {...store.form()}
                resetOnSuccess={['password']}
                className="flex flex-col gap-6"
            >
                {({ processing, errors }) => (
                    <>
                        <div className="grid gap-6">
                            <div className="grid gap-2">
                                <Label htmlFor="email">Email address</Label>
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
                                    <Label htmlFor="password">Password</Label>
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
                                <InputError message={errors.password} />
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
                    </>
                )}
            </Form>

            {/* A wall TV cannot type a password: it connects with a one-time code from a Super Administrator. */}
            <div className="flex items-center gap-3 text-xs text-muted-foreground uppercase">
                <span className="h-px flex-1 bg-border" />
                or
                <span className="h-px flex-1 bg-border" />
            </div>

            <Button asChild variant="outline" className="w-full" tabIndex={6}>
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
                    Accounts are created by your ICT administrator.
                    {campusName && campusName !== 'Campus'
                        ? ` ${campusName} network monitoring.`
                        : ''}
                </span>
            </p>
        </>
    );
}

Login.layout = {
    title: 'Sign in to CampusNet',
    description: 'Campus bandwidth monitoring and network usage analytics',
};