const KEY = 'campusnet.alerts.sound';

export type AlertSoundLevel = 'warning' | 'critical';

const FILES = {
    notification: '/sounds/notification.mp3',
    hit: '/sounds/hit.mp3',
    error: '/sounds/error.mp3',
} as const;

type SoundName = keyof typeof FILES;

/** Which sounds play, in order, for each severity. Change this to use other files. */
const SEQUENCE: Record<AlertSoundLevel, SoundName[]> = {
    warning: ['notification'],
    critical: ['hit', 'error'],
};

const elements = new Map<SoundName, HTMLAudioElement>();
let unlocked = false;

export function soundEnabled(): boolean {
    try {
        return localStorage.getItem(KEY) !== 'off';
    } catch {
        return true;
    }
}

export function setSoundEnabled(on: boolean): void {
    try {
        localStorage.setItem(KEY, on ? 'on' : 'off');
    } catch {
        // storage unavailable: the choice just lasts until the page is reloaded
    }
}

function element(name: SoundName): HTMLAudioElement {
    let el = elements.get(name);

    if (!el) {
        el = new Audio(FILES[name]);
        el.preload = 'auto';
        elements.set(name, el);
    }

    return el;
}

/**
 * Browsers only allow sound after the person has interacted with the page. Calling this on the
 * first click or key press plays every file silently once, which unlocks later alert sounds.
 */
export function primeAlertSound(): void {
    if (unlocked) {
        return;
    }

    (Object.keys(FILES) as SoundName[]).forEach((name) => {
        const el = element(name);

        el.muted = true;
        el.play()
            .then(() => {
                el.pause();
                el.currentTime = 0;
                el.muted = false;
                unlocked = true;
            })
            .catch(() => {
                el.muted = false;
            });
    });
}

/** Plays one file and resolves when it has finished (or after 4 s at most). */
function playOne(name: SoundName): Promise<boolean> {
    const el = element(name);

    el.muted = false;
    el.currentTime = 0;

    return new Promise((resolve) => {
        const finish = (ok: boolean) => {
            el.removeEventListener('ended', onEnded);
            window.clearTimeout(timer);
            resolve(ok);
        };

        const onEnded = () => finish(true);
        const timer = window.setTimeout(() => finish(true), 4000);

        el.addEventListener('ended', onEnded);
        el.play().catch((error: unknown) => {
            console.warn(`[CampusNet] Alert sound "${name}" could not play:`, error);
            finish(false);
        });
    });
}

/**
 * Plays the sound for a severity. Returns false when it did not play (sound off, or blocked by
 * the browser). `force` ignores the on/off switch; it is used by the "Test sound" buttons.
 */
export async function playAlertSound(level: AlertSoundLevel, force = false): Promise<boolean> {
    if (!force && !soundEnabled()) {
        return false;
    }

    for (const name of SEQUENCE[level]) {
        if (!(await playOne(name))) {
            return false;
        }
    }

    unlocked = true;

    return true;
}