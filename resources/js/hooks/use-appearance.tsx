import { useSyncExternalStore } from 'react';

export type ResolvedAppearance = 'light' | 'dark';
export type Appearance = ResolvedAppearance | 'system';

/** 'glass' is the original Liquid Glass look. 'midnight', 'icecream' and 'sketchpad' also have a dark mode. The rest are fixed light themes. */
export type UiTheme = 'glass' | 'midnight' | 'icecream' | 'sketchpad' | 'mono' | 'royal' | 'emerald';
export const UI_THEMES: readonly UiTheme[] = ['glass', 'midnight', 'icecream', 'sketchpad', 'mono', 'royal', 'emerald'];

/** Only these themes have a dark mode. The others are light only. */
const LIGHT_ONLY_THEMES: readonly UiTheme[] = ['mono', 'royal', 'emerald'];
export const supportsDarkMode = (theme: UiTheme): boolean => !LIGHT_ONLY_THEMES.includes(theme);

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

    // Mono, Royal blue and Emerald are light only, so dark mode applies to the other themes.
    const isDark = supportsDarkMode(currentUiTheme) && isDarkMode(appearance);

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