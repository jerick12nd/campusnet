export type OfficeStatus = 'active' | 'idle' | 'no_devices' | 'no_source';

export type OfficeRow = {
    id: number;
    name: string;
    code: string | null;
    department: string | null;
    subnet_cidr: string | null;
    description: string | null;
    members: number;
    registered_devices: number;
    online_devices: number;
    offline_devices: number;
    status: OfficeStatus;
    last_seen_at: string | null;
    rx_bytes: number | null;
    tx_bytes: number | null;
};

export type OfficeSource = {
    enabled: boolean;
    last_run_at: string | null;
    stale: boolean;
    failing: boolean;
};

export type OfficeTotals = {
    offices: number;
    online_devices: number;
    registered_devices: number;
    unidentified_online: number;
};

export type SessionRow = {
    id: number;
    device_name: string | null;
    device_type: string | null;
    hostname: string | null;
    member_name: string | null;
    ip_address: string | null;
    mac_address: string;
    interface_name: string | null;
    started_at: string;
    last_seen_at: string;
    status: 'online' | 'offline';
};