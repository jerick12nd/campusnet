const KEY = 'campusnet.alerts.sound';

let audio: HTMLAudioElement | null = null;
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

function element(): HTMLAudioElement {
    if (!audio) {
        audio = new Audio('/sounds/alert.mp3');
        audio.preload = 'auto';
    }

    return audio;
}

/**
 * Browsers only allow sound after the person has interacted with the page. Calling this on the
 * first click or key press plays the file silently once, which unlocks later alert sounds.
 */
export function primeAlertSound(): void {
    if (unlocked) {
        return;
    }

    const el = element();

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
}

/** Plays the alert sound. Returns false when it did not play (sound off, or blocked by the browser). */
export async function playAlertSound(force = false): Promise<boolean> {
    if (!force && !soundEnabled()) {
        return false;
    }

    try {
        const el = element();

        el.muted = false;
        el.currentTime = 0;
        await el.play();
        unlocked = true;

        return true;
    } catch (error) {
        console.warn('[CampusNet] Alert sound could not play:', error);

        return false;
    }
}