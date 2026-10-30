/**
 * Optional chime for a NEW alert while the Hub is open and on screen.
 *
 * - A per-device choice ("Play a sound for new alerts while the Hub is open"), OFF by default,
 *   kept in localStorage under `mbfd-alert-sound`. No account setting, no schema.
 * - The chime is generated with WebAudio (two soft sine notes, about half a second). No file.
 * - It only plays when the choice is on, the page is visible, and the browser has let audio
 *   start after a tap or key press on this page. Otherwise it is skipped, silently.
 * - It is never attached to a service worker and never plays in the background: a lock-screen
 *   alert uses the phone's own notification sound and Focus settings.
 */

export const SOUND_KEY = 'mbfd-alert-sound';

export const SOUND_NOTE =
    "Alerts on your lock screen use your phone's own notification sound and Focus settings; the Hub can't change those.";

/** Chimes closer together than this collapse into one (three alerts at once ring once). */
export const CHIME_GAP_MS = 2_000;

export interface StorageLike {
    getItem(key: string): string | null;
    setItem(key: string, value: string): void;
    removeItem(key: string): void;
}

function defaultStorage(): StorageLike | null {
    try {
        return typeof localStorage === 'undefined' ? null : localStorage;
    } catch {
        return null;
    }
}

/** True only when this device's choice is explicitly on. Unreadable storage means off. */
export function readSoundPreference(storage: StorageLike | null = defaultStorage()): boolean {
    try {
        return storage?.getItem(SOUND_KEY) === 'on';
    } catch {
        return false;
    }
}

/** Saves the choice; false when this browser cannot remember it (private mode, blocked storage). */
export function writeSoundPreference(on: boolean, storage: StorageLike | null = defaultStorage()): boolean {
    if (storage === null) return false;
    try {
        if (on) storage.setItem(SOUND_KEY, 'on');
        else storage.removeItem(SOUND_KEY);

        return readSoundPreference(storage) === on;
    } catch {
        return false;
    }
}

export interface SoundGate {
    enabled: boolean;
    visible: boolean;
    /** AudioContext.state, or null when no context exists yet (never unlocked). */
    audioState: string | null;
}

/** The whole rule in one place: on, visible, and unlocked by a gesture. */
export function shouldPlaySound(gate: SoundGate): boolean {
    return gate.enabled && gate.visible && gate.audioState === 'running';
}

// ---------------------------------------------------------------------------
// WebAudio
// ---------------------------------------------------------------------------

type AudioContextCtor = new () => AudioContext;

let context: AudioContext | null = null;
let lastChimeAt = Number.NEGATIVE_INFINITY;

function audioContextClass(): AudioContextCtor | null {
    if (typeof window === 'undefined') return null;
    const w = window as unknown as { AudioContext?: AudioContextCtor; webkitAudioContext?: AudioContextCtor };

    return w.AudioContext ?? w.webkitAudioContext ?? null;
}

function audioState(): string | null {
    return context?.state ?? null;
}

/**
 * Must run inside a tap or key press. Creates (or resumes) the one AudioContext and plays a
 * silent blip, which is what iOS needs to let later sounds through.
 */
export async function unlockAudio(): Promise<boolean> {
    const Ctor = audioContextClass();
    if (Ctor === null) return false;
    try {
        context ??= new Ctor();
        if (context.state !== 'running') {
            // Not awaited before the blip: iOS only honours work started synchronously in the gesture.
            const resumed = context.resume();
            const blip = context.createOscillator();
            const silent = context.createGain();
            silent.gain.value = 0;
            blip.connect(silent).connect(context.destination);
            blip.start();
            blip.stop(context.currentTime + 0.01);
            await resumed;
        }

        return context.state === 'running';
    } catch {
        return false;
    }
}

function chime(ctx: AudioContext): void {
    const start = ctx.currentTime + 0.01;
    // G5 then C6: short, soft, rising. Peak gain stays low so it never startles.
    const notes: Array<[frequency: number, offset: number, length: number]> = [
        [784, 0, 0.22],
        [1047, 0.16, 0.32],
    ];
    for (const [frequency, offset, length] of notes) {
        const oscillator = ctx.createOscillator();
        const gain = ctx.createGain();
        oscillator.type = 'sine';
        oscillator.frequency.value = frequency;
        const at = start + offset;
        gain.gain.setValueAtTime(0.0001, at);
        gain.gain.exponentialRampToValueAtTime(0.12, at + 0.02);
        gain.gain.exponentialRampToValueAtTime(0.0001, at + length);
        oscillator.connect(gain).connect(ctx.destination);
        oscillator.start(at);
        oscillator.stop(at + length + 0.02);
    }
}

/** Plays the chime for a new alert if, and only if, every condition holds. Returns whether it played. */
export function playAlertChime(now: number = Date.now()): boolean {
    const visible = typeof document !== 'undefined' && document.visibilityState === 'visible';
    if (!shouldPlaySound({ enabled: readSoundPreference(), visible, audioState: audioState() })) return false;
    if (now - lastChimeAt < CHIME_GAP_MS || context === null) return false;
    try {
        chime(context);
        lastChimeAt = now;

        return true;
    } catch {
        return false;
    }
}

/** "Play test sound": the member asked, so it plays whatever the setting (and unlocks audio). */
export async function playTestChime(): Promise<boolean> {
    if (!(await unlockAudio()) || context === null) return false;
    try {
        chime(context);

        return true;
    } catch {
        return false;
    }
}

let gestureListenerInstalled = false;

/**
 * Browsers only let audio start after the member interacts with the page. When the choice is on,
 * the first tap or key press on each page quietly unlocks it, so a later alert can chime.
 */
export function installGestureUnlock(): void {
    if (gestureListenerInstalled || typeof document === 'undefined') return;
    gestureListenerInstalled = true;
    const onGesture = (event: Event): void => {
        if (!event.isTrusted || audioState() === 'running' || !readSoundPreference()) return;
        void unlockAudio();
    };
    for (const type of ['pointerdown', 'keydown'] as const) {
        document.addEventListener(type, onGesture, { capture: true, passive: true });
    }
}

// ---------------------------------------------------------------------------
// Settings block ([data-alert-sound-settings] on the Notification settings page)
// ---------------------------------------------------------------------------

export function mountAlertSoundSettings(root: ParentNode | null = typeof document === 'undefined' ? null : document): HTMLElement | null {
    const block = root?.querySelector<HTMLElement>('[data-alert-sound-settings]') ?? null;
    if (block === null || block.dataset.mounted === 'true') return block;
    block.dataset.mounted = 'true';

    const toggle = block.querySelector<HTMLInputElement>('[data-role="sound-toggle"]');
    const test = block.querySelector<HTMLButtonElement>('[data-role="sound-test"]');
    const message = block.querySelector<HTMLElement>('[data-role="sound-message"]');
    const say = (text: string): void => {
        if (message) message.textContent = text;
    };

    if (toggle) {
        const storage = defaultStorage();
        toggle.checked = readSoundPreference(storage);
        toggle.disabled = storage === null;
        if (storage === null) say("This browser can't remember this choice, so alerts stay silent here.");
        toggle.addEventListener('change', () => {
            const on = toggle.checked;
            if (!writeSoundPreference(on)) {
                toggle.checked = readSoundPreference();
                say("This browser couldn't save that choice.");

                return;
            }
            if (on) {
                // The change itself is a gesture: unlock now so the very next alert can chime.
                void unlockAudio();
                say('Sound is on for this device while the Hub is open.');
            } else {
                say('Sound is off for this device.');
            }
        });
    }

    test?.addEventListener('click', () => {
        void playTestChime().then((played) => {
            say(played ? 'Played the alert sound. If you heard nothing, check the volume and silent switch.' : "This browser couldn't play a sound.");
        });
    });

    return block;
}
