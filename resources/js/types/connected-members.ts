export type OfficeOption = { id: number; name: string };

export type ConnectionRow = {
    id: number;
    identified: boolean;
    member_id: number | null;
    member_name: string | null;
    office_id: number | null;
    office_name: string | null;
    device_name: string | null;
    device_type: string | null;
    hostname: string | null;
    mac_address: string;
    private_mac: boolean;
    ip_address: string | null;
    interface_name: string | null;
    started_at: string;
    last_seen_at: string;
    status: 'online' | 'offline';
};

export type MemberListRow = {
    id: number;
    full_name: string;
    employee_id: string | null;
    position: string | null;
    office_id: number;
    office_name: string | null;
    is_active: boolean;
    devices_count: number;
    last_seen_at: string | null;
};

export type DeviceListRow = {
    id: number;
    name: string;
    device_type: string;
    device_type_label: string;
    mac_address: string;
    private_mac: boolean;
    notes: string | null;
    office_id: number | null;
    office_name: string | null;
    member_id: number | null;
    member_name: string | null;
    is_active: boolean;
    last_seen_at: string | null;
};

export type AssignmentRow = {
    id: number;
    device_name: string | null;
    device_type: string | null;
    mac_address: string | null;
    assigned_at: string;
    unassigned_at: string | null;
    current: boolean;
};

export function formatBytes(bytes: number): string {
    if (bytes < 1024) {
        return `${bytes} B`;
    }

    const units = ['KB', 'MB', 'GB', 'TB'];
    let value = bytes / 1024;
    let unit = 0;

    while (value >= 1024 && unit < units.length - 1) {
        value /= 1024;
        unit++;
    }

    return `${value.toFixed(value >= 100 ? 0 : 1)} ${units[unit]}`;
}

export function formatDateTime(iso: string | null): string {
    if (!iso) {
        return '\u2014';
    }

    return new Date(iso).toLocaleString();
}