import { PolarAngleAxis, PolarGrid, Radar, RadarChart, ResponsiveContainer, Tooltip } from 'recharts';

import { ChartTip } from './chart-tooltip';
import type { ChartPoint } from './traffic-line-chart';

type Props = {
    title: string;
    description?: string;
    valueLabel: string;
    data: ChartPoint[];
    /** Show only every Nth axis label so many axes (e.g. 24 hours) stay readable. */
    labelEvery?: number;
    format?: (value: number) => string;
    footer?: string;
    color?: string;
};

export function UsageRadarChart({ title, description, valueLabel, data, labelEvery = 1, format, footer, color = '#4aa8f0' }: Props) {
    const points = data.map((point) => ({ label: point.label, value: point.value ?? 0 }));
    // A radar needs at least three axes to look like anything.
    const usable = data.filter((point) => point.value !== null).length >= 3;

    return (
        <section aria-label={title} className="rounded-xl border bg-card p-4 text-card-foreground shadow-xs">
            <h3 className="text-center text-sm font-medium">{title}</h3>
            {description && <p className="text-center text-sm text-muted-foreground">{description}</p>}

            {usable ? (
                <div className="mx-auto mt-2 h-75 w-full max-w-95">
                    <ResponsiveContainer width="100%" height="100%">
                        <RadarChart data={points}>
                            <Tooltip cursor={false} content={<ChartTip valueLabel={valueLabel} format={format} />} />
                            <PolarAngleAxis
                                dataKey="label"
                                tick={{ fill: 'var(--muted-foreground)', fontSize: 12 }}
                                tickFormatter={(value: string, index: number) => (index % labelEvery === 0 ? value : '')}
                            />
                            <PolarGrid stroke="var(--border)" />
                            <Radar
                                dataKey="value"
                                stroke={color}
                                fill={color}
                                fillOpacity={0.45}
                                dot={{ r: 4, fillOpacity: 1 }}
                                isAnimationActive={false}
                            />
                        </RadarChart>
                    </ResponsiveContainer>
                </div>
            ) : (
                <p className="py-12 text-center text-sm text-muted-foreground">Not enough data for this chart yet.</p>
            )}

            {footer && <p className="mt-2 text-center text-xs text-muted-foreground">{footer}</p>}
        </section>
    );
}