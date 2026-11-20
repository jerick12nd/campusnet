export type WebsiteFilters = {
    search: string;
    office: string;
    type: string;
    member: string;
    from: string;
    to: string;
};

export type WebsiteRecord = {
    id: number;
    domain: string;
    base_domain: string | null;
    observation_type: string;
    observation_label: string;
    office_name: string | null;
    hits: number;
    traffic_bytes: number | null;
    source_name: string | null;
    observed_at: string;
    // Only present for people who may see who did it.
    member_name?: string | null;
    device_name?: string | null;
    client_ip?: string | null;
};

export type TopDomain = { domain: string; hits: number; clients: number };
export type OfficeShare = { office_id: number | null; name: string; hits: number; domains: number };
export type TrendDay = { day: string; hits: number };

export type SourceStatus = {
    id: number;
    name: string;
    type: string;
    type_label: string;
    is_enabled: boolean;
    retention_days: number;
    last_run_at: string | null;
    last_status: string | null;
};

export type SourceRow = {
    id: number;
    name: string;
    type: string;
    description: string | null;
    is_enabled: boolean;
    retention_days: number;
    monitored_device_id: number | null;
    device_name: string | null;
    last_run_at: string | null;
    last_status: string | null;
    last_error: string | null;
};