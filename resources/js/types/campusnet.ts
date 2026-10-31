export type Option = { value: string; label: string };

export type DeviceStatusValue = 'unknown' | 'online' | 'unreachable';

export type ConnectionTestResult = {
    success: boolean;
    status: DeviceStatusValue | null;
    message: string;
    latency_ms: number | null;
    details: Record<string, string | number | null>;
    simulated: boolean;
    device_id: number;
    tested_at: string;
};

export type FlashProps = {
    success?: string | null;
    error?: string | null;
    connection_test?: ConnectionTestResult | null;
};

/** Props the server shares with every page (HandleInertiaRequests). */
export type CampusNetShared = {
    campusnet?: {
        demo_mode: boolean;
        driver: string;
        campus_name: string;
        timezone: string;
        flash?: FlashProps;
    };
    access?: {
        role: string | null;
        role_label: string | null;
        permissions: string[];
    };
};

export type Paginated<T> = {
    data: T[];
    current_page: number;
    last_page: number;
    from: number | null;
    to: number | null;
    total: number;
    per_page: number;
    prev_page_url: string | null;
    next_page_url: string | null;
};

export type DeviceSummary = {
    id: number;
    name: string;
    management_ip: string;
    vendor: string | null;
    model: string | null;
    device_type: Option;
    location: string | null;
    is_enabled: boolean;
    is_demo: boolean;
    last_status: Option & { value: DeviceStatusValue };
    last_successful_poll_at: string | null;
    interfaces_count: number;
    open_alerts_count: number;
};

export type DeviceDetail = DeviceSummary & {
    description: string | null;
    snmp_version: Option;
    snmp_port: number;
    polling_interval_seconds: number;
    last_polled_at: string | null;
    last_error: string | null;
    has_credentials: boolean;
    created_at: string | null;
    updated_at: string | null;
};

export type DeviceFilters = {
    search: string;
    status: string;
    type: string;
    location: string;
    enabled: string;
    sort: string;
    direction: 'asc' | 'desc';
    per_page: number;
};

export type DeviceOptions = {
    types: Option[];
    versions: Option[];
    statuses: Option[];
};

export type InterfaceRow = {
    id: number;
    if_index: number;
    name: string;
    role: Option;
    oper_status: string | null;
    is_monitored: boolean;
};

export type AlertRow = {
    id: number;
    title: string;
    severity: 'info' | 'warning' | 'critical';
    status: 'active' | 'acknowledged' | 'resolved';
    triggered_at: string;
};

export type DashboardPoint = {
    t: string;
    in_mbps: number;
    out_mbps: number;
    peak_in_mbps: number;
    peak_out_mbps: number;
    utilization_percent: number | null;
    peak_utilization_percent: number | null;
};

export type DashboardData = {
    range: string;
    ranges: string[];
    mode: { demo: boolean; driver: string };
    capacity_mbps: number;
    thresholds: { warning: number; critical: number };
    refresh_seconds: number;
    generated_at: string;
    collector: { status: 'ok' | 'no_devices' | 'no_data' | 'stale' | 'misconfigured'; message: string | null };
    latest_measurement_at: string | null;
    wan: {
        configured: boolean;
        interfaces: { id: number; name: string; device: string | null }[];
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
    devices: { total: number; online: number; unreachable: number; unknown: number; disabled: number };
    alerts: { active: number; critical: number };
    history: DashboardPoint[];
    summary: {
        avg_utilization_percent: number | null;
        peak_utilization_percent: number | null;
        avg_in_mbps: number;
        avg_out_mbps: number;
        peak_in_mbps: number;
        peak_out_mbps: number;
    } | null;
    top_interfaces: {
        id: number;
        name: string;
        device: string | null;
        role: string | null;
        avg_in_mbps: number;
        avg_out_mbps: number;
        avg_total_mbps: number;
    }[];
    incidents: {
        id: number;
        title: string;
        severity: 'info' | 'warning' | 'critical';
        status: 'active' | 'acknowledged' | 'resolved';
        device: string | null;
        triggered_at: string;
        resolved_at: string | null;
    }[];
};