import { CartesianGrid, LabelList, Line, LineChart, ResponsiveContainer, Tooltip, XAxis } from 'recharts';

import { ChartTip } from './chart-tooltip';

export type ChartPoint = { label: string; value: number | null };

type Props = {
    title: string;
    description?: string;
    /** Name of the measured value, e.g. "Average utilization (%)". Shown in the tooltip. */
    valueLabel: string;
    data: ChartPoint[];
    /** Formats the number shown above each point and in the tooltip. */
    format?: (value: number) => string;
    footer?: string;
    color?: string;
};

export function TrafficLineChart({ title, description, valueLabel, data, format = (value) => value.toFixed(1), footer, color = '#4aa8f0' }: Props) {
    const hasData = data.some((point) => point.value !== null);
    // Labels on every point only stay readable when there are few points.
    const showLabels = data.length <= 12;

    return (
        <section aria-label={title} className="rounded-xl border bg-card p-4 text-card-foreground shadow-xs">
            <h3 className="text-sm font-medium">{title}</h3>
            {description && <p className="text-sm text-muted-foreground">{description}</p>}

            {hasData ? (
                <div className="mt-4 h-64 w-full">
                    <ResponsiveContainer width="100%" height="100%">
                        <LineChart data={data} margin={{ top: 24, left: 24, right: 24, bottom: 0 }}>
                            <CartesianGrid vertical={false} stroke="var(--border)" />
                            <XAxis
                                dataKey="label"
                                tickLine={false}
                                axisLine={false}
                                tickMargin={8}
                                minTickGap={16}
                                tick={{ fill: 'var(--muted-foreground)', fontSize: 12 }}
                            />
                            <Tooltip cursor={false} content={<ChartTip valueLabel={valueLabel} format={format} />} />
                            <Line
                                dataKey="value"
                                type="monotone"
                                stroke={color}
                                strokeWidth={2}
                                connectNulls={false}
                                dot={{ r: 3, fill: color, stroke: color }}
                                activeDot={{ r: 6 }}
                                isAnimationActive={false}
                            >
                                {showLabels && (
                                    <LabelList
                                        dataKey="value"
                                        position="top"
                                        offset={12}
                                        className="fill-foreground"
                                        fontSize={12}
                                        formatter={(value: unknown) => (typeof value === 'number' ? format(value) : '')}
                                    />
                                )}
                            </Line>
                        </LineChart>
                    </ResponsiveContainer>
                </div>
            ) : (
                <p className="py-12 text-center text-sm text-muted-foreground">No measurements for this period.</p>
            )}

            {footer && <p className="mt-2 text-xs text-muted-foreground">{footer}</p>}
        </section>
    );
}