import { useSyncExternalStore } from 'react';

export type ResolvedAppearance = 'light' | 'dark';
export type Appearance = ResolvedAppearance | 'system';

/**
 * 'glass' is the original Liquid Glass look. Every theme says which color modes it supports:
 * 'both' = the Light/Dark setting works, 'light' = always light, 'dark' = always dark.
 */
export type UiTheme = 'glass' | 'dssc' | 'midnight' | 'icecream' | 'sketchpad' | 'hacker' | 'mono' | 'royal' | 'emerald';
export type ThemeColorModes = 'both' | 'light' | 'dark';

const THEME_COLOR_MODES: Record<UiTheme, ThemeColorModes> = {
    glass: 'both',
    dssc: 'both',
    midnight: 'both',
    icecream: 'both',
    sketchpad: 'both',
    hacker: 'dark',
    mono: 'light',
    royal: 'light',
    emerald: 'light',
};

export const UI_THEMES = Object.keys(THEME_COLOR_MODES) as UiTheme[];

export const colorModesOf = (theme: UiTheme): ThemeColorModes => THEME_COLOR_MODES[theme];

/** True when the person can choose Light or Dark for this theme. */
export const supportsDarkMode = (theme: UiTheme): boolean => THEME_COLOR_MODES[theme] === 'both';

export type UseAppearanceReturn = {
    readonly appearance: Appearance;
    readonly resolvedAppearance: ResolvedAppearance;
    readonly updateAppearance: (mode: Appearance) => void;
};

export type UseUiThemeReturn = {
    readonly uiTheme: UiTheme;
    readonly updateUiTheme: (theme: UiTheme) => void;
};

const UI_THEME_KEY = 'ui-theme';

const listeners = new Set<() => void>();
let currentAppearance: Appearance = 'system';
let currentUiTheme: UiTheme = 'glass';

const prefersDark = (): boolean => {
    if (typeof window === 'undefined') {
        return false;
    }

    return window.matchMedia('(prefers-color-scheme: dark)').matches;
};

const setCookie = (name: string, value: string, days = 365): void => {
    if (typeof document === 'undefined') {
        return;
    }

    const maxAge = days * 24 * 60 * 60;
    document.cookie = `${name}=${value};path=/;max-age=${maxAge};SameSite=Lax`;
};

const getStoredAppearance = (): Appearance => {
    if (typeof window === 'undefined') {
        return 'system';
    }

    return (localStorage.getItem('appearance') as Appearance) || 'system';
};

const isUiTheme = (value: unknown): value is UiTheme => {
    return typeof value === 'string' && (UI_THEMES as readonly string[]).includes(value);
};

const getStoredUiTheme = (): UiTheme => {
    if (typeof window === 'undefined') {
        return 'glass';
    }

    try {
        const stored = localStorage.getItem(UI_THEME_KEY);

        return isUiTheme(stored) ? stored : 'glass';
    } catch {
        return 'glass';
    }
};

const isDarkMode = (appearance: Appearance): boolean => {
    return appearance === 'dark' || (appearance === 'system' && prefersDark());
};

const applyTheme = (appearance: Appearance): void => {
    if (typeof document === 'undefined') {
        return;
    }

    const root = document.documentElement;

    // Light-only themes stay light, dark-only themes stay dark, the rest follow the Light/Dark setting.
    const modes = colorModesOf(currentUiTheme);
    const isDark = modes === 'dark' || (modes === 'both' && isDarkMode(appearance));

    root.classList.toggle('dark', isDark);
    root.style.colorScheme = isDark ? 'dark' : 'light';

    // Liquid Glass = no data-theme attribute at all, so its CSS stays untouched.
    if (currentUiTheme === 'glass') {
        root.removeAttribute('data-theme');
    } else {
        root.setAttribute('data-theme', currentUiTheme);
    }
};

const subscribe = (callback: () => void) => {
    listeners.add(callback);

    return () => listeners.delete(callback);
};

const notify = (): void => listeners.forEach((listener) => listener());

const mediaQuery = (): MediaQueryList | null => {
    if (typeof window === 'undefined') {
        return null;
    }

    return window.matchMedia('(prefers-color-scheme: dark)');
};

const handleSystemThemeChange = (): void => applyTheme(currentAppearance);

export function initializeTheme(): void {
    if (typeof window === 'undefined') {
        return;
    }

    if (!localStorage.getItem('appearance')) {
        localStorage.setItem('appearance', 'system');
        setCookie('appearance', 'system');
    }

    currentAppearance = getStoredAppearance();
    currentUiTheme = getStoredUiTheme();
    applyTheme(currentAppearance);

    // Set up system theme change listener
    mediaQuery()?.addEventListener('change', handleSystemThemeChange);
}

export function useAppearance(): UseAppearanceReturn {
    const appearance: Appearance = useSyncExternalStore(
        subscribe,
        () => currentAppearance,
        () => 'system',
    );

    const resolvedAppearance: ResolvedAppearance = isDarkMode(appearance)
        ? 'dark'
        : 'light';

    const updateAppearance = (mode: Appearance): void => {
        currentAppearance = mode;

        // Store in localStorage for client-side persistence...
        localStorage.setItem('appearance', mode);

        // Store in cookie for SSR...
        setCookie('appearance', mode);

        applyTheme(mode);
        notify();
    };

    return { appearance, resolvedAppearance, updateAppearance } as const;
}

export function useUiTheme(): UseUiThemeReturn {
    const uiTheme: UiTheme = useSyncExternalStore(
        subscribe,
        () => currentUiTheme,
        () => 'glass',
    );

    const updateUiTheme = (theme: UiTheme): void => {
        currentUiTheme = theme;

        // Per browser only. No database change needed.
        try {
            localStorage.setItem(UI_THEME_KEY, theme);
        } catch {
            // Private mode / blocked storage: the theme still applies until reload.
        }

        applyTheme(currentAppearance);
        notify();
    };

    return { uiTheme, updateUiTheme } as const;
}