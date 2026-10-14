import { type FormEvent, useCallback, useEffect, useState } from 'react';
import { Link, useNavigate } from 'react-router-dom';
import { api, ApiError } from '../api';
import { Mascot, Say } from '../components/Mascot';
import { Banner, useFocusOnChange } from '../components/ui';
import { say } from '../lib/stepSays';
import type { ExploreState, PathSummary } from '../types';

const LEVELS: [string, string][] = [['new', 'New to it'], ['some', 'Some experience'], ['experienced', 'Experienced']];
const STAGE: Record<string, string> = {
    queued: 'Getting started',
    plan: 'Planning your path',
    'first-mission': 'Writing your first practice',
    draft: 'Writing your first practice',
    gates: 'Checking it',
    verify: 'Checking it a second way',
    publishing: 'Getting it ready',
};

/** "Learn something else" (docs/16 §8): name a topic, get a short path, choose whether to start it. Nothing changes until Start. */
export function ExplorePage() {
    const nav = useNavigate();
    const h = useFocusOnChange('explore');
    const [topic, setTopic] = useState('');
    const [level, setLevel] = useState('some');
    const [state, setState] = useState<ExploreState | null>(null);
    const [paths, setPaths] = useState<PathSummary[]>([]);
    const [error, setError] = useState<string | null>(null);
    const [unavailable, setUnavailable] = useState(false);
    const [busy, setBusy] = useState(false);
    const [ack, setAck] = useState(false);

    const loadPaths = useCallback(async () => {
        try { setPaths((await api.get<{ enrolments: PathSummary[] }>('/enrolments')).enrolments.filter((e) => ['active', 'paused'].includes(e.status))); } catch { /* the list is a convenience */ }
    }, []);

    useEffect(() => {
        void loadPaths();
        api.get<{ requests: ExploreState[] }>('/explore').then((r) => {
            // Pick up where the learner left off: a request still being made, or a ready path they have not started.
            const latest = r.requests[0];
            if (latest && (latest.status === 'working' || (latest.status === 'ready' && !latest.enrolment_id))) setState(latest);
        }).catch((e) => { if (e instanceof ApiError && e.code === 'explore_unavailable') setUnavailable(true); });
    }, [loadPaths]);

    // While a path is being made, ask how it is going. It is a calm wait: no timer, no countdown.
    useEffect(() => {
        if (state?.status !== 'working') return;
        const id = state.id;
        const t = window.setInterval(() => { api.get<ExploreState>(`/explore/${id}`).then(setState).catch(() => undefined); }, 2000);

        return () => window.clearInterval(t);
    }, [state]);

    const submit = async (e: FormEvent) => {
        e.preventDefault();
        setBusy(true);
        setError(null);
        try {
            const r = await api.post<{ id: string; stage: string }>('/explore', { topic, level, ack });
            setState({ id: r.id, status: 'working', stage: r.stage });
        } catch (err) {
            setError(err instanceof ApiError ? (err.fields?.[0]?.message ?? err.message) : 'We could not start that. Please try again.');
        } finally {
            setBusy(false);
        }
    };

    const start = async () => {
        if (state?.status !== 'ready') return;
        setBusy(true);
        setError(null);
        try {
            await api.post(`/explore/${state.id}/start`);
            nav('/today');
        } catch (err) {
            setError(err instanceof ApiError ? err.message : 'We could not start that path.');
            setBusy(false);
        }
    };

    const focus = async (id: string) => {
        setBusy(true);
        try {
            await api.post(`/enrolments/${id}/focus`);
            nav('/today');
        } catch (err) {
            setError(err instanceof ApiError ? err.message : 'We could not switch paths.');
            setBusy(false);
        }
    };

    const working = state?.status === 'working' ? state : null;
    const ready = state?.status === 'ready' ? state : null;
    const trouble = state?.status === 'rejected' || state?.status === 'failed' ? state : null;

    if (unavailable) {
        return (
            <div className="stack empty">
                <Mascot mood="think" size={130} />
                <h1 ref={h} tabIndex={-1}>Your own topic</h1>
                <p>Making a path on your own topic is not available yet. Your roadmap works as usual.</p>
                <Link className="btn" to="/today">Back to Today</Link>
            </div>
        );
    }

    return (
        <div className="stack">
            <h1 ref={h} tabIndex={-1}>Learn something else</h1>

            {!working && !ready ? (
                <>
                    <Say mood={trouble ? 'think' : 'guide'} size={104}>{say(trouble ? 'explore_trouble' : 'explore')}</Say>
                    {trouble && <Banner tone="attention" role="status" title="No path this time.">{trouble.message}</Banner>}
                    <form className="stack" onSubmit={(e) => void submit(e)} noValidate>
                        <div>
                            <label className="field" htmlFor="topic">What do you want to practise?<span className="field-help">A few words about a software skill, for example “Docker networking basics” or “writing clear SQL joins”.</span></label>
                            <input id="topic" type="text" value={topic} maxLength={120} onChange={(e) => setTopic(e.target.value)} autoComplete="off" aria-describedby={error ? 'topic-err' : undefined} aria-invalid={error ? true : undefined} />
                            {error && <p id="topic-err" className="err" role="alert">{error}</p>}
                        </div>
                        <fieldset>
                            <legend>Where are you with it?</legend>
                            {LEVELS.map(([v, label]) => (
                                <label className="choice" key={v}><input type="radio" name="level" checked={level === v} onChange={() => setLevel(v)} /><span>{label}</span></label>
                            ))}
                        </fieldset>
                        <label className="choice">
                            <input type="checkbox" checked={ack} onChange={(e) => setAck(e.target.checked)} />
                            <span>I understand that what I type is sent to an AI service, and that the practice is not reviewed by a person.</span>
                        </label>
                        <button type="submit" className="btn block" disabled={busy || !ack || topic.trim().length < 3}>{busy ? 'Sending…' : 'Make my path'}</button>
                    </form>
                    <section className="card stack" aria-labelledby="how-h">
                        <h2 id="how-h">Before you start</h2>
                        <ul className="plain">
                            <li><strong>A computer writes it.</strong> An AI service writes the practice, and DevStep checks it automatically. A person has not reviewed it.</li>
                            <li><strong>Your evidence says so.</strong> Anything you show from it counts as Practised at most, and it never changes your main roadmap.</li>
                            <li><strong>Your words are sent out.</strong> What you type here goes to the AI service that writes the path. Please do not include secrets, employer code or personal details.</li>
                        </ul>
                    </section>
                </>
            ) : working ? (
                <div className="stack empty" role="status" aria-live="polite">
                    <Mascot mood="think" size={150} />
                    <p><strong>{STAGE[working.stage] ?? 'Working on it'}…</strong></p>
                    <p className="muted">{say('explore_working')}</p>
                </div>
            ) : ready && (
                <>
                    <Say mood="cheer" size={104}>{say('explore_ready')}</Say>
                    <div className="card stack">
                        <h2>{ready.title}</h2>
                        <p>{ready.outcome}</p>
                        <ol className="plain-ol">
                            {ready.topics.map((t) => <li key={t.title}>{t.title} <span className="muted small">· about {t.minutes} min</span></li>)}
                        </ol>
                        <Banner title="Made for you, not reviewed yet.">{ready.coverage_note}</Banner>
                        {error && <p className="err" role="alert">{error}</p>}
                        <button type="button" className="btn block" disabled={busy} onClick={() => void start()}>Start this path</button>
                        <p className="small muted">Your current roadmap is paused with your progress and drafts kept. You can switch back at any time.</p>
                        <button type="button" className="btn secondary" onClick={() => setState(null)}>Not now</button>
                    </div>
                </>
            )}

            {paths.length > 0 && !working && (
                <section className="stack" aria-labelledby="paths-h">
                    <h2 id="paths-h">Your paths</h2>
                    {paths.map((p) => (
                        <div className="card path-row" key={p.id}>
                            <div>
                                <strong>{p.title}</strong>
                                <p className="small muted">{p.progress.completed_required} of {p.progress.total_required} topics{p.made_for_you ? ' · Made for you' : ''}{p.made_for_you && !p.reviewed ? ' · Not reviewed yet' : ''}</p>
                            </div>
                            {p.status === 'active'
                                ? <span className="pill" aria-label="This path is your focus">Your focus</span>
                                : <button type="button" className="btn secondary" disabled={busy} onClick={() => void focus(p.id)}>Make this my focus</button>}
                        </div>
                    ))}
                    {paths.length > 1 && <p className="small muted">Switching keeps every path exactly as you left it, and your reviews carry on across all of them.</p>}
                </section>
            )}
        </div>
    );
}
