import { Head } from '@inertiajs/react';
import { useEffect, useMemo, useRef, useState } from 'react';

import { TvAnalytics } from '@/components/tv/tv-analytics';
import { TvChart, type ChartPoint } from '@/components/tv/tv-chart';
import {
    AlertsPanel,
    DevicesPanel,
    EventsPanel,
    Glass,
    HealthPanel,
    KpiRow,
    StatusBanner,
    TvHeader,
} from '@/components/tv/tv-panels';
import { COMPACT_QUERY } from '@/hooks/use-compact-layout';
import { useConnectionStale, useTvData } from '@/hooks/use-tv-data';
import { cn } from '@/lib/utils';
import type { TvData, TvPageProps } from '@/types/tv-display';

const PANELS = [
    { id: 'devices', label: 'Devices' },
    { id: 'alerts', label: 'Alerts' },
    { id: 'health', label: 'Health' },
    { id: 'events', label: 'Events' },
] as const;

type PanelId = (typeof PANELS)[number]['id'];
type ViewId = 'overview' | 'traffic' | 'devices';
type SlideId = 'overview' | 'analytics';
type SlideChoice = 'auto' | SlideId;
type Settings = {
    view: ViewId;
    interval: number;
    range: 'hour' | 'session';
    hidden: PanelId[];
    slide: SlideChoice;
    rotate: number;
};

const INTERVALS = [3, 5, 10, 15, 30, 60]; // safe limits: never faster than 3 s
const ROTATIONS = [10, 15, 20, 30, 60]; // seconds each slide stays on screen in auto mode
const SLIDE_CHOICES: SlideChoice[] = ['auto', 'overview', 'analytics'];
const SLIDES: { id: SlideId; label: string }[] = [
    { id: 'overview', label: 'Overview' },
    { id: 'analytics', label: 'Analytics' },
];
const DEFAULTS: Settings = { view: 'overview', interval: 5, range: 'hour', hidden: [], slide: 'auto', rotate: 10 };
const STORAGE_KEY = 'campusnet.tv.settings.v2';

/** The graph never holds more than one hour, however long the display stays open. */
const HISTORY_WINDOW_MS = 60 * 60 * 1000;
const MAX_POINTS = 720;

const CONTROLS_HIDE_MS = 15_000;
const OK_KEYS = ['Enter', ' ', 'ContextMenu', 'Select'];
const BACK_KEYS = ['Escape', 'GoBack', 'BrowserBack', 'XF86Back'];

/**
 * Layout and motion for the whole page.
 *
 * - The root font size is the ONE scale factor: 1920x1080 gives 16px, 3840x2160 gives 32px, 1366x768 gives
 *   11.4px. Every rem in the panels follows it, so all TV resolutions look the same, only sharper or softer.
 * - A wall display never scrolls. A phone or an upright tablet (the "compact" query, which the JS hook shares)
 *   stacks the panels and scrolls instead.
 * - The only animations are one 400 ms fade per slide change and a progress line that moves once a second.
 *   Nothing runs forever, and reduced-motion turns both off.
 */
const PAGE_CSS = `
html.tv-root { font-size: 0.8333vw; font-size: clamp(10px, min(0.8333vw, 1.4815vh), 48px); -webkit-text-size-adjust: 100%; }

.tv-stage {
    --tv-kpi: 4.4rem; --tv-clock: 3rem; --tv-title: 2.2rem; --tv-badge: 1.5rem; --tv-seal: 4.5rem;
    position: fixed; inset: 0; display: flex; flex-direction: column; gap: 1rem;
    padding: 1.25rem 1.5rem 1.75rem; overflow: hidden; color: #fff; background-color: #060a13;
    background-image:
        radial-gradient(60rem 30rem at 8% -8%, rgba(56,189,248,0.16), transparent),
        radial-gradient(50rem 30rem at 100% 110%, rgba(52,211,153,0.10), transparent);
    font-family: system-ui, -apple-system, "Segoe UI", Roboto, sans-serif;
}
.tv-header { display: flex; flex-shrink: 0; align-items: center; justify-content: space-between; gap: 1.5rem; }
.tv-body { position: relative; flex: 1 1 0; min-height: 0; }
.tv-slide { position: absolute; inset: 0; display: flex; flex-direction: column; gap: 1rem; animation: tv-fade-in 400ms ease-out both; }
.tv-kpis { display: grid; flex-shrink: 0; grid-template-columns: repeat(4, minmax(0, 1fr)); gap: 1rem; }
.tv-bottom { display: grid; min-height: 0; gap: 1rem; grid-template-columns: repeat(var(--tv-cols, 4), minmax(0, 1fr)); grid-template-rows: minmax(0, 1fr); }

.tv-btn {
    min-height: max(3rem, 44px); padding: 0 1.1rem; border-radius: 0.75rem; font-weight: 500;
    background: rgba(255,255,255,0.06); color: rgba(255,255,255,0.85); box-shadow: inset 0 0 0 1px rgba(255,255,255,0.18);
}
.tv-btn[aria-pressed='true'] { background: rgba(14,165,233,0.32); color: #fff; box-shadow: inset 0 0 0 2px rgba(56,189,248,0.8); }
.tv-btn:focus { outline: none; }
.tv-btn:focus-visible { outline: 0.25rem solid #fff; outline-offset: 0.15rem; }

@keyframes tv-fade-in { from { opacity: 0; } to { opacity: 1; } }
@keyframes tv-progress { from { transform: scaleX(0); } to { transform: scaleX(1); } }
.tv-progress { width: 100%; transform-origin: left; animation: tv-progress linear both; }

@media ${COMPACT_QUERY} {
    html.tv-root { font-size: 1.2vw; font-size: clamp(12px, calc(8px + 1.2vw), 18px); }
    .tv-stage { --tv-kpi: 2.8rem; --tv-clock: 2.2rem; --tv-title: 1.6rem; --tv-badge: 1.1rem; --tv-seal: 3.2rem; overflow-x: hidden; overflow-y: auto; overscroll-behavior: contain; padding: 1rem 1rem 1.5rem; }
    .tv-header { flex-direction: column; align-items: stretch; gap: 0.75rem; }
    .tv-header .tv-end { text-align: left; }
    .tv-body { flex: none; min-height: auto; }
    .tv-slide { position: static; }
    .tv-kpis { grid-template-columns: repeat(2, minmax(0, 1fr)); }
    .tv-chart { flex: none; height: 22rem; }
    .tv-bottom { flex: none; grid-template-columns: repeat(auto-fit, minmax(min(100%, 22rem), 1fr)); grid-template-rows: none; }
    .tv-dots { display: none; }
}
@media (max-width: 520px) {
    .tv-kpis { grid-template-columns: minmax(0, 1fr); }
}
@media (prefers-reduced-motion: reduce) {
    .tv-slide, .tv-progress { animation: none; }
}
`;

function loadSettings(): Settings {
    try {
        const raw = localStorage.getItem(STORAGE_KEY);

        if (raw) {
            const saved = { ...DEFAULTS, ...JSON.parse(raw) } as Settings;

            return {
                ...saved,
                interval: INTERVALS.includes(saved.interval) ? saved.interval : DEFAULTS.interval,
                rotate: ROTATIONS.includes(saved.rotate) ? saved.rotate : DEFAULTS.rotate,
                slide: DEFAULTS.slide, // human sa matag reload, balik sa auto-rotate
            };
        }
    } catch {
        // storage unavailable (private mode, some TV browsers): use defaults
    }

    return DEFAULTS;
}

/** Real samples received by this display since it opened, at most the last hour. Nothing here is generated. */
function useSessionSeries(data: TvData | null): ChartPoint[] {
    const [points, setPoints] = useState<ChartPoint[]>([]);
    const current = data?.wan.current ?? null;

    useEffect(() => {
        if (!current) {
            return;
        }

        const ts = Date.parse(current.sampled_at);

        if (Number.isNaN(ts)) {
            return;
        }

        setPoints((previous) =>
            previous.length > 0 && previous[previous.length - 1].ts >= ts
                ? previous
                : [...previous, { ts, in: current.in_mbps, out: current.out_mbps }].filter((p) => p.ts > ts - HISTORY_WINDOW_MS).slice(-MAX_POINTS),
        );
    }, [current]);

    return points;
}

export default function TvDisplay({ initial, can_control }: TvPageProps) {
    const [settings, setSettings] = useState<Settings>(loadSettings);
    const [paused, setPaused] = useState(false);
    const [controlsVisible, setControlsVisible] = useState(false);
    const [hintVisible, setHintVisible] = useState(can_control);
    const [fullscreen, setFullscreen] = useState(false);
    const [tick, setTick] = useState(0);

    const { data, lastOkAt, connection, skewMs } = useTvData(initial, settings.interval, paused);
    const connectionStale = useConnectionStale(lastOkAt, settings.interval, paused);
    const session = useSessionSeries(data);

    const update = (patch: Partial<Settings>) => {
        setSettings((previous) => {
            const next = { ...previous, ...patch };

            try {
                localStorage.setItem(STORAGE_KEY, JSON.stringify(next));
            } catch {
                // ignore
            }

            return next;
        });
    };

    // Slides: auto mode alternates Overview and Analytics. Analytics only joins the rotation
    // when there is real analytics data, so an empty slide is never shown on its own.
    const hasAnalytics = Boolean(data?.analytics?.has_data);
    const rotation: SlideId[] = hasAnalytics ? ['overview', 'analytics'] : ['overview'];
    const activeSlide: SlideId = settings.slide === 'auto' ? rotation[tick % rotation.length] : settings.slide;
    const autoRotating = settings.slide === 'auto' && rotation.length > 1 && !paused;

    useEffect(() => {
        if (!autoRotating) {
            return;
        }

        const id = window.setInterval(() => setTick((value) => value + 1), settings.rotate * 1000);

        return () => window.clearInterval(id);
    }, [autoRotating, settings.rotate]);

    // One scale factor for the whole page (see PAGE_CSS). A class on <html> instead of a resize listener.
    useEffect(() => {
        document.documentElement.classList.add('tv-root');

        return () => document.documentElement.classList.remove('tv-root');
    }, []);

    // Keep the screen awake where the browser supports it (silently skipped otherwise).
    useEffect(() => {
        let lock: { release: () => Promise<void> } | null = null;
        const nav = navigator as Navigator & { wakeLock?: { request: (type: 'screen') => Promise<{ release: () => Promise<void> }> } };

        const acquire = async () => {
            try {
                lock = (await nav.wakeLock?.request('screen')) ?? null;
            } catch {
                lock = null;
            }
        };

        const onVisible = () => {
            if (!document.hidden) {
                void acquire();
            }
        };

        void acquire();
        document.addEventListener('visibilitychange', onVisible);

        return () => {
            document.removeEventListener('visibilitychange', onVisible);
            void lock?.release();
        };
    }, []);

    // ---- Controls: a mouse or touch opens them, and so does the OK button of a TV remote ----------------------
    const toolbarRef = useRef<HTMLDivElement>(null);
    const hideTimer = useRef<ReturnType<typeof setTimeout> | undefined>(undefined);
    const focusOnOpen = useRef(false);

    const closeControls = () => {
        clearTimeout(hideTimer.current);
        setControlsVisible(false);
    };

    const armHide = () => {
        clearTimeout(hideTimer.current);
        hideTimer.current = setTimeout(() => setControlsVisible(false), CONTROLS_HIDE_MS);
    };

    // Remote / keyboard.
    //   Controls closed:  OK = open the controls · Left/Right/Up = choose the slide
    //   Controls open:    arrows move the focus (left/right inside a row, up/down between rows) · OK presses the button
    //                     · Back/Escape closes
    useEffect(() => {
        const onKey = (event: KeyboardEvent) => {
            const bar = toolbarRef.current;

            if (bar) {
                armHide();

                if (BACK_KEYS.includes(event.key)) {
                    event.preventDefault();
                    closeControls();

                    return;
                }

                const rows = Array.from(bar.querySelectorAll<HTMLElement>('[data-row]'));
                const active = document.activeElement as HTMLElement | null;
                const rowIndex = rows.findIndex((row) => active !== null && row.contains(active));

                if (event.key === 'ArrowLeft' || event.key === 'ArrowRight') {
                    const buttons = rowIndex < 0 ? [] : Array.from(rows[rowIndex].querySelectorAll<HTMLButtonElement>('button'));
                    const at = active instanceof HTMLButtonElement ? buttons.indexOf(active) : -1;
                    const next = buttons[Math.min(buttons.length - 1, Math.max(0, at + (event.key === 'ArrowRight' ? 1 : -1)))];

                    event.preventDefault();
                    (next ?? bar.querySelector<HTMLButtonElement>('button'))?.focus();
                } else if (event.key === 'ArrowUp' || event.key === 'ArrowDown') {
                    const step = event.key === 'ArrowDown' ? 1 : -1;
                    const from = rowIndex < 0 ? (step > 0 ? -1 : rows.length) : rowIndex;
                    const target = rows[(from + step + rows.length) % rows.length];

                    event.preventDefault();
                    // Land on the value that is on right now, so OK changes it deliberately.
                    (target?.querySelector<HTMLButtonElement>('[aria-pressed="true"]') ?? target?.querySelector<HTMLButtonElement>('button'))?.focus();
                }

                return;
            }

            if (can_control && OK_KEYS.includes(event.key)) {
                event.preventDefault();
                focusOnOpen.current = true;
                setHintVisible(false);
                setControlsVisible(true);
                armHide();

                return;
            }

            if (event.key === 'ArrowRight') {
                update({ slide: 'analytics' });
            } else if (event.key === 'ArrowLeft') {
                update({ slide: 'overview' });
            } else if (event.key === 'ArrowUp') {
                update({ slide: 'auto' });
            }
        };

        window.addEventListener('keydown', onKey);

        return () => window.removeEventListener('keydown', onKey);
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [can_control]);

    // Pointer and touch only. A key press must not pop the controls up while someone is just changing slides.
    useEffect(() => {
        if (!can_control) {
            return;
        }

        const reveal = () => {
            setHintVisible(false);
            setControlsVisible(true);
            armHide();
        };

        const events = ['mousemove', 'pointerdown', 'touchstart'] as const;
        events.forEach((name) => window.addEventListener(name, reveal, { passive: true }));

        return () => {
            events.forEach((name) => window.removeEventListener(name, reveal));
            clearTimeout(hideTimer.current);
        };
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [can_control]);

    // When the remote opened the controls, put the focus ring on the first button.
    useEffect(() => {
        if (controlsVisible && focusOnOpen.current) {
            focusOnOpen.current = false;
            toolbarRef.current?.querySelector<HTMLButtonElement>('button')?.focus();
        }
    }, [controlsVisible]);

    // The "press OK" hint is a reminder, not furniture.
    useEffect(() => {
        if (!hintVisible) {
            return;
        }

        const id = setTimeout(() => setHintVisible(false), 12_000);

        return () => clearTimeout(id);
    }, [hintVisible]);

    useEffect(() => {
        const onChange = () => setFullscreen(Boolean(document.fullscreenElement));

        document.addEventListener('fullscreenchange', onChange);

        return () => document.removeEventListener('fullscreenchange', onChange);
    }, []);

    const toggleFullscreen = async () => {
        try {
            if (document.fullscreenElement) {
                await document.exitFullscreen();
            } else {
                await document.documentElement.requestFullscreen();
            }
        } catch {
            // The browser refused. F11 or a kiosk-mode launch still works.
        }
    };

    const hourPoints = useMemo<ChartPoint[]>(
        () =>
            (data?.history ?? [])
                .map((p) => ({ ts: Date.parse(p.t), in: p.in_mbps, out: p.out_mbps }))
                .filter((p) => !Number.isNaN(p.ts))
                .slice(-MAX_POINTS),
        [data?.history],
    );

    const view = settings.view;
    const visiblePanels = PANELS.filter((panel) => !settings.hidden.includes(panel.id));
    const showChart = view !== 'devices';
    const showBottom = view !== 'traffic' && visiblePanels.length > 0;
    const dim = connectionStale || connection !== 'live';
    const timeZone = data?.timezone ?? 'Asia/Manila';

    const renderSlide = (id: SlideId, current: TvData) => {
        if (id === 'analytics') {
            return <TvAnalytics data={current} dim={dim} />;
        }

        return (
            <>
                <KpiRow data={current} dim={dim} />

                {showChart && (
                    <Glass className={cn('tv-chart flex min-h-0 flex-col p-[1.1rem]', showBottom ? 'flex-4' : 'flex-1')}>
                        <div className="mb-1 text-[1.1rem] font-semibold tracking-wide text-white/70 uppercase">
                            Live bandwidth · {settings.range === 'hour' ? 'Last hour' : 'Current session (since this display opened)'}
                            {dim && <span className="text-amber-300"> · not live</span>}
                        </div>
                        <div className={cn('min-h-0 flex-1', dim && 'opacity-60')}>
                            <TvChart
                                points={settings.range === 'hour' ? hourPoints : session}
                                timeZone={timeZone}
                                capacityMbps={current.capacity_mbps}
                                emptyMessage={
                                    settings.range === 'hour'
                                        ? 'Not enough measurements in the last hour to draw a graph yet.'
                                        : 'Collecting measurements. The graph appears after two samples arrive.'
                                }
                            />
                        </div>
                    </Glass>
                )}

                {showBottom && (
                    <div
                        className={cn('tv-bottom', showChart ? 'flex-5' : 'flex-1')}
                        style={{ '--tv-cols': visiblePanels.length } as React.CSSProperties}
                    >
                        {visiblePanels.map((panel) => {
                            switch (panel.id) {
                                case 'devices':
                                    return <DevicesPanel key={panel.id} data={current} skewMs={skewMs} />;
                                case 'alerts':
                                    return <AlertsPanel key={panel.id} data={current} skewMs={skewMs} />;
                                case 'health':
                                    return <HealthPanel key={panel.id} data={current} connection={connection} skewMs={skewMs} />;
                                case 'events':
                                    return <EventsPanel key={panel.id} data={current} skewMs={skewMs} />;
                            }
                        })}
                    </div>
                )}
            </>
        );
    };

    return (
        <>
            <Head title="TV Display" />
            <style>{PAGE_CSS}</style>

            <div className={cn('tv-stage', !controlsVisible && 'cursor-none')}>
                <TvHeader
                    data={data}
                    lastOkAt={lastOkAt}
                    skewMs={skewMs}
                    campusName={data?.campus_name ?? 'Campus network'}
                    timeZone={timeZone}
                />

                <StatusBanner connection={connection} paused={paused} connectionStale={connectionStale} data={data} />

                {data === null ? (
                    <Glass className="flex flex-1 items-center justify-center p-6 text-center text-[2rem] text-white/70">
                        {connection === 'unauthorized'
                            ? 'This display is not authorized. Waiting for access…'
                            : 'Connecting to CampusNet…'}
                    </Glass>
                ) : (
                    <div className="tv-body">
                        <div key={activeSlide} className="tv-slide">
                            {renderSlide(activeSlide, data)}
                        </div>
                    </div>
                )}

                {/* Which slide is showing. It sits in the bottom padding, so it never shifts the layout. */}
                {data !== null && (
                    <div aria-hidden="true" className="tv-dots pointer-events-none fixed bottom-[0.4rem] left-1/2 flex -translate-x-1/2 items-center gap-5 text-[0.8rem]">
                        {SLIDES.map((slide) => (
                            <span key={slide.id} className={cn('flex items-center gap-1.5', activeSlide === slide.id ? 'text-white' : 'text-white/45')}>
                                <span className={cn('size-2 rounded-full', activeSlide === slide.id ? 'bg-sky-400' : 'bg-white/30')} />
                                {slide.label}
                            </span>
                        ))}
                    </div>
                )}

                {/* Thin line along the bottom edge that fills up until the next slide, one step per second. */}
                {data !== null && autoRotating && (
                    <div
                        key={tick}
                        aria-hidden="true"
                        className="tv-progress pointer-events-none fixed bottom-0 left-0 h-[0.2rem] bg-sky-400/70"
                        style={{ animationDuration: `${settings.rotate}s`, animationTimingFunction: `steps(${settings.rotate}, end)` }}
                    />
                )}

                {can_control && hintVisible && !controlsVisible && (
                    <div role="note" className="pointer-events-none fixed right-4 bottom-6 rounded-full bg-black/60 px-4 py-2 text-[0.95rem] text-white/80">
                        Press OK for display controls
                    </div>
                )}

                {can_control && controlsVisible && (
                    <div
                        ref={toolbarRef}
                        role="toolbar"
                        aria-label="Display controls"
                        className="fixed top-4 right-4 z-50 space-y-3 overflow-y-auto rounded-[1.2rem] border border-white/15 bg-[#0b1220] p-4 text-[1rem] shadow-2xl"
                        style={{ width: 'min(36rem, calc(100vw - 2rem))', maxHeight: 'calc(100vh - 2rem)' }}
                    >
                        <div data-row className="flex gap-2">
                            <Btn onClick={() => void toggleFullscreen()}>{fullscreen ? 'Exit full screen' : 'Full screen'}</Btn>
                            <Btn onClick={() => setPaused((p) => !p)} active={paused}>
                                {paused ? 'Resume refresh' : 'Pause refresh'}
                            </Btn>
                            <Btn onClick={closeControls}>Close</Btn>
                        </div>

                        <Row label="Slide">
                            {SLIDE_CHOICES.map((id) => (
                                <Btn key={id} active={settings.slide === id} onClick={() => update({ slide: id })}>
                                    {id === 'auto' ? 'Auto-rotate' : id === 'overview' ? 'Overview' : 'Analytics'}
                                </Btn>
                            ))}
                        </Row>

                        {settings.slide === 'auto' && (
                            <Row label="Rotate every">
                                {ROTATIONS.map((seconds) => (
                                    <Btn key={seconds} active={settings.rotate === seconds} onClick={() => update({ rotate: seconds })}>
                                        {seconds}s
                                    </Btn>
                                ))}
                            </Row>
                        )}

                        <Row label="Overview layout">
                            {(['overview', 'traffic', 'devices'] as const).map((id) => (
                                <Btn key={id} active={view === id} onClick={() => update({ view: id })}>
                                    {id[0].toUpperCase() + id.slice(1)}
                                </Btn>
                            ))}
                        </Row>

                        <Row label="Graph">
                            <Btn active={settings.range === 'hour'} onClick={() => update({ range: 'hour' })}>
                                Last hour
                            </Btn>
                            <Btn active={settings.range === 'session'} onClick={() => update({ range: 'session' })}>
                                Session
                            </Btn>
                        </Row>

                        <Row label="Refresh">
                            {INTERVALS.map((seconds) => (
                                <Btn key={seconds} active={settings.interval === seconds} onClick={() => update({ interval: seconds })}>
                                    {seconds}s
                                </Btn>
                            ))}
                        </Row>

                        <Row label="Panels">
                            {PANELS.map((panel) => {
                                const hidden = settings.hidden.includes(panel.id);

                                return (
                                    <Btn
                                        key={panel.id}
                                        active={!hidden}
                                        onClick={() =>
                                            update({
                                                hidden: hidden
                                                    ? settings.hidden.filter((id) => id !== panel.id)
                                                    : [...settings.hidden, panel.id],
                                            })
                                        }
                                    >
                                        {panel.label}
                                    </Btn>
                                );
                            })}
                        </Row>
                    </div>
                )}
            </div>
        </>
    );
}

function Row({ label, children }: { label: string; children: React.ReactNode }) {
    return (
        <div data-row>
            <div className="mb-1 text-[0.85rem] tracking-wide text-white/60 uppercase">{label}</div>
            <div className="flex flex-wrap gap-2">{children}</div>
        </div>
    );
}

function Btn({ active, onClick, children }: { active?: boolean; onClick: () => void; children: React.ReactNode }) {
    return (
        <button type="button" onClick={onClick} aria-pressed={active} className="tv-btn">
            {children}
        </button>
    );
}