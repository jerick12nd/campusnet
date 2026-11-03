import { Head } from '@inertiajs/react';
import { useEffect, useMemo, useRef, useState } from 'react';

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
type Settings = { view: ViewId; interval: number; range: 'hour' | 'session'; hidden: PanelId[] };

const INTERVALS = [3, 5, 10, 15, 30, 60]; // safe limits: never faster than 3 s
const DEFAULTS: Settings = { view: 'overview', interval: 5, range: 'hour', hidden: [] };
const STORAGE_KEY = 'campusnet.tv.settings';

function loadSettings(): Settings {
    try {
        const raw = localStorage.getItem(STORAGE_KEY);

        if (raw) {
            const saved = { ...DEFAULTS, ...JSON.parse(raw) } as Settings;

            return { ...saved, interval: INTERVALS.includes(saved.interval) ? saved.interval : DEFAULTS.interval };
        }
    } catch {
        // storage unavailable (private mode, some TV browsers): use defaults
    }

    return DEFAULTS;
}

/** Real samples received by this display since it opened. Nothing here is generated. */
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
                : [...previous, { ts, in: current.in_mbps, out: current.out_mbps }].slice(-720),
        );
    }, [current]);

    return points;
}

export default function TvDisplay({ initial, can_control }: TvPageProps) {
    const [settings, setSettings] = useState<Settings>(loadSettings);
    const [paused, setPaused] = useState(false);
    const [controlsVisible, setControlsVisible] = useState(false);
    const [fullscreen, setFullscreen] = useState(false);

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

    // Scale everything with the screen: 1920x1080 -> 16px, 3840x2160 -> 32px. Tailwind rem classes follow.
    useEffect(() => {
        const apply = () => {
            const px = Math.max(10, Math.min(window.innerWidth / 120, window.innerHeight / 67.5));
            document.documentElement.style.fontSize = `${px}px`;
        };

        apply();
        window.addEventListener('resize', apply);

        return () => {
            window.removeEventListener('resize', apply);
            document.documentElement.style.fontSize = '';
        };
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

    // Control overlay: only for signed-in users, shown on interaction, hidden again after 6 s.
    const hideTimer = useRef<ReturnType<typeof setTimeout> | undefined>(undefined);

    useEffect(() => {
        if (!can_control) {
            return;
        }

        const reveal = () => {
            setControlsVisible(true);
            clearTimeout(hideTimer.current);
            hideTimer.current = setTimeout(() => setControlsVisible(false), 6000);
        };

        const events = ['mousemove', 'keydown', 'pointerdown', 'touchstart'] as const;
        events.forEach((name) => window.addEventListener(name, reveal, { passive: true }));

        return () => {
            events.forEach((name) => window.removeEventListener(name, reveal));
            clearTimeout(hideTimer.current);
        };
    }, [can_control]);

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
        () => (data?.history ?? []).map((p) => ({ ts: Date.parse(p.t), in: p.in_mbps, out: p.out_mbps })),
        [data?.history],
    );

    const view = settings.view;
    const visiblePanels = PANELS.filter((panel) => !settings.hidden.includes(panel.id));
    const showChart = view !== 'devices';
    const showBottom = view !== 'traffic' && visiblePanels.length > 0;
    const dim = connectionStale || connection !== 'live';
    const timeZone = data?.timezone ?? 'Asia/Manila';

    return (
        <>
            <Head title="TV Display" />

            <div
                className={cn('fixed inset-0 flex flex-col gap-4 overflow-hidden bg-[#060a13] p-6 text-white', !controlsVisible && 'cursor-none')}
                style={{
                    fontFamily: 'system-ui, -apple-system, "Segoe UI", Roboto, sans-serif',
                    backgroundImage:
                        'radial-gradient(60rem 30rem at 8% -8%, rgba(56,189,248,0.16), transparent), radial-gradient(50rem 30rem at 100% 110%, rgba(52,211,153,0.10), transparent)',
                }}
            >
                <TvHeader
                    data={data}
                    lastOkAt={lastOkAt}
                    skewMs={skewMs}
                    campusName={data?.campus_name ?? 'Campus network'}
                    timeZone={timeZone}
                />

                <StatusBanner connection={connection} paused={paused} connectionStale={connectionStale} data={data} />

                {data === null ? (
                    <Glass className="flex flex-1 items-center justify-center text-[2rem] text-white/70">
                        {connection === 'unauthorized'
                            ? 'This display is not authorized. Waiting for access…'
                            : 'Connecting to CampusNet…'}
                    </Glass>
                ) : (
                    <>
                        <KpiRow data={data} dim={dim} />

                        {showChart && (
                            <Glass className={cn('flex min-h-0 flex-col p-5', showBottom ? 'flex-[5]' : 'flex-1')}>
                                <div className="mb-1 text-[1.15rem] font-semibold tracking-wide text-white/70 uppercase">
                                    Live bandwidth ·{' '}
                                    {settings.range === 'hour' ? 'Last hour' : 'Current session (since this display opened)'}
                                </div>
                                <div className={cn('min-h-0 flex-1', dim && 'opacity-60')}>
                                    <TvChart
                                        points={settings.range === 'hour' ? hourPoints : session}
                                        timeZone={timeZone}
                                        capacityMbps={data.capacity_mbps}
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
                                className={cn('grid min-h-0 gap-4', showChart ? 'flex-[4]' : 'flex-1')}
                                style={{ gridTemplateColumns: `repeat(${visiblePanels.length}, minmax(0, 1fr))` }}
                            >
                                {visiblePanels.map((panel) => {
                                    switch (panel.id) {
                                        case 'devices':
                                            return <DevicesPanel key={panel.id} data={data} skewMs={skewMs} />;
                                        case 'alerts':
                                            return <AlertsPanel key={panel.id} data={data} skewMs={skewMs} />;
                                        case 'health':
                                            return <HealthPanel key={panel.id} data={data} connection={connection} skewMs={skewMs} />;
                                        case 'events':
                                            return <EventsPanel key={panel.id} data={data} skewMs={skewMs} />;
                                    }
                                })}
                            </div>
                        )}
                    </>
                )}

                {can_control && controlsVisible && (
                    <div
                        role="toolbar"
                        aria-label="Display controls"
                        className="fixed top-4 right-4 z-50 w-[26rem] space-y-3 rounded-[1.2rem] border border-white/15 bg-[#0b1220]/95 p-4 text-[1rem] shadow-2xl"
                    >
                        <div className="flex gap-2">
                            <Btn onClick={() => void toggleFullscreen()}>{fullscreen ? 'Exit full screen' : 'Full screen'}</Btn>
                            <Btn onClick={() => setPaused((p) => !p)} active={paused}>
                                {paused ? 'Resume refresh' : 'Pause refresh'}
                            </Btn>
                        </div>

                        <Row label="View">
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
        <div>
            <div className="mb-1 text-[0.85rem] tracking-wide text-white/50 uppercase">{label}</div>
            <div className="flex flex-wrap gap-2">{children}</div>
        </div>
    );
}

function Btn({ active, onClick, children }: { active?: boolean; onClick: () => void; children: React.ReactNode }) {
    return (
        <button
            type="button"
            onClick={onClick}
            aria-pressed={active}
            className={cn(
                'rounded-lg px-3 py-1.5 font-medium ring-1 ring-inset',
                active ? 'bg-sky-500/30 text-white ring-sky-400/70' : 'bg-white/5 text-white/80 ring-white/15 hover:bg-white/10',
            )}
        >
            {children}
        </button>
    );
}