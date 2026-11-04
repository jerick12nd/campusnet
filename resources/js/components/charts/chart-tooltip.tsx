type TooltipItem = { value?: unknown; payload?: { label?: string } };

type Props = {
    active?: boolean;
    payload?: ReadonlyArray<TooltipItem>;
    valueLabel: string;
    format?: (value: number) => string;
};

/** Small tooltip shared by the CampusNet charts. recharts fills in `active` and `payload`. */
export function ChartTip({ active, payload, valueLabel, format = (value) => value.toFixed(1) }: Props) {
    if (!active || !payload || payload.length === 0) {
        return null;
    }

    const item = payload[0];
    const value = typeof item.value === 'number' ? item.value : null;

    if (value === null) {
        return null;
    }

    return (
        <div className="rounded-lg border bg-popover px-2.5 py-1.5 text-xs text-popover-foreground shadow-md">
            <div className="font-medium">{item.payload?.label}</div>
            <div className="text-muted-foreground">
                {valueLabel}: <span className="font-mono font-medium text-foreground tabular-nums">{format(value)}</span>
            </div>
        </div>
    );
}