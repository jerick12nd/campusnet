import { usePage } from '@inertiajs/react';
import { CircleAlert, CircleCheck } from 'lucide-react';

import type { CampusNetShared } from '@/types/campusnet';

/** Success and error messages flashed by the server after an action. */
export function FlashMessages() {
    const flash = usePage<CampusNetShared>().props.campusnet?.flash;

    if (!flash?.success && !flash?.error) {
        return null;
    }

    return (
        <div className="space-y-2">
            {flash.success && (
                <div
                    role="status"
                    className="flex items-start gap-2 rounded-lg border border-emerald-600/30 bg-emerald-50 px-3 py-2 text-sm text-emerald-950 dark:bg-emerald-950/40 dark:text-emerald-100"
                >
                    <CircleCheck className="mt-0.5 size-4 shrink-0" aria-hidden="true" />
                    <span>{flash.success}</span>
                </div>
            )}
            {flash.error && (
                <div
                    role="alert"
                    className="flex items-start gap-2 rounded-lg border border-red-600/30 bg-red-50 px-3 py-2 text-sm text-red-950 dark:bg-red-950/40 dark:text-red-100"
                >
                    <CircleAlert className="mt-0.5 size-4 shrink-0" aria-hidden="true" />
                    <span>{flash.error}</span>
                </div>
            )}
        </div>
    );
}