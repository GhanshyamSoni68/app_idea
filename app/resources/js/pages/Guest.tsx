import { type ReactNode, useEffect, useMemo, useRef, useState } from 'react';
import { Link, useNavigate } from 'react-router-dom';
import { api, ApiError, track, uuid } from '../api';
import { OnboardingForm } from '../components/OnboardingForm';
import { type PlayerEngine, Player, type PlayerStep } from '../components/Player';
import { Mascot, Say } from '../components/Mascot';
import { Banner, useFocusOnChange } from '../components/ui';
import { say } from '../lib/stepSays';
import { GUEST_TTL_DAYS, type GuestAttempt, isPersistent, loadGuest, saveGuest } from '../lib/guestStore';
import type { AttemptResult, SampleMission } from '../types';

const PILOT_URL: string | null = (window as unknown as { __DEVSTEP__?: { pilotFormUrl?: string | null } }).__DEVSTEP__?.pilotFormUrl ?? null;

export function Landing() {
    const h = useFocusOnChange('landing');
    return (
        <div className="stack">
            <div className="hero">
                <div className="art"><Mascot mood="hello" size={230} /></div>
                <div className="stack">
                    <h1 ref={h} tabIndex={-1}>Know what to practise next, and see proof you are improving.</h1>
                    <p className="lead">{say('landing')} Start with the time you have, even three minutes.</p>
                    <p><Link className="btn" to="/start">Try a sample scenario</Link></p>
                    <p className="muted small">About 8 minutes. No account needed. What you do stays in this browser on this device.</p>
                    <p>Already have an account? <Link to="/sign-in">Sign in</Link></p>
                </div>
            </div>
            <ul className="perks">
                <li><span className="ico" aria-hidden="true">1</span><span>One small, concrete task at a time, tied to one continuing engineering scenario.</span></li>
                <li><span className="ico" aria-hidden="true">2</span><span>Questions come back until you can apply the idea without help.</span></li>
                <li><span className="ico" aria-hidden="true">3</span><span>Honest evidence: what you showed, with dates, and what it does not prove.</span></li>
            </ul>
            <Banner title="Pilot invite needed to keep your progress.">DevStep is in a small invited pilot. You can try the sample now and join later if you have an invite.</Banner>
        </div>
    );
}

export function GuestStart() {
    const nav = useNavigate();
    const h = useFocusOnChange('start');
    return (
        <div className="stack">
            <h1 ref={h} tabIndex={-1}>A few quick questions</h1>
            <Say mood="think" size={96}>{say('onboarding')}</Say>
            <OnboardingForm submitLabel="Continue to the sample" onDone={(o) => {
                const b = loadGuest();
                saveGuest({ ...b, onboarding: o });
                nav('/sample');
            }} />
        </div>
    );
}

export function GuestSample() {
    const nav = useNavigate();
    const [sample, setSample] = useState<SampleMission | null>(null);
    const [error, setError] = useState<string | null>(null);
    const bundleRef = useRef(loadGuest());
    const [, force] = useState(0);

    useEffect(() => {
        api.get<{ sample: SampleMission }>('/guest/sample').then((r) => {
            setSample(r.sample);
            const b = bundleRef.current;
            if (!b.sample || b.sample.contentVersionId !== r.sample.content_version_id) {
                // A newer sample version replaces any unfinished work on the old one.
                bundleRef.current = { ...b, sample: { contentVersionId: r.sample.content_version_id, step: 0, attempts: {}, hintsByItem: {}, revealedByItem: {} } };
                saveGuest(bundleRef.current);
            }
            if (!sessionStorage.getItem('devstep.sample.tracked')) {
                sessionStorage.setItem('devstep.sample.tracked', '1');
                track('sample_started', {}, b.subjectId);
            }
            force((n) => n + 1);
        }).catch((e) => setError(e instanceof ApiError ? e.message : 'The sample could not be loaded.'));
    }, []);

    const engine = useMemo<PlayerEngine | null>(() => {
        if (!sample) return null;
        const update = (fn: (s: NonNullable<typeof bundleRef.current.sample>) => void) => {
            const b = bundleRef.current;
            fn(b.sample!);
            saveGuest(b);
        };
        const keyToStepIdx = (key: string) => sample.steps.findIndex((s) => s.key === key);

        return {
            async check(step, answer) {
                const item = step.item!;
                const r = await api.post<AttemptResult>('/guest/attempts', { assessment_item_id: item.content_version_id, content_version_id: sample.content_version_id, answer });
                const s = bundleRef.current.sample!;
                const hints = s.hintsByItem[item.content_version_id] ?? 0;
                const revealed = !!s.revealedByItem[item.content_version_id];
                const result: AttemptResult = { ...r, assistance: revealed ? 'solution_revealed' : hints > 0 ? 'hint' : 'none', hint_count: hints };
                const attempt: GuestAttempt = { itemId: item.content_version_id, stepKey: step.key, answer, answeredAt: new Date().toISOString(), hintsUsed: hints, revealed, feedbackViewed: false, clientAttemptId: uuid(), result };
                update((sm) => { sm.attempts[item.content_version_id] = attempt; });
                track('first_answer_submitted', { assessment_item_id: item.content_version_id, seconds_since_session_start: 0 }, bundleRef.current.subjectId);
                track('attempt_evaluated', { assessment_item_id: item.content_version_id, outcome: result.outcome, assistance: result.assistance, hint_count: hints }, bundleRef.current.subjectId);

                return result;
            },
            async hint(step, n) {
                const item = step.item!;
                const r = await api.post<{ text: string; remaining: number }>('/guest/hints', { assessment_item_id: item.content_version_id, content_version_id: sample.content_version_id, hint_number: n });
                update((sm) => { sm.hintsByItem[item.content_version_id] = Math.max(sm.hintsByItem[item.content_version_id] ?? 0, n); });

                return r;
            },
            async reveal(step) {
                const item = step.item!;
                const r = await api.post<{ text: string }>('/guest/reveal', { assessment_item_id: item.content_version_id, content_version_id: sample.content_version_id });
                update((sm) => { sm.revealedByItem[item.content_version_id] = true; });

                return r.text;
            },
            async advance(toIndex) {
                update((sm) => {
                    sm.step = toIndex;
                    for (const a of Object.values(sm.attempts)) if (keyToStepIdx(a.stepKey) < toIndex) a.feedbackViewed = true;
                });
            },
            async finish() {
                update((sm) => {
                    for (const a of Object.values(sm.attempts)) a.feedbackViewed = true;
                    sm.completedAt = new Date().toISOString();
                });
                nav('/sample/done');
            },
            onDraft(stepKey, answer) { update((sm) => { sm.drafts = { ...(sm.drafts ?? {}), [stepKey]: answer }; }); },
            async saveAndExit() { nav('/'); },
        };
    }, [sample, nav]);

    if (error) return <Banner tone="attention" role="alert" title="Something went wrong.">{error}</Banner>;
    if (!sample || !engine) return <p role="status">Loading the sample…</p>;

    const sm = bundleRef.current.sample!;
    const steps: PlayerStep[] = sample.steps.map((s) => {
        const a = s.item ? sm.attempts[s.item.content_version_id] : undefined;

        return { ...s, attempt: a?.result ?? null };
    });
    const notice: ReactNode = (
        <div className="stack" style={{ margin: '0 0 16px' }}>
            <Banner title="Saved only in this browser on this device.">Until you create an account. If you do not, it is deleted from this browser after {GUEST_TTL_DAYS} days.</Banner>
            {!isPersistent() && <Banner tone="attention" role="alert" title="Your browser is blocking storage.">Your answers will be lost when this tab closes.</Banner>}
        </div>
    );

    return (
        <Player
            steps={steps}
            startIndex={Math.min(sm.step, steps.length - 1)}
            title={sample.title}
            context="Sample scenario"
            modeLabel="Practise"
            engine={engine}
            initialAnswers={sm.drafts as never}
            banners={notice}
            finishLabel="Finish the sample"
            draftStatus="Draft kept on this device"
        />
    );
}

export function SampleDone() {
    const h = useFocusOnChange('sample-done');
    const b = loadGuest();
    const done = Object.keys(b.sample?.attempts ?? {}).length;
    return (
        <div className="stack">
            <div className="celebrate">
                <Mascot mood="cheer" size={170} />
                <h1 ref={h} tabIndex={-1}>Sample complete</h1>
            </div>
            <p>One real scenario, {done} decisions and their feedback. {say('sample_done')} You can stop here.</p>
            <div className="card">
                <h2>Where this is saved</h2>
                <p>Only in this browser on this device until you create an account. If you do not, it is deleted from this browser after {GUEST_TTL_DAYS} days. Clearing browser data or closing a private window loses it sooner.</p>
                <Link className="btn block" to="/sign-up">Create an account to keep it</Link>
                <p className="small muted" style={{ marginTop: 8 }}>Needs a pilot invite. Your answers are checked again and move into your account as practice.</p>
            </div>
            <p>
                {PILOT_URL ? <>No invite? <a href={PILOT_URL}>Ask to join the pilot</a>.</> : 'No invite yet? Ask the person who shared DevStep with you.'}
            </p>
            <p><Link to="/">Not now</Link> · Already have an account? <Link to="/sign-in">Sign in</Link></p>
        </div>
    );
}
