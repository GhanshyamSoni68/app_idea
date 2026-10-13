import { describe, expect, it } from 'vitest';
import { LINES, say } from './stepSays';

// Words the docs forbid in any learner-facing copy: guilt, fear, comparison, missed-work counts, streak pressure,
// and mastery claims after one exercise (PRD §1, §6, §9; docs/08 §7.2; docs/12 "Never").
const FORBIDDEN = /\b(missed|behind|overdue|streak|you lost|you've lost|lost your|lose your|hurry|falling|disappoint\w*|sad|miss you|give up|obsolete|everyone|other learners|leaderboard|mastered|master|genius|perfect|wrong|fail\w*|lazy|must|deadline|last chance|don't lose)\b/i;

describe('Step never guilts, compares or over-claims', () => {
    for (const [context, lines] of Object.entries(LINES)) {
        for (const line of lines) {
            it(`${context}: "${line.slice(0, 40)}…"`, () => {
                expect(line).not.toMatch(FORBIDDEN);
                expect(line).not.toMatch(/!/); // no exclamation marks near assessment (docs/08 §7.1); calm everywhere
                expect(line).not.toMatch(/\p{Extended_Pictographic}/u); // no emoji in learning surfaces
                expect(line.length).toBeLessThanOrEqual(120);
            });
        }
    }

    it('does not count days or tasks the learner has not done', () => {
        for (const line of Object.values(LINES).flat()) expect(line).not.toMatch(/\b\d+ (days?|tasks?|sessions?)\b/i);
    });

    it('is deterministic for a context and a day, and varies with the seed', () => {
        expect(say('today_ready', '2026-10-13')).toBe(say('today_ready', '2026-10-13'));
        const seen = new Set(['2026-10-13', '2026-10-14', '2026-10-15', '2026-10-16', '2026-10-17', '2026-10-18'].map((d) => say('today_ready', d)));
        expect(seen.size).toBeGreaterThan(1);
    });
});
