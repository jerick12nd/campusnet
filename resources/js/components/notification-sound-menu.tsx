import { Volume2, VolumeX } from 'lucide-react';
import { useState } from 'react';

import {
    DropdownMenuCheckboxItem,
    DropdownMenuLabel,
    DropdownMenuRadioGroup,
    DropdownMenuRadioItem,
    DropdownMenuSeparator,
    DropdownMenuSub,
    DropdownMenuSubContent,
    DropdownMenuSubTrigger,
} from '@/components/ui/dropdown-menu';
import {
    getSoundChoice,
    previewSound,
    primeAlertSound,
    setSoundChoice,
    setSoundEnabled,
    soundEnabled,
    SOUND_CHOICES,
    type AlertSoundLevel,
    type SoundSelection,
} from '@/lib/alert-sound';

const LEVELS: { level: AlertSoundLevel; label: string }[] = [
    { level: 'warning', label: 'Warning alerts' },
    { level: 'critical', label: 'Critical alerts' },
];

/**
 * "Notification sounds" submenu for the profile menu: sound on/off, and which sound plays for
 * warning and critical alerts. Choosing a sound plays it once so you can hear it. Saved in this browser.
 */
export function NotificationSoundMenu() {
    const [enabled, setEnabled] = useState<boolean>(soundEnabled);
    const [choices, setChoices] = useState<Record<AlertSoundLevel, SoundSelection>>(() => ({
        warning: getSoundChoice('warning'),
        critical: getSoundChoice('critical'),
    }));

    const toggle = (on: boolean) => {
        setEnabled(on);
        setSoundEnabled(on);
        primeAlertSound();
    };

    const choose = (level: AlertSoundLevel, value: string) => {
        const choice = value as SoundSelection;

        setChoices((current) => ({ ...current, [level]: choice }));
        setSoundChoice(level, choice);
        primeAlertSound();
        void previewSound(choice);
    };

    return (
        <DropdownMenuSub>
            <DropdownMenuSubTrigger data-test="notification-sound-menu">
                {enabled ? <Volume2 className="mr-2" aria-hidden="true" /> : <VolumeX className="mr-2" aria-hidden="true" />}
                Notification sounds
            </DropdownMenuSubTrigger>

            <DropdownMenuSubContent className="w-56">
                <DropdownMenuCheckboxItem checked={enabled} onCheckedChange={toggle} onSelect={(event) => event.preventDefault()}>
                    Play sounds
                </DropdownMenuCheckboxItem>

                {LEVELS.map(({ level, label }) => (
                    <div key={level} className={enabled ? undefined : 'opacity-50'}>
                        <DropdownMenuSeparator />
                        <DropdownMenuLabel className="text-xs text-muted-foreground">{label}</DropdownMenuLabel>
                        <DropdownMenuRadioGroup value={choices[level]} onValueChange={(value) => choose(level, value)}>
                            {SOUND_CHOICES.map((choice) => (
                                <DropdownMenuRadioItem key={choice.id} value={choice.id} onSelect={(event) => event.preventDefault()}>
                                    {choice.label}
                                </DropdownMenuRadioItem>
                            ))}
                            <DropdownMenuRadioItem value="none" onSelect={(event) => event.preventDefault()}>
                                No sound
                            </DropdownMenuRadioItem>
                        </DropdownMenuRadioGroup>
                    </div>
                ))}
            </DropdownMenuSubContent>
        </DropdownMenuSub>
    );
}