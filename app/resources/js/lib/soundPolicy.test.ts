import { describe, expect, it } from 'vitest';
import { CUES, cueForResult, decide, type Cue } from './soundPolicy';

const base = { enabled: true, hidden: false, now: 10_000, playing: null, last: null };

describe('sound policy', () => {
    it('is silent unless the learner turned sound on, and while the tab is hidden', () => {
        for (const c of CUES) {
            expect(decide(c, { ...base, enabled: false })).toBe('skip');
            expect(decide(c, { ...base, hidden: true })).toBe('skip');
            expect(decide(c, base)).toBe('play');
        }
    });

    it('turns a double click into one sound, but lets a different cue through', () => {
        expect(decide('next', { ...base, last: { cue: 'next', at: 9_800 } })).toBe('skip');
        expect(decide('next', { ...base, last: { cue: 'next', at: 9_000 } })).toBe('play');
        expect(decide('hint', { ...base, last: { cue: 'next', at: 9_900 } })).toBe('play');
    });

    it('never lets a smaller cue cut off a bigger moment, but a bigger one replaces a smaller one', () => {
        const during = (cue: Cue) => ({ ...base, playing: { cue, endsAt: 11_000 } });
        expect(decide('correct', during('milestone'))).toBe('skip');
        expect(decide('lesson-complete', during('milestone'))).toBe('skip');
        expect(decide('next', during('lesson-complete'))).toBe('skip');
        expect(decide('milestone', during('lesson-complete'))).toBe('play');
        expect(decide('lesson-complete', during('correct'))).toBe('play');
        expect(decide('correct', during('next'))).toBe('play');
        // once the bigger cue has ended, anything may play
        expect(decide('next', { ...base, playing: { cue: 'milestone', endsAt: 9_000 } })).toBe('play');
    });

    it('sounds a result only when the feedback is authoritative', () => {
        expect(cueForResult('ok', 'auto_scored')).toBe('correct');
        expect(cueForResult('notyet', 'auto_scored')).toBe('try-again');
        expect(cueForResult('ok', 'self_assessed')).toBeNull();
        expect(cueForResult('notyet', 'self_assessed')).toBeNull();
        expect(cueForResult('other', 'auto_scored')).toBeNull();
    });
});
