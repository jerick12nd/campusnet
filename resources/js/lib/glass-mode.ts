type GlassMode = 'full' | 'lite';

const STORAGE_KEY = 'campusnet.glass';

function readOverride(): GlassMode | null {
    try {
        const value = window.localStorage.getItem(STORAGE_KEY);

        return value === 'lite' || value === 'full' ? value : null;
    } catch {
        return null;
    }
}

function detectMode(): GlassMode {
    const override = readOverride();

    if (override) {
        return override;
    }

    const cores = navigator.hardwareConcurrency;
    // deviceMemory is Chromium-only and not in lib.dom.d.ts.
    const memory = (navigator as Navigator & { deviceMemory?: number })
        .deviceMemory;

    if ((cores && cores <= 2) || (memory && memory <= 2)) {
        return 'lite';
    }

    return 'full';
}

export function applyGlassMode(mode: GlassMode = detectMode()): void {
    if (typeof document === 'undefined') {
        return;
    }

    if (mode === 'lite') {
        document.documentElement.dataset.glass = 'lite';
    } else {
        delete document.documentElement.dataset.glass;
    }
}

export function setGlassMode(mode: GlassMode): void {
    try {
        window.localStorage.setItem(STORAGE_KEY, mode);
    } catch {
        // Storage can be blocked; the mode still applies for this page view.
    }

    applyGlassMode(mode);
}

applyGlassMode();