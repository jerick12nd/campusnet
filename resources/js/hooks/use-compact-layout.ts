import { useEffect, useState } from 'react';

/**
 * "Compact" = a phone, a tablet held upright, or any window that is not clearly landscape.
 * There the wall layout (everything visible at once, no scrolling) would be unreadable, so the
 * page stacks its panels and scrolls instead. The CSS in tv-display.tsx uses the same query.
 */
export const COMPACT_QUERY = '(max-width: 899px), (max-aspect-ratio: 11/10)';

export function useCompactLayout(): boolean {
    const [compact, setCompact] = useState(() => typeof window !== 'undefined' && window.matchMedia(COMPACT_QUERY).matches);

    useEffect(() => {
        const query = window.matchMedia(COMPACT_QUERY);
        const sync = () => setCompact(query.matches);

        sync();
        query.addEventListener('change', sync);

        return () => query.removeEventListener('change', sync);
    }, []);

    return compact;
}