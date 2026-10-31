import { usePage } from '@inertiajs/react';
import { FlaskConical } from 'lucide-react';

type CampusNetShared = {
    campusnet?: {
        demo_mode: boolean;
        driver: string;
        campus_name: string;
    };
};

/**
 * Shown on every page while the mock driver is active, so simulated
 * measurements can never be mistaken for real campus traffic.
 */
export function DemoModeBanner() {
    const { campusnet } = usePage<CampusNetShared>().props;

    if (!campusnet?.demo_mode) {
        return null;
    }

    return (
        <div
            role="status"
            className="flex items-center justify-center gap-2 bg-amber-500 px-3 py-1.5 text-xs font-semibold tracking-wide text-amber-950"
        >
            <FlaskConical className="size-3.5 shrink-0" aria-hidden="true" />
            <span>DEMO MODE: all traffic values are simulated, not collected from real equipment.</span>
        </div>
    );
}