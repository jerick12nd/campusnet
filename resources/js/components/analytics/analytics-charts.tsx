import { Bar, BarChart, CartesianGrid, Legend, ReferenceLine, ResponsiveContainer, Tooltip, XAxis, YAxis } from 'recharts';

import { formatPercent, formatVolume } from '@/lib/format';

const TOOLTIP_STYLE = {
    backgroundColor: 'var(--popover)',
    color: 'var(--popover-foreground)',
    border: '1px solid var(--border)',
    borderRadius: 8,
    fontSize: 12,
} as const;

const COLORS = {
    utilization: '#3b82f6',
    warning: '#f59e0b',
    inbound: '#3b82f6',
    outbound: '#14b8a6',
} as const;

/** Leaves headroom above the tallest bar, but never less than 20 %, so low usage is still readable. */
const utilizationDomain: [number, (max: number) => number] = [0, (max: number) => Math.max(20, Math.ceil((max * 1.2) / 10) * 10)];

type UtilizationChartProps = {
    data: { label: string; value: number | null }[];
    title: string;
    summary: string;
    warning: number;
    xInterval?: number;
};

/** Average utilization per hour of day or per weekday. A missing bar means no measurement. */
export function UtilizationBars({ data, title, summary, warning, xInterval = 0 }: UtilizationChartProps) {
    return (
        <figure aria-label={title} className="m-0">
            <figcaption className="sr-only">{summary}</figcaption>
            <ResponsiveContainer width="100%" height={240}>
                <BarChart data={data} margin={{ top: 8, right: 16, bottom: 0, left: 0 }}>
                    <CartesianGrid strokeDasharray="3 3" stroke="currentColor" strokeOpacity={0.12} vertical={false} />
                    <XAxis dataKey="label" tick={{ fontSize: 12 }} interval={xInterval} />
                    <YAxis width={48} tick={{ fontSize: 12 }} domain={utilizationDomain} tickFormatter={(value: number) => `${value}%`} />
                    <Tooltip
                        contentStyle={TOOLTIP_STYLE}
                        cursor={{ fill: 'currentColor', fillOpacity: 0.06 }}
                        formatter={(value) => [value === null || value === undefined ? 'No data' : formatPercent(Number(value)), 'Average utilization']}
                    />
                    <ReferenceLine y={warning} stroke={COLORS.warning} strokeDasharray="5 4" ifOverflow="hidden" />
                    <Bar dataKey="value" name="Average utilization" fill={COLORS.utilization} radius={[3, 3, 0, 0]} isAnimationActive={false} />
                </BarChart>
            </ResponsiveContainer>
        </figure>
    );
}

type VolumeChartProps = {
    data: { date: string; in_mb: number; out_mb: number }[];
    summary: string;
};

function formatDay(date: string): string {
    // The date is already the campus-local calendar day, so it is formatted as UTC to stop the browser shifting it.
    return new Intl.DateTimeFormat('en-PH', { month: 'short', day: 'numeric', timeZone: 'UTC' }).format(new Date(`${date}T00:00:00Z`));
}

/** Data moved per campus-local day, in gigabytes. */
export function DailyVolumeChart({ data, summary }: VolumeChartProps) {
    const series = data.map((day) => ({ date: day.date, inbound: day.in_mb / 1000, outbound: day.out_mb / 1000 }));

    return (
        <figure aria-label="Data transferred per day" className="m-0">
            <figcaption className="sr-only">{summary}</figcaption>
            <ResponsiveContainer width="100%" height={260}>
                <BarChart data={series} margin={{ top: 8, right: 16, bottom: 0, left: 0 }}>
                    <CartesianGrid strokeDasharray="3 3" stroke="currentColor" strokeOpacity={0.12} vertical={false} />
                    <XAxis dataKey="date" tick={{ fontSize: 12 }} tickFormatter={formatDay} minTickGap={24} />
                    <YAxis width={64} tick={{ fontSize: 12 }} unit=" GB" />
                    <Tooltip
                        contentStyle={TOOLTIP_STYLE}
                        cursor={{ fill: 'currentColor', fillOpacity: 0.06 }}
                        labelFormatter={(value) => formatDay(String(value))}
                        formatter={(value, name) => [formatVolume(Number(value) * 1000), String(name)]}
                    />
                    <Legend wrapperStyle={{ fontSize: 12 }} />
                    <Bar dataKey="inbound" name="Inbound" fill={COLORS.inbound} radius={[3, 3, 0, 0]} isAnimationActive={false} />
                    <Bar dataKey="outbound" name="Outbound" fill={COLORS.outbound} radius={[3, 3, 0, 0]} isAnimationActive={false} />
                </BarChart>
            </ResponsiveContainer>
        </figure>
    );
}