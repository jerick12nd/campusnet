import { CircleCheck, CircleHelp, CircleMinus } from 'lucide-react';

import { Pill } from '@/components/status-badge';
import type { OfficeStatus } from '@/types/office-network';

const CONFIG = {
    active: { label: 'Devices online', tone: 'green', Icon: CircleCheck },
    idle: { label: 'No devices online', tone: 'gray', Icon: CircleMinus },
    no_devices: { label: 'No registered devices', tone: 'gray', Icon: CircleHelp },
    no_source: { label: 'No data source', tone: 'gray', Icon: CircleHelp },
} as const;

/** Status is always an icon plus text, never colour alone. */
export function OfficeStatusPill({ status }: { status: OfficeStatus }) {
    const { label, tone, Icon } = CONFIG[status];

    return (
        <Pill tone={tone}>
            <Icon className="size-3" aria-hidden="true" />
            {label}
        </Pill>
    );
}

export const OFFICE_STATUS_OPTIONS = (Object.keys(CONFIG) as OfficeStatus[]).map((value) => ({
    value,
    label: CONFIG[value].label,
}));