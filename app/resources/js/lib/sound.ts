import { useSyncExternalStore } from 'react';
import tap from '../../sound/tap.wav';
import next from '../../sound/next.wav';
import correct from '../../sound/correct.wav';
import tryAgain from '../../sound/try-again.wav';
import hint from '../../sound/hint.wav';
import saved from '../../sound/saved.wav';
import lessonComplete from '../../sound/lesson-complete.wav';
import milestone from '../../sound/milestone.wav';
import rest from '../../sound/rest.wav';
import welcome from '../../sound/welcome.wav';
import { type Cue, decide, type Playing } from './soundPolicy';

// DevStep's sound pack (docs/15 §8): ten short original cues, no voice. Off until the learner turns it on, always paired
// with something visible, never a measure of progress. Stored on this device only. Files load only when first played.

const URLS: Record<Cue, string> = {
    tap, next, correct, 'try-again': tryAgain, hint, saved, 'lesson-complete': lessonComplete, milestone, rest, welcome,
};
const VOLUME = 0.7;

export type SoundMode = 'on' | 'off';
const KEY = 'devstep.sound';
const listeners = new Set<() => void>();
let memory: SoundMode | null = null;

export function readSoundMode(): SoundMode {
    try {
        return window.localStorage.getItem(KEY) === 'on' ? 'on' : 'off';
    } catch {
        return 'off';
    }
}

export function setSoundMode(mode: SoundMode): void {
    try {
        mode === 'off' ? window.localStorage.removeItem(KEY) : window.localStorage.setItem(KEY, mode);
    } catch {
        /* storage blocked: the choice lasts until reload */
    }
    memory = mode;
    if (mode === 'off') stopSound();
    listeners.forEach((l) => l());
}

const snapshot = () => memory ?? readSoundMode();

export function useSoundMode(): SoundMode {
    return useSyncExternalStore((cb) => { listeners.add(cb); return () => listeners.delete(cb); }, snapshot, () => 'off');
}

const elements = new Map<Cue, HTMLAudioElement>();
let playing: (Playing & { audio: HTMLAudioElement }) | null = null;
let last: { cue: Cue; at: number } | null = null;

/** Silence whatever is sounding now (the mute button and "off" call this immediately). */
export function stopSound(): void {
    if (playing) {
        try { playing.audio.pause(); playing.audio.currentTime = 0; } catch { /* already gone */ }
        playing = null;
    }
}

/** Play a cue if sound is on and the policy allows it. Never throws: a blocked or failed sound is just silence. */
export function playCue(cue: Cue, options: { once?: string } = {}): void {
    try {
        if (typeof window === 'undefined') return;
        const now = Date.now();
        const verdict = decide(cue, {
            enabled: snapshot() === 'on',
            hidden: document.visibilityState === 'hidden',
            now,
            playing: playing ? { cue: playing.cue, endsAt: playing.endsAt } : null,
            last,
        });
        if (verdict === 'skip') return;
        if (options.once) {
            const k = `devstep.sound.once.${options.once}`;
            if (window.sessionStorage.getItem(k)) return;
            window.sessionStorage.setItem(k, '1');
        }

        stopSound(); // one cue at a time: the old one is stopped before the new one starts
        let audio = elements.get(cue);
        if (!audio) {
            audio = new Audio(URLS[cue]);
            audio.preload = 'auto';
            audio.volume = VOLUME;
            elements.set(cue, audio);
        }
        audio.currentTime = 0;
        const duration = Number.isFinite(audio.duration) && audio.duration > 0 ? audio.duration * 1000 : 1900;
        playing = { cue, endsAt: now + duration, audio };
        last = { cue, at: now };
        void audio.play().catch(() => { if (playing?.audio === audio) playing = null; });
    } catch {
        /* sound is a nicety; never let it break a lesson */
    }
}
