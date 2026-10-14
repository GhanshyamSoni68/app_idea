// When a cue may play (docs/15 §8). Pure, so the rules are tested without audio. The pack's own integration rules:
// one cue at a time, milestone beats lesson-complete beats everything else, no rapid repeats, and nothing for
// typing, autosave, scrolling or inactivity (so those callers never ask).

export type Cue = 'tap' | 'next' | 'correct' | 'try-again' | 'hint' | 'saved' | 'lesson-complete' | 'milestone' | 'rest' | 'welcome';

export const CUES: readonly Cue[] = ['tap', 'next', 'correct', 'try-again', 'hint', 'saved', 'lesson-complete', 'milestone', 'rest', 'welcome'];

const PRIORITY: Record<Cue, number> = {
    tap: 1, next: 1, correct: 1, 'try-again': 1, hint: 1, saved: 1, rest: 1, welcome: 1, 'lesson-complete': 2, milestone: 3,
};

/** Minimum gap before the same cue may sound again, so a double click is one sound. */
export const REPEAT_GUARD_MS = 400;

export interface Playing { cue: Cue; endsAt: number }

export type Decision = 'play' | 'skip';

export function decide(
    request: Cue,
    ctx: { enabled: boolean; hidden: boolean; now: number; playing: Playing | null; last: { cue: Cue; at: number } | null },
): Decision {
    if (!ctx.enabled || ctx.hidden) return 'skip';
    if (ctx.last && ctx.last.cue === request && ctx.now - ctx.last.at < REPEAT_GUARD_MS) return 'skip';
    // A bigger moment is never cut off by a smaller one that arrives while it is still sounding.
    if (ctx.playing && ctx.playing.endsAt > ctx.now && PRIORITY[ctx.playing.cue] > PRIORITY[request]) return 'skip';

    return 'play';
}

/** The cue for an authoritative result, or null when sound would be misleading (self-assessed answers prove nothing). */
export function cueForResult(tone: 'ok' | 'notyet' | string, basis: string | undefined): Cue | null {
    if (basis === 'self_assessed') return null;
    if (tone === 'ok') return 'correct';
    if (tone === 'notyet') return 'try-again';

    return null;
}
