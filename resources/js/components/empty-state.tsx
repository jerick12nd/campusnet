import type { LucideIcon } from 'lucide-react';
import type { ReactNode } from 'react';

type Props = {
    icon: LucideIcon;
    title: string;
    description?: string;
    children?: ReactNode;
};

export function EmptyState({ icon: Icon, title, description, children }: Props) {
    return (
        <div className="flex flex-col items-center justify-center gap-2 rounded-lg border border-dashed px-6 py-10 text-center">
            <Icon className="size-8 text-muted-foreground" aria-hidden="true" />
            <h3 className="text-sm font-semibold">{title}</h3>
            {description && <p className="max-w-md text-sm text-muted-foreground">{description}</p>}
            {children && <div className="mt-2">{children}</div>}
        </div>
    );
}