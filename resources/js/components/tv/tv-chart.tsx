import { useMemo } from 'react';

import { formatAxisTime } from '@/lib/format';

export type ChartPoint = { ts: number; in: number; out: number };

const IN_COLOR = '#38bdf8';
const OUT_COLOR = '#34d399';
const W = 1000;
const H = 300;

function niceCeil(value: number): number {
    if (value <= 0) {
        return 10;
    }

    const power = 10 ** Math.floor(Math.log10(value));
    const n = value / power;

    return (n <= 1 ? 1 : n <= 2 ? 2 : n <= 5 ? 5 : 10) * power;
}

function axisLabel(mbps: number, yMax: number): string {
    return yMax >= 1000 ? `${+(mbps / 1000).toFixed(2)} Gbps` : `${+mbps.toFixed(1)} Mbps`;
}

export function TvChart({
    points,
    timeZone,
    capacityMbps,
    emptyMessage,
}: {
    points: ChartPoint[];
    timeZone: string;
    capacityMbps: number;
    emptyMessage: string;
}) {
    const model = useMemo(() => {
        if (points.length < 2) {
            return null;
        }

        const minTs = points[0].ts;
        const maxTs = points[points.length - 1].ts;
        const span = Math.max(1, maxTs - minTs);
        const peak = Math.max(...points.map((p) => Math.max(p.in, p.out)));
        const yMax = niceCeil(peak * 1.15);

        const x = (ts: number) => ((ts - minTs) / span) * W;
        const y = (value: number) => H - (value / yMax) * H;
        const line = (key: 'in' | 'out') =>
            points.map((p, i) => `${i === 0 ? 'M' : 'L'}${x(p.ts).toFixed(1)} ${y(p[key]).toFixed(1)}`).join(' ');

        return {
            yMax,
            inLine: line('in'),
            outLine: line('out'),
            inArea: `${line('in')} L${W} ${H} L0 ${H} Z`,
            capacityY: capacityMbps > 0 && capacityMbps <= yMax ? y(capacityMbps) : null,
            xTicks: [0, 1, 2, 3, 4].map((i) => minTs + (span * i) / 4),
            span,
            minTs,
        };
    }, [points, capacityMbps]);

    if (model === null) {
        return (
            <div className="flex h-full items-center justify-center text-[1.4rem] text-white/60">{emptyMessage}</div>
        );
    }

    return (
        <div className="relative flex h-full min-h-0 flex-col">
            <div className="absolute top-0 right-0 z-10 flex gap-6 text-[1.1rem] text-white/80">
                <span className="flex items-center gap-2">
                    <span className="inline-block h-[0.3rem] w-[2rem] rounded" style={{ background: IN_COLOR }} />
                    Download (in)
                </span>
                <span className="flex items-center gap-2">
                    <span
                        className="inline-block h-0 w-[2rem] border-t-[0.3rem] border-dashed"
                        style={{ borderColor: OUT_COLOR }}
                    />
                    Upload (out)
                </span>
                {model.capacityY !== null && (
                    <span className="flex items-center gap-2 text-amber-300">
                        <span className="inline-block h-0 w-[2rem] border-t-[0.15rem] border-dotted border-amber-300" />
                        Capacity
                    </span>
                )}
            </div>

            <div className="flex min-h-0 flex-1 pt-[2rem]">
                <div className="relative w-[7rem] shrink-0">
                    {[0, 1, 2, 3, 4].map((i) => (
                        <span
                            key={i}
                            className="absolute right-3 -translate-y-1/2 text-[1rem] whitespace-nowrap text-white/60 tabular-nums"
                            style={{ top: `${100 - i * 25}%` }}
                        >
                            {axisLabel((model.yMax * i) / 4, model.yMax)}
                        </span>
                    ))}
                </div>

                <div className="relative min-h-0 flex-1">
                    <svg
                        viewBox={`0 0 ${W} ${H}`}
                        preserveAspectRatio="none"
                        className="absolute inset-0 h-full w-full"
                        role="img"
                        aria-label="Inbound and outbound traffic over time"
                    >
                        <defs>
                            <linearGradient id="tvIn" x1="0" y1="0" x2="0" y2="1">
                                <stop offset="0%" stopColor={IN_COLOR} stopOpacity="0.30" />
                                <stop offset="100%" stopColor={IN_COLOR} stopOpacity="0" />
                            </linearGradient>
                        </defs>

                        {[0, 1, 2, 3, 4].map((i) => (
                            <line
                                key={i}
                                x1="0"
                                x2={W}
                                y1={(H * i) / 4}
                                y2={(H * i) / 4}
                                stroke="rgba(255,255,255,0.10)"
                                vectorEffect="non-scaling-stroke"
                            />
                        ))}

                        {model.capacityY !== null && (
                            <line
                                x1="0"
                                x2={W}
                                y1={model.capacityY}
                                y2={model.capacityY}
                                stroke="#fcd34d"
                                strokeDasharray="2 6"
                                vectorEffect="non-scaling-stroke"
                                style={{ strokeWidth: '0.15rem' }}
                            />
                        )}

                        <path d={model.inArea} fill="url(#tvIn)" />
                        <path
                            d={model.outLine}
                            fill="none"
                            stroke={OUT_COLOR}
                            strokeDasharray="10 6"
                            strokeLinejoin="round"
                            vectorEffect="non-scaling-stroke"
                            style={{ strokeWidth: '0.22rem' }}
                        />
                        <path
                            d={model.inLine}
                            fill="none"
                            stroke={IN_COLOR}
                            strokeLinejoin="round"
                            vectorEffect="non-scaling-stroke"
                            style={{ strokeWidth: '0.26rem' }}
                        />
                    </svg>
                </div>
            </div>

            <div className="relative ml-[7rem] h-[2rem] shrink-0">
                {model.xTicks.map((ts, i) => (
                    <span
                        key={i}
                        className="absolute top-1 text-[1rem] whitespace-nowrap text-white/60 tabular-nums"
                        style={{
                            left: `${i * 25}%`,
                            transform: i === 0 ? 'none' : i === 4 ? 'translateX(-100%)' : 'translateX(-50%)',
                        }}
                    >
                        {formatAxisTime(ts, model.span > 86_400_000 ? '7d' : '1h', timeZone)}
                    </span>
                ))}
            </div>
        </div>
    );
}