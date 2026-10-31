import { Head } from '@inertiajs/react';

import { DeviceForm } from '@/components/devices/device-form';
import AppLayout from '@/layouts/app-layout';
import type { Option } from '@/types/campusnet';

type Props = {
    options: { types: Option[]; versions: Option[] };
    defaults: { device_type: string; snmp_version: string; snmp_port: number; polling_interval_seconds: number };
};

export default function CreateDevice({ options, defaults }: Props) {
    return (
        <AppLayout
            breadcrumbs={[
                { title: 'Network Devices', href: '/devices' },
                { title: 'Add device', href: '/devices/create' },
            ]}
        >
            <Head title="Add device" />

            <div className="flex flex-col gap-4 p-4">
                <div>
                    <h1 className="text-xl font-semibold">Add a network device</h1>
                    <p className="text-sm text-muted-foreground">
                        Only devices you add here are monitored. CampusNet never scans address ranges or discovers devices on its own.
                    </p>
                </div>

                <DeviceForm
                    mode="create"
                    options={options}
                    initial={{
                        name: '',
                        management_ip: '',
                        vendor: '',
                        model: '',
                        device_type: defaults.device_type,
                        location: '',
                        description: '',
                        is_enabled: true,
                        snmp_version: defaults.snmp_version,
                        snmp_port: defaults.snmp_port,
                        polling_interval_seconds: defaults.polling_interval_seconds,
                    }}
                />
            </div>
        </AppLayout>
    );
}