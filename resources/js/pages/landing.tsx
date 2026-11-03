import { Head, usePage } from '@inertiajs/react';
import {
    Activity,
    ArrowRight,
    Bell,
    FileText,
    History,
    KeyRound,
    LayoutDashboard,
    Network,
    Router,
    ScrollText,
    Settings,
    ShieldCheck,
    Users,
} from 'lucide-react';
import type { ComponentType } from 'react';

type Module = { title: string; text: string; icon: ComponentType<{ className?: string }> };

/**
 * Add screenshots of the real pages here (put the files in public/images/landing/).
 * The gallery section only appears when this list is not empty, so nothing is ever faked.
 */
const SCREENSHOTS: { src: string; alt: string; caption: string }[] = [];

const MODULES: Module[] = [
    { title: 'Dashboard', icon: LayoutDashboard, text: 'Current traffic, utilization against campus capacity, device status and open incidents in one view, with an honest "data is stale" warning.' },
    { title: 'Network Devices', icon: Router, text: 'Register routers and switches with SNMP credentials that are encrypted at rest, test the connection safely, archive instead of delete.' },
    { title: 'Interfaces', icon: Network, text: 'Every port with its latest valid rate. Mark which ones are WAN or uplinks and choose which ones are monitored.' },
    { title: 'Bandwidth Analytics', icon: Activity, text: 'Peak and average usage, busiest hours and the heaviest interfaces for the period you choose.' },
    { title: 'Traffic History', icon: History, text: 'Browse past measurements by device, interface and date range, grouped in campus time.' },
    { title: 'Alerts', icon: Bell, text: 'Unreachable devices, high utilization and missing data raise alerts that resolve themselves when things recover. People can acknowledge and resolve with a note.' },
    { title: 'Reports', icon: FileText, text: 'Traffic, alert and availability reports for any period, previewed on screen and exported as CSV.' },
    { title: 'User Management', icon: Users, text: 'Change roles and activate or deactivate accounts. A deactivated person is signed out on their next request.' },
    { title: 'Audit Logs', icon: ScrollText, text: 'Who signed in and who changed what, with secrets redacted and old entries removed automatically.' },
    { title: 'System Settings', icon: Settings, text: 'Campus name, time zone, capacity and alert thresholds, each change recorded with the old and new value.' },
];

const ROLES = [
    { name: 'Super Administrator', text: 'Everything, including accounts, system settings and the audit log.' },
    { name: 'ICT / Network Administrator', text: 'Manages devices, interfaces and alerts and exports reports. Reads the settings but cannot change them.' },
    { name: 'Read-Only Viewer', text: 'Sees the dashboard, devices, traffic, alerts and report previews. Changes nothing and downloads nothing.' },
];

const SAFEGUARDS = [
    'Device credentials are encrypted at rest and are never sent to the browser.',
    'Management addresses must be safe unicast addresses, so a device test cannot be aimed at the server itself.',
    'Connection tests and report exports are rate limited.',
    'Spreadsheet cells that look like formulas are neutralised in CSV exports.',
    'Public registration is off. Accounts are created by an administrator.',
    'Simulated data is always labelled as demo and never mixed with real measurements.',
];

const STACK = ['Laravel 13', 'PHP 8.3', 'React 19', 'Inertia.js', 'TypeScript', 'Tailwind CSS 4', 'shadcn/ui', 'Recharts', 'MySQL', 'Vite', 'SNMP'];

export default function Landing() {
    const { auth } = usePage<{ auth?: { user?: { name?: string } | null } }>().props;
    const signedIn = Boolean(auth?.user);

    const cta = signedIn ? { href: '/dashboard', label: 'Open the dashboard' } : { href: '/login', label: 'Sign in' };

    return (
        <>
            <Head title="CampusNet · Campus bandwidth monitoring" />

            <div className="min-h-screen bg-neutral-950 text-neutral-100 antialiased">
                <a href="#main" className="sr-only focus:not-sr-only focus:absolute focus:top-2 focus:left-2 focus:z-50 focus:rounded-md focus:bg-white focus:px-3 focus:py-2 focus:text-neutral-950">
                    Skip to content
                </a>

                <header className="sticky top-0 z-40 border-b border-white/10 bg-neutral-950/80 backdrop-blur">
                    <div className="mx-auto flex h-14 max-w-6xl items-center justify-between px-4">
                        <a href="/" className="flex items-center gap-2 font-semibold tracking-tight">
                            <span className="grid size-7 place-items-center rounded-md bg-emerald-500 text-neutral-950" aria-hidden="true">
                                <Network className="size-4" />
                            </span>
                            CampusNet
                        </a>

                        <nav aria-label="Page sections" className="hidden items-center gap-6 text-sm text-neutral-300 md:flex">
                            <a className="hover:text-white" href="#modules">Modules</a>
                            <a className="hover:text-white" href="#roles">Roles</a>
                            <a className="hover:text-white" href="#security">Security</a>
                            <a className="hover:text-white" href="#status">Status</a>
                        </nav>

                        <a href={cta.href} className="rounded-md bg-white px-3 py-1.5 text-sm font-medium text-neutral-950 hover:bg-neutral-200 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-white">
                            {cta.label}
                        </a>
                    </div>
                </header>

                <main id="main">
                    <section className="relative overflow-hidden border-b border-white/10">
                        <div className="pointer-events-none absolute inset-0 -z-0 bg-[radial-gradient(60%_50%_at_50%_0%,rgba(16,185,129,0.18),transparent)]" aria-hidden="true" />
                        <div className="relative mx-auto max-w-6xl px-4 py-20 md:py-28">
                            <p className="mb-4 inline-block rounded-full border border-white/15 px-3 py-1 text-xs text-neutral-300">Campus bandwidth monitoring and network usage analytics</p>
                            <h1 className="max-w-3xl text-4xl font-semibold tracking-tight text-balance md:text-6xl">Know where your campus bandwidth goes.</h1>
                            <p className="mt-5 max-w-2xl text-lg text-neutral-300">
                                CampusNet gives an ICT team one place to watch internet usage, see which links are busy, get alerted when something breaks, and report on it, without guessing and without
                                exposing device credentials.
                            </p>

                            <div className="mt-8 flex flex-wrap items-center gap-3">
                                <a href={cta.href} className="inline-flex items-center gap-2 rounded-md bg-emerald-500 px-4 py-2.5 font-medium text-neutral-950 hover:bg-emerald-400 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-emerald-300">
                                    {cta.label}
                                    <ArrowRight className="size-4" aria-hidden="true" />
                                </a>
                                <a href="#modules" className="rounded-md border border-white/20 px-4 py-2.5 font-medium hover:bg-white/5">
                                    See what it does
                                </a>
                            </div>

                            <p className="mt-6 text-sm text-neutral-400">An instructor-led IT system project, built with Laravel and React.</p>
                        </div>
                    </section>

                    <section id="modules" aria-labelledby="modules-title" className="mx-auto max-w-6xl px-4 py-16">
                        <h2 id="modules-title" className="text-2xl font-semibold tracking-tight md:text-3xl">Ten modules, one purpose</h2>
                        <p className="mt-2 max-w-2xl text-neutral-300">From the first measurement to the report on the principal's desk.</p>

                        <ul className="mt-8 grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                            {MODULES.map(({ title, text, icon: Icon }) => (
                                <li key={title} className="rounded-xl border border-white/10 bg-white/[0.03] p-5">
                                    <Icon className="mb-3 size-5 text-emerald-400" aria-hidden="true" />
                                    <h3 className="font-medium">{title}</h3>
                                    <p className="mt-1.5 text-sm text-neutral-300">{text}</p>
                                </li>
                            ))}
                        </ul>
                    </section>

                    {SCREENSHOTS.length > 0 && (
                        <section aria-labelledby="shots-title" className="mx-auto max-w-6xl px-4 pb-16">
                            <h2 id="shots-title" className="text-2xl font-semibold tracking-tight md:text-3xl">Screenshots</h2>
                            <ul className="mt-8 grid gap-6 md:grid-cols-2">
                                {SCREENSHOTS.map((shot) => (
                                    <li key={shot.src}>
                                        <figure>
                                            <img src={shot.src} alt={shot.alt} loading="lazy" className="w-full rounded-xl border border-white/10" />
                                            <figcaption className="mt-2 text-sm text-neutral-400">{shot.caption}</figcaption>
                                        </figure>
                                    </li>
                                ))}
                            </ul>
                        </section>
                    )}

                    <section id="roles" aria-labelledby="roles-title" className="border-y border-white/10 bg-white/[0.02]">
                        <div className="mx-auto max-w-6xl px-4 py-16">
                            <h2 id="roles-title" className="text-2xl font-semibold tracking-tight md:text-3xl">The right access for each person</h2>
                            <ul className="mt-8 grid gap-4 md:grid-cols-3">
                                {ROLES.map((role) => (
                                    <li key={role.name} className="rounded-xl border border-white/10 p-5">
                                        <h3 className="flex items-center gap-2 font-medium">
                                            <KeyRound className="size-4 text-emerald-400" aria-hidden="true" />
                                            {role.name}
                                        </h3>
                                        <p className="mt-1.5 text-sm text-neutral-300">{role.text}</p>
                                    </li>
                                ))}
                            </ul>
                        </div>
                    </section>

                    <section id="security" aria-labelledby="security-title" className="mx-auto max-w-6xl px-4 py-16">
                        <h2 id="security-title" className="flex items-center gap-2 text-2xl font-semibold tracking-tight md:text-3xl">
                            <ShieldCheck className="size-6 text-emerald-400" aria-hidden="true" />
                            Built to be safe to run
                        </h2>
                        <ul className="mt-6 grid gap-3 md:grid-cols-2">
                            {SAFEGUARDS.map((item) => (
                                <li key={item} className="flex gap-3 rounded-lg border border-white/10 p-4 text-sm text-neutral-200">
                                    <span className="mt-1.5 size-1.5 shrink-0 rounded-full bg-emerald-400" aria-hidden="true" />
                                    {item}
                                </li>
                            ))}
                        </ul>
                    </section>

                    <section id="status" aria-labelledby="status-title" className="border-t border-white/10 bg-white/[0.02]">
                        <div className="mx-auto max-w-6xl px-4 py-16">
                            <h2 id="status-title" className="text-2xl font-semibold tracking-tight md:text-3xl">Where it stands</h2>
                            <div className="mt-6 grid gap-4 md:grid-cols-2">
                                <div className="rounded-xl border border-white/10 p-5">
                                    <h3 className="font-medium">Working today</h3>
                                    <p className="mt-1.5 text-sm text-neutral-300">
                                        Every module above, a polling pipeline with a simulated driver for demonstrations, and an automated test suite of more than 300 tests.
                                    </p>
                                </div>
                                <div className="rounded-xl border border-white/10 p-5">
                                    <h3 className="font-medium">Next milestone</h3>
                                    <p className="mt-1.5 text-sm text-neutral-300">The live SNMP polling driver for real campus equipment. Until it is switched on, the system says plainly that its numbers are simulated.</p>
                                </div>
                            </div>

                            <h3 className="mt-10 text-sm font-medium text-neutral-400">Built with</h3>
                            <ul className="mt-3 flex flex-wrap gap-2">
                                {STACK.map((item) => (
                                    <li key={item} className="rounded-full border border-white/15 px-3 py-1 text-sm text-neutral-200">
                                        {item}
                                    </li>
                                ))}
                            </ul>
                        </div>
                    </section>

                    <section className="mx-auto max-w-6xl px-4 py-16 text-center">
                        <h2 className="text-2xl font-semibold tracking-tight md:text-3xl">Ready to look at the network?</h2>
                        <a href={cta.href} className="mt-6 inline-flex items-center gap-2 rounded-md bg-emerald-500 px-5 py-3 font-medium text-neutral-950 hover:bg-emerald-400 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-emerald-300">
                            {cta.label}
                            <ArrowRight className="size-4" aria-hidden="true" />
                        </a>
                    </section>
                </main>

                <footer className="border-t border-white/10">
                    <div className="mx-auto flex max-w-6xl flex-wrap items-center justify-between gap-2 px-4 py-6 text-sm text-neutral-400">
                        <p>CampusNet &middot; Campus bandwidth monitoring</p>
                        <p>Designed and built by Jerick Vicente</p>
                    </div>
                </footer>
            </div>
        </>
    );
}