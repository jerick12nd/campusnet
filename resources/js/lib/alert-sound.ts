export type AlertSoundLevel = 'warning' | 'critical';

/** Every sound the person can pick. Add a file to public/sounds and one line here to offer another. */
export const SOUND_CHOICES = [
    { id: 'notification', label: 'Notification', files: ['/sounds/notification.mp3'] },
    { id: 'hit', label: 'Hit', files: ['/sounds/hit.mp3'] },
    { id: 'error', label: 'Error', files: ['/sounds/error.mp3'] },
    { id: 'popup', label: 'Popup', files: ['/sounds/popup_close.mp3'] },
    { id: 'hit-error', label: 'Hit + Error', files: ['/sounds/hit.mp3', '/sounds/error.mp3'] },
] as const;

export type SoundChoiceId = (typeof SOUND_CHOICES)[number]['id'];
export type SoundSelection = SoundChoiceId | 'none';

const ENABLED_KEY = 'campusnet.alerts.sound';

const CHOICE_KEY: Record<AlertSoundLevel, string> = {
    warning: 'campusnet.alerts.sound.warning',
    critical: 'campusnet.alerts.sound.critical',
};

const DEFAULTS: Record<AlertSoundLevel, SoundSelection> = {
    warning: 'notification',
    critical: 'hit-error',
};

const elements = new Map<string, HTMLAudioElement>();
let unlocked = false;
let playId = 0;

/* ---- Saved settings (this browser only) ---- */

export function soundEnabled(): boolean {
    try {
        return localStorage.getItem(ENABLED_KEY) !== 'off';
    } catch {
        return true;
    }
}

export function setSoundEnabled(on: boolean): void {
    try {
        localStorage.setItem(ENABLED_KEY, on ? 'on' : 'off');
    } catch {
        // storage unavailable: the choice just lasts until the page is reloaded
    }
}

function isSelection(value: string | null): value is SoundSelection {
    return value === 'none' || SOUND_CHOICES.some((choice) => choice.id === value);
}

export function getSoundChoice(level: AlertSoundLevel): SoundSelection {
    try {
        const saved = localStorage.getItem(CHOICE_KEY[level]);

        if (isSelection(saved)) {
            return saved;
        }
    } catch {
        // fall through to the default
    }

    return DEFAULTS[level];
}

export function setSoundChoice(level: AlertSoundLevel, choice: SoundSelection): void {
    try {
        localStorage.setItem(CHOICE_KEY[level], choice);
    } catch {
        // storage unavailable: the choice just lasts until the page is reloaded
    }
}

function filesFor(selection: SoundSelection): readonly string[] {
    if (selection === 'none') {
        return [];
    }

    return SOUND_CHOICES.find((choice) => choice.id === selection)?.files ?? [];
}

/* ---- Playback ---- */

function element(file: string): HTMLAudioElement {
    let el = elements.get(file);

    if (!el) {
        el = new Audio(file);
        el.preload = 'auto';
        elements.set(file, el);
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

    const files = new Set<string>(SOUND_CHOICES.flatMap((choice) => [...choice.files]));

    files.forEach((file) => {
        const el = element(file);

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

function stopAll(): void {
    elements.forEach((el) => {
        el.pause();
        el.currentTime = 0;
    });
}

/** Plays one file and resolves when it has finished (or after 4 s at most). */
function playOne(file: string): Promise<boolean> {
    const el = element(file);

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
            // A newer sound interrupting this one is normal, not a failure.
            if ((error as Error).name === 'AbortError') {
                finish(true);

                return;
            }

            console.warn(`[CampusNet] Alert sound "${file}" could not play:`, error);
            finish(false);
        });
    });
}

/** Plays the files one after another. A newer call stops the older one. Returns false if the browser blocked it. */
async function playFiles(files: readonly string[]): Promise<boolean> {
    const id = ++playId;

    stopAll();

    for (const file of files) {
        if (id !== playId) {
            return true;
        }

        if (!(await playOne(file))) {
            return false;
        }
    }

    return true;
}

/**
 * Plays the sound chosen for a severity. Returns false when it did not play (sound off, or blocked
 * by the browser). `force` ignores the on/off switch.
 */
export async function playAlertSound(level: AlertSoundLevel, force = false): Promise<boolean> {
    if (!force && !soundEnabled()) {
        return false;
    }

    const played = await playFiles(filesFor(getSoundChoice(level)));

    if (played) {
        unlocked = true;
    }

    return played;
}

/** Plays a sound so the person can hear it before choosing it. */
export function previewSound(choice: SoundSelection): Promise<boolean> {
    return playFiles(filesFor(choice));
}