let audio: HTMLAudioElement | null = null;

export function playErrorSound(): void {
    try {
        audio ??= new Audio('/sounds/popup_close.mp3');
        audio.currentTime = 0;
        void audio.play().catch(() => {
        
        });
    } catch {
    
    }
}