export type Severity = 'info' | 'warning' | 'critical';
export type DeviceState = 'online' | 'unreachable' | 'unknown';
export type CollectorState = 'ok' | 'no_devices' | 'no_data' | 'stale' | 'misconfigured';
export type OverallState = 'operational' | 'degraded' | 'critical' | 'unknown';

export type TvDevice = {
    id: number;
    name: string;
    type: string;
    location: string | null;
    status: DeviceState;
    last_successful_poll_at: string | null;
};

export type TvAlert = {
    id: number;
    title: string;
    severity: Severity;
    status: 'active' | 'acknowledged' | 'resolved';
    type: string;
    device: string | null;
    triggered_at: string;
    resolved_at: string | null;
};

export type TvData = {
    generated_at: string;
    campus_name: string;
    timezone: string;
    overall: OverallState;
    mode: { demo: boolean; driver: string };
    capacity_mbps: number;
    thresholds: { warning: number; critical: number };
    collector: { status: CollectorState; message: string | null };
    latest_measurement_at: string | null;
    wan: {
        configured: boolean;
        current: {
            in_mbps: number;
            out_mbps: number;
            utilization_percent: number | null;
            sampled_at: string;
            stale: boolean;
            interfaces_reporting: number;
            interfaces_total: number;
        } | null;
    };
    summary: {
        avg_in_mbps: number;
        avg_out_mbps: number;
        peak_in_mbps: number;
        peak_out_mbps: number;
        avg_utilization_percent: number | null;
        peak_utilization_percent: number | null;
    } | null;
    history: { t: string; in_mbps: number; out_mbps: number }[];
    devices: {
        total: number;
        online: number;
        unreachable: number;
        unknown: number;
        disabled: number;
        list: TvDevice[];
    };
    alerts: {
        active: number;
        critical: number;
        warning: number;
        unreachable: number;
        collector_failures: number;
        list: TvAlert[];
    };
    events: TvAlert[];
};

export type TvPageProps = {
    initial: TvData | null;
    can_control: boolean;
};