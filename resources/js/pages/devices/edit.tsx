import { Head } from '@inertiajs/react';

import { DeviceForm } from '@/components/devices/device-form';
import type { DeviceFormInitial } from '@/components/devices/device-form';
import AppLayout from '@/layouts/app-layout';
import type { Option } from '@/types/campusnet';

type Props = {
    device: DeviceFormInitial & { id: number; has_credentials: boolean };
    options: { types: Option[]; versions: Option[] };
};

export default function EditDevice({ device, options }: Props) {
    const { id, has_credentials: hasCredentials, ...initial } = device;

    return (
        <AppLayout
            breadcrumbs={[
                { title: 'Network Devices', href: '/devices' },
                { title: device.name, href: `/devices/${id}` },
                { title: 'Edit', href: `/devices/${id}/edit` },
            ]}
        >
            <Head title={`Edit ${device.name}`} />

            <div className="flex flex-col gap-4 p-4">
                <h1 className="text-xl font-semibold">Edit {device.name}</h1>

                <DeviceForm mode="edit" deviceId={id} initial={initial} hasCredentials={hasCredentials} options={options} />
            </div>
        </AppLayout>
    );
}