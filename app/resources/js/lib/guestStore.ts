// Guest work stays ONLY in this browser until sign-up (docs/04 §7, docs/08 §2.4). The server stores no
// guest rows. An unclaimed bundle is deleted from this browser after 30 days. If browser storage is
// blocked the work lives in memory and the UI warns that it is lost when the tab closes.

import type { AttemptResult, Mode } from '../types';

const KEY = 'devstep.guest.v1';
export const GUEST_TTL_DAYS = 30;

export interface Onboarding {
    time_zone: string;
    available_days: number[];
    preferred_mode: Mode;
    weekly_session_target: number;
    stack_tags: string[];
    goal: { outcome_key: string; note?: string | null };
}

export interface GuestAttempt {
    itemId: string;
    stepKey: string;
    answer: Record<string, unknown>;
    answeredAt: string;
    hintsUsed: number;
    revealed: boolean;
    feedbackViewed: boolean;
    clientAttemptId: string;
    result: AttemptResult;
}

export interface GuestBundle {
    v: 1;
    subjectId: string;
    createdAt: string;
    onboarding?: Onboarding;
    sample?: {
        contentVersionId: string;
        step: number;
        attempts: Record<string, GuestAttempt>;
        hintsByItem: Record<string, number>;
        revealedByItem: Record<string, boolean>;
        drafts?: Record<string, Record<string, unknown>>;
        completedAt?: string;
    };
    claimKey?: string;
}

let memory: GuestBundle | null = null;
let persistent = true;

function storage(): Storage | null {
    try {
        const s = window.localStorage;
        s.setItem('devstep.probe', '1');
        s.removeItem('devstep.probe');

        return s;
    } catch {
        return null;
    }
}

/** False when storage is blocked: guest answers are then lost when the tab closes (docs/08 A13). */
export function isPersistent(): boolean {
    storage();

    return persistent;
}

function fresh(): GuestBundle {
    return { v: 1, subjectId: crypto.randomUUID(), createdAt: new Date().toISOString() };
}

export function expired(b: GuestBundle, now = Date.now()): boolean {
    return now - new Date(b.createdAt).getTime() > GUEST_TTL_DAYS * 86_400_000;
}

export function loadGuest(now = Date.now()): GuestBundle {
    const s = storage();
    persistent = s !== null;
    if (!s) {
        memory ??= fresh();

        return memory;
    }
    try {
        const raw = s.getItem(KEY);
        if (raw) {
            const b = JSON.parse(raw) as GuestBundle;
            if (b?.v === 1 && b.subjectId && !expired(b, now)) return b;
            s.removeItem(KEY); // unclaimed for 30 days (or unreadable): deleted, the learner starts fresh
        }
    } catch {
        s.removeItem(KEY);
    }
    const b = fresh();
    saveGuest(b);

    return b;
}

export function saveGuest(b: GuestBundle): void {
    memory = b;
    const s = storage();
    if (!s) return;
    try {
        s.setItem(KEY, JSON.stringify(b));
    } catch {
        persistent = false;
    }
}

/** Reads the stored bundle without creating one: asking whether guest work exists must not write anything. */
export function peekGuest(now = Date.now()): GuestBundle | null {
    const s = storage();
    if (!s) return memory;
    try {
        const raw = s.getItem(KEY);
        if (!raw) return null;
        const b = JSON.parse(raw) as GuestBundle;
        if (b?.v === 1 && b.subjectId && !expired(b, now)) return b;
        s.removeItem(KEY);
    } catch {
        s.removeItem(KEY);
    }

    return null;
}

export function hasGuestWork(): boolean {
    return Object.keys(peekGuest()?.sample?.attempts ?? {}).length > 0;
}

/** Build the claim request body. The server ignores our own results and re-evaluates every answer. */
export function claimBody(b: GuestBundle) {
    return {
        subject_id: b.subjectId,
        onboarding: b.onboarding,
        sample: {
            content_version_id: b.sample?.contentVersionId,
            attempts: Object.values(b.sample?.attempts ?? {}).map((a) => ({
                assessment_item_id: a.itemId,
                answer: a.answer,
                answered_at: a.answeredAt,
                client_attempt_id: a.clientAttemptId,
                hints_used: a.hintsUsed,
                revealed: a.revealed,
                feedback_viewed: a.feedbackViewed,
            })),
        },
    };
}

export function clearGuest(): void {
    memory = null;
    try {
        window.localStorage.removeItem(KEY);
    } catch {
        /* nothing to clear */
    }
}
