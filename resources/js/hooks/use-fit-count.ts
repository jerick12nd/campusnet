import { useLayoutEffect, useRef, useState } from 'react';

/**
 * A wall display cannot be scrolled, so a list may only show the rows that really fit its box.
 *
 * It renders the first `count` rows once ("measuring"), counts how many end inside the box, then
 * renders just those. It measures again whenever `count` or the height of the box changes, for
 * example after a resize. The box needs a height that does not depend on its rows
 * (flex-1 + min-h-0 + overflow-hidden), otherwise measuring would chase its own tail.
 *
 * Pass `enabled = false` for a layout that grows with its content (the scrolling phone layout):
 * then every row is shown and nothing is measured.
 */
export function useFitCount<T extends HTMLElement>(count: number, enabled = true) {
    const ref = useRef<T>(null);
    const [fit, setFit] = useState<number | null>(null); // null = measuring
    const [height, setHeight] = useState(0);

    useLayoutEffect(() => {
        const element = ref.current;

        if (!enabled || !element || typeof ResizeObserver === 'undefined') {
            return;
        }

        const observer = new ResizeObserver(() => setHeight(element.clientHeight));

        observer.observe(element);

        return () => observer.disconnect();
    }, [enabled]);

    useLayoutEffect(() => {
        setFit(null);
    }, [count, height, enabled]);

    useLayoutEffect(() => {
        const element = ref.current;

        if (!enabled || fit !== null || !element) {
            return;
        }

        const limit = element.getBoundingClientRect().bottom + 1;
        let visible = 0;

        for (const child of Array.from(element.children)) {
            if (child.getBoundingClientRect().bottom > limit) {
                break;
            }

            visible += 1;
        }

        setFit(Math.max(1, visible));
    });

    return { ref, measuring: enabled && fit === null, fit: enabled ? (fit ?? count) : count };
}