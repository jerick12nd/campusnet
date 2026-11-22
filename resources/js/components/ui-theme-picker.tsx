import { Check } from 'lucide-react';

import { useUiTheme, type UiTheme } from '@/hooks/use-appearance';

type Swatch = {
    page: string;
    sidebar: string;
    sidebarInk: string;
    activeBg: string;
    activeInk: string;
    content: string;
    card: string;
    ink: string;
    accent: string;
    radius: string;
};

type ThemeOption = {
    id: UiTheme;
    name: string;
    description: string;
    swatch: Swatch;
};

// Preview colors are fixed on purpose: each card must look the same no matter which theme is active.
const THEME_OPTIONS: ThemeOption[] = [
    {
        id: 'glass',
        name: 'Liquid Glass',
        description: 'Frosted glass, with dark mode.',
        swatch: {
            page: '#1c2a44',
            sidebar: '#2a3a58',
            sidebarInk: '#c9d4ea',
            activeBg: '#4a6fb5',
            activeInk: '#ffffff',
            content: '#141c2e',
            card: '#22304d',
            ink: '#e6ecf8',
            accent: '#7aa7ff',
            radius: '10px',
        },
    },
       {
        id: 'midnight',
        name: 'Midnight',
        description: 'Dark navy sidebar with a blue pill. Light or dark.',
        swatch: {
            page: '#dfe3ec',
            sidebar: '#161b27',
            sidebarInk: '#aab4c9',
            activeBg: '#4a7bd0',
            activeInk: '#ffffff',
            content: '#f4f6fa',
            card: '#ffffff',
            ink: '#1a2233',
            accent: '#4a7bd0',
            radius: '10px',
        },
    },
    {
        id: 'royal',
        name: 'Royal blue',
        description: 'Solid blue sidebar with white content.',
        swatch: {
            page: '#e6ecfa',
            sidebar: '#1749e6',
            sidebarInk: '#dbe5ff',
            activeBg: '#ffffff',
            activeInk: '#1749e6',
            content: '#fbfcff',
            card: '#ffffff',
            ink: '#1b2447',
            accent: '#1749e6',
            radius: '9px',
        },
    },
    {
        id: 'emerald',
        name: 'Emerald',
        description: 'Deep green sidebar with mint content.',
        swatch: {
            page: '#dce8e3',
            sidebar: '#0d3d36',
            sidebarInk: '#cfe6dd',
            activeBg: '#1d5a4f',
            activeInk: '#ffffff',
            content: '#f2f8f5',
            card: '#ffffff',
            ink: '#10312b',
            accent: '#34d27b',
            radius: '10px',
        },
    },
];

function ThemePreview({ swatch }: { swatch: Swatch }) {
    return (
        <div
            aria-hidden="true"
            className="flex aspect-16/10 w-full gap-1 overflow-hidden rounded-lg p-1"
            style={{ backgroundColor: swatch.page }}
        >
            <div className="flex w-[30%] flex-col gap-1 p-1.5" style={{ backgroundColor: swatch.sidebar, borderRadius: swatch.radius }}>
                <div className="mb-0.5 h-1.5 w-3/4 rounded-full" style={{ backgroundColor: swatch.sidebarInk, opacity: 0.9 }} />
                <div className="h-2 rounded-full" style={{ backgroundColor: swatch.activeBg }} />
                <div className="h-1.5 w-5/6 rounded-full" style={{ backgroundColor: swatch.sidebarInk, opacity: 0.45 }} />
                <div className="h-1.5 w-2/3 rounded-full" style={{ backgroundColor: swatch.sidebarInk, opacity: 0.45 }} />
                <div className="h-1.5 w-3/4 rounded-full" style={{ backgroundColor: swatch.sidebarInk, opacity: 0.45 }} />
            </div>

            <div className="flex flex-1 flex-col gap-1 p-1.5" style={{ backgroundColor: swatch.content, borderRadius: swatch.radius }}>
                <div className="grid grid-cols-3 gap-1">
                    {[0, 1, 2].map((i) => (
                        <div key={i} className="h-3.5 p-0.5" style={{ backgroundColor: swatch.card, borderRadius: `calc(${swatch.radius} / 2)` }}>
                            <div className="h-1 w-1/2 rounded-full" style={{ backgroundColor: i === 0 ? swatch.accent : swatch.ink, opacity: i === 0 ? 1 : 0.35 }} />
                        </div>
                    ))}
                </div>
                <div className="flex flex-1 items-end gap-0.5 p-1" style={{ backgroundColor: swatch.card, borderRadius: `calc(${swatch.radius} / 2)` }}>
                    {[40, 70, 55, 85, 50, 75, 60].map((height, i) => (
                        <div
                            key={i}
                            className="flex-1 rounded-sm"
                            style={{ height: `${height}%`, backgroundColor: i % 2 === 0 ? swatch.accent : swatch.ink, opacity: i % 2 === 0 ? 1 : 0.25 }}
                        />
                    ))}
                </div>
            </div>
        </div>
    );
}

export default function UiThemePicker() {
    const { uiTheme, updateUiTheme } = useUiTheme();

    return (
        <fieldset className="grid grid-cols-2 gap-3">
            <legend className="sr-only">Theme</legend>

            {THEME_OPTIONS.map((option) => {
                const selected = option.id === uiTheme;

                return (
                    <label
                        key={option.id}
                        className="relative block cursor-pointer rounded-xl border bg-card p-2 transition-shadow hover:shadow-md has-checked:border-primary has-checked:ring-2 has-checked:ring-primary/25 has-focus-visible:ring-2 has-focus-visible:ring-ring"
                    >
                        <input
                            type="radio"
                            name="ui-theme"
                            value={option.id}
                            checked={selected}
                            onChange={() => updateUiTheme(option.id)}
                            className="sr-only"
                        />

                        <ThemePreview swatch={option.swatch} />

                        <div className="flex items-start justify-between gap-2 px-1 pt-2 pb-1">
                            <div className="min-w-0">
                                <p className="text-sm font-medium">{option.name}</p>
                                <p className="text-xs text-muted-foreground">{option.description}</p>
                            </div>

                            {selected && (
                                <span className="mt-0.5 flex size-5 shrink-0 items-center justify-center rounded-full bg-primary text-primary-foreground">
                                    <Check className="size-3" aria-hidden="true" />
                                </span>
                            )}
                        </div>
                    </label>
                );
            })}
        </fieldset>
    );
}