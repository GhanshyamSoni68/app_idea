import type { Basis, Level, Mode, Outcome } from '../types';

// Display labels for canonical enums (docs/08 §7.3). British spelling, plain and calm.

export const MODE_LABEL: Record<Mode, string> = { small: 'Start small', practise: 'Practise', build: 'Build' };
export const MODE_TIME: Record<Mode, string> = { small: 'about 3 min', practise: 'about 10 min', build: '30–45 min on a computer' };

export const LEVEL_LABEL: Record<Level, string> = { introduced: 'Introduced', practised: 'Practised', demonstrated: 'Demonstrated', retained: 'Retained' };
export const LEVEL_GLYPH: Record<Level, string> = { introduced: '○', practised: '◐', demonstrated: '●', retained: '◉' };
export const LEVEL_EXPLAIN: Record<Level, string> = {
    introduced: 'You have met the concept.',
    practised: 'You attempted it and reviewed feedback.',
    demonstrated: 'You met the rubric on a new task without hints or the solution.',
    retained: 'You met the rubric again on a new task, without hints or the solution, at least 7 days later.',
};

export const BASIS_LABEL: Record<Basis, string> = {
    auto_scored: 'Checked automatically',
    self_assessed: 'Self-assessed against an example. Not reviewed by a person.',
    learner_submitted: 'Submitted by you. Not verified by DevStep.',
    human_reviewed: 'Reviewed by a person',
};

export const LIMITATION_LABEL: Record<string, string> = {
    small_mode: 'Done in Start small',
    guest_claim: 'Done as a guest, checked again when you joined',
    solution_revealed: 'Solution seen before answering',
    ai_generated_unreviewed: 'From AI-made practice a person has not reviewed, so shown as Practised at most',
};

export const OUTCOME_LABEL: Record<Outcome, { word: string; glyph: string; tone: 'ok' | 'notyet' | 'neutral' }> = {
    correct: { word: 'A good answer', glyph: '✓', tone: 'ok' },
    partially_correct: { word: 'Partly right', glyph: '◐', tone: 'notyet' },
    incorrect: { word: 'Not quite', glyph: '!', tone: 'notyet' },
    self_met: { word: 'Met, by your own check', glyph: '✓', tone: 'ok' },
    self_not_met: { word: 'Not yet, by your own check', glyph: '!', tone: 'notyet' },
};

export const TOPIC_STATE: Record<string, { label: string; glyph: string }> = {
    not_started: { label: 'Not started', glyph: '□' },
    in_progress: { label: 'In progress', glyph: '◧' },
    completed: { label: 'Completed', glyph: '☑' },
    deferred: { label: 'Deferred, no credit', glyph: '⊟' },
};

export function helpUsed(assistance: string, hints: number): string {
    if (assistance === 'hint') return `Help used: ${hints} ${hints === 1 ? 'hint' : 'hints'}`;
    if (assistance === 'worked_example') return 'Help used: a worked example';
    if (assistance === 'solution_revealed') return 'You saw the solution before answering, so this one will not count toward your evidence.';

    return 'No help used';
}

/** Assistance on an evidence record (docs/08 §7.3). A reveal never labels evidence: it earns nothing above Introduced. */
export function assistanceLabel(assistance: string): string {
    return assistance === 'hint' ? 'Used a hint' : assistance === 'worked_example' ? 'Used a worked example' : assistance === 'solution_revealed' ? 'Introduced only' : 'No help used';
}

export function formatDate(iso: string | null | undefined, timeZone?: string): string {
    if (!iso) return '';
    const d = new Date(iso.length === 10 ? `${iso}T12:00:00Z` : iso);

    return new Intl.DateTimeFormat('en-GB', { day: 'numeric', month: 'short', timeZone: iso.length === 10 ? 'UTC' : timeZone }).format(d);
}

export function minutesLabel(m: number | null | undefined): string {
    return m ? `About ${m} min` : '';
}

export function browserTimeZone(): string {
    try {
        return Intl.DateTimeFormat().resolvedOptions().timeZone || 'UTC';
    } catch {
        return 'UTC';
    }
}
