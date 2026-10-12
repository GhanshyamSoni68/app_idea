import { beforeEach, describe, expect, it } from 'vitest';
import { claimBody, clearGuest, expired, hasGuestWork, loadGuest, peekGuest, saveGuest } from './guestStore';

beforeEach(() => {
    localStorage.clear();
    clearGuest();
});

describe('guestStore', () => {
    it('creates a bundle with a random subject id and keeps it between loads', () => {
        const a = loadGuest();
        expect(a.subjectId).toMatch(/^[0-9a-f-]{36}$/);
        expect(loadGuest().subjectId).toBe(a.subjectId);
    });

    it('deletes an unclaimed bundle after 30 days and starts fresh', () => {
        const a = loadGuest();
        const day31 = Date.now() + 31 * 86_400_000;
        expect(expired(a, day31)).toBe(true);
        const b = loadGuest(day31);
        expect(b.subjectId).not.toBe(a.subjectId);
    });

    it('keeps a bundle that is 29 days old', () => {
        const a = loadGuest();
        expect(loadGuest(Date.now() + 29 * 86_400_000).subjectId).toBe(a.subjectId);
    });

    it('asking whether guest work exists never writes anything', () => {
        expect(hasGuestWork()).toBe(false);
        expect(peekGuest()).toBeNull();
        expect(localStorage.getItem('devstep.guest.v1')).toBeNull();
    });

    it('survives unreadable storage by starting fresh', () => {
        localStorage.setItem('devstep.guest.v1', '{not json');
        expect(loadGuest().v).toBe(1);
    });

    it('sends only answers and counts in the claim, never its own results', () => {
        const b = loadGuest();
        b.sample = {
            contentVersionId: 'cv', step: 2, hintsByItem: {}, revealedByItem: {},
            attempts: {
                i1: { itemId: 'i1', stepKey: 'first-move', answer: { choice: 'c' }, answeredAt: '2026-10-12T19:20:00Z', hintsUsed: 1, revealed: false, feedbackViewed: true, clientAttemptId: 'x',
                    result: { outcome: 'correct', assistance: 'hint', hint_count: 1, evidence_basis: 'auto_scored', feedback: { text: 'secret' } } },
            },
        };
        saveGuest(b);
        const body = claimBody(b);
        expect(JSON.stringify(body)).not.toContain('secret');
        expect(JSON.stringify(body)).not.toContain('outcome');
        expect(body.sample.attempts[0]).toMatchObject({ assessment_item_id: 'i1', hints_used: 1, feedback_viewed: true });
    });
});
