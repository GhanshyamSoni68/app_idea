import { useSyncExternalStore } from 'react';

// The learner decides how much of the mascot they see (docs/15): full, quiet (still image, no motion) or off.
// Stored on this device only. "Off" must always be available: some people find a mascot childish or distracting,
// and the PRD puts autonomy first (PRD §2, docs/08 IR-13).

export type MascotMode = 'on' | 'quiet' | 'off';
const KEY = 'devstep.mascot';
const listeners = new Set<() => void>();

export function readMascotMode(): MascotMode {
    try {
        const v = window.localStorage.getItem(KEY);

        return v === 'quiet' || v === 'off' ? v : 'on';
    } catch {
        return 'on';
    }
}

export function setMascotMode(mode: MascotMode): void {
    try {
        mode === 'on' ? window.localStorage.removeItem(KEY) : window.localStorage.setItem(KEY, mode);
    } catch {
        /* storage blocked: the choice lasts until reload */
    }
    memory = mode;
    listeners.forEach((l) => l());
}

let memory: MascotMode | null = null;
const snapshot = () => memory ?? readMascotMode();

export function useMascotMode(): MascotMode {
    return useSyncExternalStore((cb) => { listeners.add(cb); return () => listeners.delete(cb); }, snapshot, () => 'on');
}

/** True when the OS asks for less motion, or the learner chose quiet or off. */
export function prefersStill(mode: MascotMode): boolean {
    return mode !== 'on' || (typeof window !== 'undefined' && window.matchMedia?.('(prefers-reduced-motion: reduce)').matches);
}
