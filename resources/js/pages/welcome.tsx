import { Head, Link, usePage } from '@inertiajs/react';
import {
    Activity,
    Bell,
    Building2,
    FileText,
    Globe,
    History,
    LayoutDashboard,
    Network,
    Radar,
    Router,
    ScrollText,
    Settings,
    Tv,
    UserCheck,
    Users,
    type LucideIcon,
} from 'lucide-react';
import { useEffect, useRef, type CSSProperties, type MouseEvent } from 'react';

type SharedProps = {
    auth?: { user?: { name?: string } | null } | null;
};

type Module = { title: string; text: string; icon: LucideIcon };

/**
 * Add screenshots of the real pages here (put the files in public/images/landing/).
 * The gallery section only appears when this list is not empty, so nothing is ever faked.
 */
const SCREENSHOTS: { src: string; alt: string; caption: string }[] = [];

const MODULES: Module[] = [
    { title: 'Dashboard', icon: LayoutDashboard, text: 'Current traffic, utilization against campus capacity, device status and open incidents in one view, with an honest "data is stale" warning.' },
    { title: 'Network Devices', icon: Router, text: 'Register routers and switches with SNMP credentials that are encrypted at rest, test the connection safely, archive instead of delete.' },
    { title: 'Online Devices', icon: Radar, text: 'A live list of the devices that are connected to the campus network right now.' },
    { title: 'Interfaces', icon: Network, text: 'Every port with its latest valid rate. Mark which ones are WAN or uplinks and choose which ones are monitored.' },
    { title: 'Bandwidth Analytics', icon: Activity, text: 'Peak and average usage, busiest hours and the heaviest interfaces for the period you choose.' },
    { title: 'Traffic History', icon: History, text: 'Browse past measurements by device, interface and date range, grouped in campus time.' },
    { title: 'Alerts', icon: Bell, text: 'Unreachable devices, high utilization and missing data raise alerts that resolve themselves when things recover. People can acknowledge and resolve with a note.' },
    { title: 'Reports', icon: FileText, text: 'Traffic, alert and availability reports for any period, previewed on screen and exported as CSV.' },
    { title: 'Office Connections', icon: Building2, text: 'Which offices are connected, and how each one uses the network.' },
    { title: 'Connected Members', icon: UserCheck, text: 'Registered members and their devices, linked to the office they belong to.' },
    { title: 'Website Activity', icon: Globe, text: 'Domain-level activity from DNS, proxy or firewall logs. Never page contents, and never invented data.' },
    { title: 'TV Display', icon: Tv, text: 'A read-only wallboard for the ICT Office Smart TV, opened with a display token.' },
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

const STACK = ['Laravel 13', 'PHP 8.5', 'React 19', 'Inertia.js', 'TypeScript', 'Tailwind CSS 4', 'shadcn/ui', 'Recharts', 'MySQL', 'Vite', 'SNMP'];

const delay = (seconds: number) => ({ '--lp-delay': `${seconds}s` }) as CSSProperties;

function prefersReducedMotion() {
    return typeof window !== 'undefined' && window.matchMedia('(prefers-reduced-motion: reduce)').matches;
}

/**
 * Scroll effects for the whole page:
 *  - reveals elements as they enter the screen (.lp-reveal -> .is-visible),
 *  - lets the campus photo "develop" from navy to color,
 *  - turns the top bar solid after the first scroll.
 * Without JavaScript (or with reduced motion) everything is simply visible.
 */
function useLandingMotion() {
    const root = useRef<HTMLDivElement>(null);

    useEffect(() => {
        const page = root.current;

        if (!page) {
            return;
        }

        const onScroll = () => page.toggleAttribute('data-scrolled', window.scrollY > 24);
        onScroll();
        window.addEventListener('scroll', onScroll, { passive: true });

        const targets = page.querySelectorAll<HTMLElement>('.lp-reveal, .lp-campus');
        let observer: IntersectionObserver | undefined;

        if (!prefersReducedMotion() && 'IntersectionObserver' in window) {
            page.setAttribute('data-motion', '');
            observer = new IntersectionObserver(
                (entries) => {
                    entries.forEach((entry) => {
                        if (entry.isIntersecting) {
                            entry.target.classList.add('is-visible');
                            observer?.unobserve(entry.target);
                        }
                    });
                },
                { threshold: 0.15, rootMargin: '0px 0px -6% 0px' },
            );
            targets.forEach((target) => observer?.observe(target));
        } else {
            targets.forEach((target) => target.classList.add('is-visible'));
        }

        return () => {
            window.removeEventListener('scroll', onScroll);
            observer?.disconnect();
            page.removeAttribute('data-motion');
        };
    }, []);

    return root;
}

function goTo(event: MouseEvent<HTMLAnchorElement>, id: string) {
    const target = document.getElementById(id);

    if (!target) {
        return;
    }

    event.preventDefault();
    target.scrollIntoView({ behavior: prefersReducedMotion() ? 'auto' : 'smooth', block: 'start' });
}

export default function Landing() {
    const { auth } = usePage<SharedProps>().props;
    const signedIn = Boolean(auth?.user);
    const entryHref = signedIn ? '/dashboard' : '/login';
    const entryLabel = signedIn ? 'Open the dashboard' : 'Sign in';
    const page = useLandingMotion();

    return (
        <div ref={page} className="lp">
            <Head title="DSSC CampusNet · Campus bandwidth monitoring" />

            <a href="#main" className="lp-skip" onClick={(event) => goTo(event, 'main')}>
                Skip to content
            </a>

            <header className="lp-nav">
                <Link href="/" className="lp-brand">
                    <img src="/images/dssc-seal.png" alt="" width={34} height={34} />
                    <span>DSSC CampusNet</span>
                </Link>

                <nav className="lp-nav__links" aria-label="Page sections">
                    <a className="lp-nav__link" href="#modules" onClick={(event) => goTo(event, 'modules')}>
                        Modules
                    </a>
                    <a className="lp-nav__link" href="#roles" onClick={(event) => goTo(event, 'roles')}>
                        Roles
                    </a>
                    <a className="lp-nav__link" href="#security" onClick={(event) => goTo(event, 'security')}>
                        Security
                    </a>
                    <a className="lp-nav__link" href="#status" onClick={(event) => goTo(event, 'status')}>
                        Status
                    </a>
                </nav>

                <Link href={entryHref} className="lp-btn lp-btn--maroon lp-nav__cta">
                    {entryLabel}
                </Link>
            </header>

            <main id="main">
                {/* ---- Hero ---- */}
                <section className="lp-hero" aria-labelledby="lp-title">
                    <div className="lp-hero__media" aria-hidden="true">
                        <img
                            className="lp-hero__img"
                        src="/images/landing/hero.jpg"
                            alt=""
                            width={2000}
                            height={2000}
                            fetchPriority="high"
                            decoding="async"
                        />
                    </div>
                    <div className="lp-hero__shade" aria-hidden="true" />
                    <div className="lp-grain" aria-hidden="true" />
                    <div className="lp-shutter lp-shutter--top" aria-hidden="true" />
                    <div className="lp-shutter lp-shutter--bottom" aria-hidden="true" />

                    <div className="lp-wrap lp-hero__content">
                        <p className="lp-school lp-fade" style={delay(1.0)}>
                            Davao del Sur State College
                        </p>

                        <h1 id="lp-title" className="lp-title">
                            <span className="lp-rise" style={delay(1.15)}>
                                <span>See every link</span>
                            </span>
                            <span className="lp-rise" style={delay(1.3)}>
                                <span>on campus.</span>
                            </span>
                        </h1>

                        <p className="lp-lede lp-fade" style={delay(1.8)}>
                            CampusNet gives the ICT Office one place to watch internet usage, see which links are busy, get alerted when
                            something breaks, and report on it, without exposing device credentials.
                        </p>

                        <div className="lp-actions lp-fade" style={delay(2.0)}>
                            <Link href={entryHref} className="lp-btn lp-btn--maroon">
                                {entryLabel}
                            </Link>
                            <a className="lp-btn lp-btn--ghost" href="#modules" onClick={(event) => goTo(event, 'modules')}>
                                See what it does
                            </a>
                        </div>

                        <p className="lp-note lp-fade" style={delay(2.2)}>
                            An instructor-led IT system project, built with Laravel and React.
                        </p>
                    </div>

                    <span className="lp-cue" aria-hidden="true">
                        Scroll
                    </span>
                </section>

                {/* ---- Campus photo ---- */}
                <section id="campus" className="lp-campus" aria-labelledby="lp-campus-title">
                    <div className="lp-campus__frame" aria-hidden="true">
                        <img
                            className="lp-campus__img"
                        src="/images/landing/building.jpg"
                            alt=""
                            width={1024}
                            height={614}
                            loading="lazy"
                            decoding="async"
                        />
                    </div>
                    <div className="lp-campus__wash" aria-hidden="true" />
                    <div className="lp-campus__shade" aria-hidden="true" />

                    <div className="lp-wrap lp-campus__copy">
                        <h2 id="lp-campus-title" className="lp-h2 lp-reveal">
                            One view of the
                            <br />
                            whole campus.
                        </h2>
                        <p className="lp-p lp-reveal" style={delay(0.12)}>
                            From the main building to the farthest office, every router, interface and connected device is tracked
                            in one place. The ICT Office can spot a slow link before anyone has to report it.
                        </p>
                    </div>
                </section>

                {/* ---- Modules ---- */}
                <section id="modules" className="lp-section" aria-labelledby="lp-modules-title">
                    <div className="lp-wrap">
                        <div className="lp-head">
                            <h2 id="lp-modules-title" className="lp-h2 lp-reveal">
                                {MODULES.length} modules, one purpose
                            </h2>
                            <p className="lp-p lp-reveal" style={delay(0.1)}>
                                From the first measurement to the report on the principal&rsquo;s desk.
                            </p>
                        </div>

                        <ul className="lp-modules">
                            {MODULES.map(({ title, text, icon: Icon }, index) => (
                                <li key={title} className="lp-module lp-reveal" style={delay((index % 3) * 0.08)}>
                                    <Icon aria-hidden="true" />
                                    <h3>{title}</h3>
                                    <p>{text}</p>
                                </li>
                            ))}
                        </ul>
                    </div>
                </section>

                {SCREENSHOTS.length > 0 && (
                    <section className="lp-section lp-section--tight" aria-labelledby="lp-shots-title">
                        <div className="lp-wrap">
                            <h2 id="lp-shots-title" className="lp-h2 lp-reveal">
                                Screenshots
                            </h2>
                            <ul className="lp-shots">
                                {SCREENSHOTS.map((shot) => (
                                    <li key={shot.src} className="lp-reveal">
                                        <figure>
                                            <img src={shot.src} alt={shot.alt} loading="lazy" />
                                            <figcaption>{shot.caption}</figcaption>
                                        </figure>
                                    </li>
                                ))}
                            </ul>
                        </div>
                    </section>
                )}

                {/* ---- Roles ---- */}
                <section id="roles" className="lp-section lp-section--alt" aria-labelledby="lp-roles-title">
                    <div className="lp-wrap">
                        <div className="lp-head">
                            <h2 id="lp-roles-title" className="lp-h2 lp-reveal">
                                The right access for each person
                            </h2>
                        </div>

                        <ul className="lp-roles">
                            {ROLES.map((role, index) => (
                                <li key={role.name} className="lp-role lp-reveal" style={delay(index * 0.08)}>
                                    <h3>{role.name}</h3>
                                    <p>{role.text}</p>
                                </li>
                            ))}
                        </ul>
                    </div>
                </section>

                {/* ---- Security ---- */}
                <section id="security" className="lp-section" aria-labelledby="lp-security-title">
                    <div className="lp-wrap">
                        <div className="lp-head">
                            <h2 id="lp-security-title" className="lp-h2 lp-reveal">
                                Built to be safe to run
                            </h2>
                        </div>

                        <ul className="lp-safe">
                            {SAFEGUARDS.map((item, index) => (
                                <li key={item} className="lp-reveal" style={delay((index % 2) * 0.08)}>
                                    {item}
                                </li>
                            ))}
                        </ul>
                    </div>
                </section>

                {/* ---- Status ---- */}
                <section id="status" className="lp-section lp-section--alt" aria-labelledby="lp-status-title">
                    <div className="lp-wrap">
                        <div className="lp-head">
                            <h2 id="lp-status-title" className="lp-h2 lp-reveal">
                                Where it stands
                            </h2>
                        </div>

                        <div className="lp-status">
                            <article className="lp-reveal">
                                <h3>Working today</h3>
                                <p>
                                    Every module above, a polling pipeline with a simulated driver for demonstrations, and an automated
                                    test suite of more than 300 tests.
                                </p>
                            </article>
                            <article className="lp-reveal" style={delay(0.08)}>
                                <h3>Next milestone</h3>
                                <p>
                                    The live SNMP polling driver for real campus equipment. Until it is switched on, the system says
                                    plainly that its numbers are simulated.
                                </p>
                            </article>
                        </div>

                        <h3 className="lp-built lp-reveal">Built with</h3>
                        <ul className="lp-stack lp-reveal" style={delay(0.08)}>
                            {STACK.map((item) => (
                                <li key={item}>{item}</li>
                            ))}
                        </ul>
                    </div>
                </section>

                {/* ---- Closing call to action ---- */}
                <section className="lp-cta" aria-labelledby="lp-cta-title">
                    <div className="lp-cta__bg" aria-hidden="true" />
                    <div className="lp-grain" aria-hidden="true" />

                    <div className="lp-wrap">
                        <h2 id="lp-cta-title" className="lp-h2 lp-reveal">
                            {signedIn ? 'Your network is waiting.' : 'Sign in to see the network.'}
                        </h2>
                        <p className="lp-p lp-reveal" style={delay(0.1)}>
                            {signedIn
                                ? 'Pick up where you left off.'
                                : 'Accounts are created by the ICT Office. Public registration is closed.'}
                        </p>
                        <div className="lp-actions lp-reveal" style={delay(0.2)}>
                            <Link href={entryHref} className="lp-btn lp-btn--light">
                                {entryLabel}
                            </Link>
                        </div>
                    </div>
                </section>
            </main>

            <footer className="lp-wrap lp-foot">
                <span>DSSC CampusNet, Davao del Sur State College</span>
                <span>Designed and built by Jerick Vicente</span>
            </footer>
        </div>
    );
}