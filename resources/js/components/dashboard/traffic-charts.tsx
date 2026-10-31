import { useMemo } from 'react';
import { Area, AreaChart, CartesianGrid, Line, LineChart, ReferenceLine, ResponsiveContainer, Tooltip, XAxis, YAxis } from 'recharts';

import { formatAxisTime, formatDateTime, formatMbps, formatPercent } from '@/lib/format';
import type { DashboardPoint } from '@/types/campusnet';

type CommonProps = {
    points: DashboardPoint[];
    range: string;
    timeZone: string;
};

const TOOLTIP_STYLE = {
    backgroundColor: 'var(--popover)',
    color: 'var(--popover-foreground)',
    border: '1px solid var(--border)',
    borderRadius: 8,
    fontSize: 12,
} as const;

const COLORS = {
    average: '#3b82f6',
    peak: '#8b5cf6',
    warning: '#f59e0b',
    critical: '#ef4444',
    inbound: '#3b82f6',
    outbound: '#14b8a6',
} as const;

function useSeries(points: DashboardPoint[]) {
    return useMemo(() => points.map((point) => ({ ...point, ts: Date.parse(point.t) })), [points]);
}

export function UtilizationChart({
    points,
    range,
    timeZone,
    thresholds,
    summary,
}: CommonProps & {
    thresholds: { warning: number; critical: number };
    summary: string;
}) {
    const series = useSeries(points);

    return (
        <figure aria-label="Bandwidth utilization over time" className="m-0">
            <figcaption className="sr-only">{summary}</figcaption>
            <ResponsiveContainer width="100%" height={260}>
                <LineChart data={series} margin={{ top: 8, right: 16, bottom: 0, left: 0 }}>
                    <CartesianGrid strokeDasharray="3 3" stroke="currentColor" strokeOpacity={0.12} />
                    <XAxis
                        dataKey="ts"
                        type="number"
                        scale="time"
                        domain={['dataMin', 'dataMax']}
                        tickFormatter={(value: number) => formatAxisTime(value, range, timeZone)}
                        tick={{ fontSize: 12 }}
                        minTickGap={32}
                    />
                    <YAxis
                        width={48}
                        tick={{ fontSize: 12 }}
                        domain={[0, (dataMax: number) => Math.max(100, Math.ceil(dataMax / 10) * 10)]}
                        tickFormatter={(value: number) => `${value}%`}
                    />
                    <Tooltip
                        contentStyle={TOOLTIP_STYLE}
                        labelFormatter={(value) => formatDateTime(new Date(Number(value)).toISOString(), timeZone)}
                        formatter={(value, name) => [formatPercent(Number(value)), String(name)]}
                    />
                    <ReferenceLine
                        y={thresholds.warning}
                        stroke={COLORS.warning}
                        strokeDasharray="5 4"
                        label={{ value: `Warning ${thresholds.warning}%`, position: 'insideTopLeft', fontSize: 11 }}
                    />
                    <ReferenceLine
                        y={thresholds.critical}
                        stroke={COLORS.critical}
                        strokeDasharray="5 4"
                        label={{ value: `Critical ${thresholds.critical}%`, position: 'insideTopLeft', fontSize: 11 }}
                    />
                    <Line
                        type="monotone"
                        dataKey="utilization_percent"
                        name="Average"
                        stroke={COLORS.average}
                        strokeWidth={2}
                        dot={false}
                        isAnimationActive={false}
                    />
                    <Line
                        type="monotone"
                        dataKey="peak_utilization_percent"
                        name="Peak"
                        stroke={COLORS.peak}
                        strokeWidth={1.5}
                        strokeDasharray="2 3"
                        dot={false}
                        isAnimationActive={false}
                    />
                </LineChart>
            </ResponsiveContainer>
        </figure>
    );
}

export function TrafficChart({ points, range, timeZone, summary }: CommonProps & { summary: string }) {
    const series = useSeries(points);

    return (
        <figure aria-label="Inbound and outbound traffic over time" className="m-0">
            <figcaption className="sr-only">{summary}</figcaption>
            <ResponsiveContainer width="100%" height={260}>
                <AreaChart data={series} margin={{ top: 8, right: 16, bottom: 0, left: 0 }}>
                    <CartesianGrid strokeDasharray="3 3" stroke="currentColor" strokeOpacity={0.12} />
                    <XAxis
                        dataKey="ts"
                        type="number"
                        scale="time"
                        domain={['dataMin', 'dataMax']}
                        tickFormatter={(value: number) => formatAxisTime(value, range, timeZone)}
                        tick={{ fontSize: 12 }}
                        minTickGap={32}
                    />
                    <YAxis width={64} tick={{ fontSize: 12 }} unit=" Mbps" />
                    <Tooltip
                        contentStyle={TOOLTIP_STYLE}
                        labelFormatter={(value) => formatDateTime(new Date(Number(value)).toISOString(), timeZone)}
                        formatter={(value, name) => [formatMbps(Number(value)), String(name)]}
                    />
                    <Area
                        type="monotone"
                        dataKey="in_mbps"
                        name="Inbound (avg)"
                        stroke={COLORS.inbound}
                        fill={COLORS.inbound}
                        fillOpacity={0.18}
                        strokeWidth={2}
                        isAnimationActive={false}
                    />
                    <Area
                        type="monotone"
                        dataKey="out_mbps"
                        name="Outbound (avg)"
                        stroke={COLORS.outbound}
                        fill={COLORS.outbound}
                        fillOpacity={0.18}
                        strokeWidth={2}
                        isAnimationActive={false}
                    />
                </AreaChart>
            </ResponsiveContainer>
        </figure>
    );
}