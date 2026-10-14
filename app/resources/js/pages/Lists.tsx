import { useCallback, useEffect, useState } from 'react';
import { useNavigate } from 'react-router-dom';
import { api, ApiError } from '../api';
import { Avatar, Mascot, Say } from '../components/Mascot';
import { Banner, Dialog, LevelMarker, ProgressCount, useFocusOnChange } from '../components/ui';
import { playCue } from '../lib/sound';
import type { Cue } from '../lib/soundPolicy';
import { say } from '../lib/stepSays';
import { assistanceLabel, BASIS_LABEL, formatDate, LEVEL_EXPLAIN, LIMITATION_LABEL, TOPIC_STATE } from '../lib/format';
import type { EvidenceItem, Level, RoadmapTopic, RoadmapView } from '../types';

export function RoadmapPage() {
    const nav = useNavigate();
    const h = useFocusOnChange('roadmap');
    const [view, setView] = useState<RoadmapView | null>(null);
    const [error, setError] = useState<string | null>(null);
    const [notice, setNotice] = useState<string | null>(null);
    const [busy, setBusy] = useState(false);
    const [challenge, setChallenge] = useState<RoadmapTopic | null>(null);

    const load = useCallback(async () => {
        try {
            const list = await api.get<{ enrolments: { id: string; status: string }[] }>('/enrolments');
            const current = list.enrolments.find((e) => ['active', 'paused'].includes(e.status));
            if (!current) { setError('You have not enrolled in a roadmap yet. Start from Today.'); return; }
            setView(await api.get<RoadmapView>(`/enrolments/${current.id}`));
        } catch (e) {
            setError(e instanceof ApiError ? e.message : 'The roadmap could not be loaded.');
        }
    }, []);
    useEffect(() => { void load(); }, [load]);

    const act = async (fn: () => Promise<unknown>, message: string, cue?: Cue) => {
        setBusy(true);
        setNotice(null);
        try {
            await fn();
            setNotice(message);
            if (cue) playCue(cue);
            await load();
        } catch (e) {
            setNotice(e instanceof ApiError ? e.message : 'We could not do that.');
        } finally {
            setBusy(false);
        }
    };

    const startChallenge = async () => {
        if (!challenge) return;
        setBusy(true);
        try {
            const r = await api.post<{ session: { id: string } }>(`/topics/${challenge.id}/challenge`);
            nav(`/session/${r.session.id}`);
        } catch (e) {
            setNotice(e instanceof ApiError ? e.message : 'We could not start the challenge.');
            setChallenge(null);
            setBusy(false);
        }
    };

    if (error) return <Banner role="alert" tone="attention" title="Roadmap">{error}</Banner>;
    if (!view) return <div className="loading" role="status"><Mascot mood="hello" size={110} />Loading the roadmap…</div>;

    const paused = view.enrolment.status === 'paused';
    // The module holding the first unfinished required topic is open; later modules say Preview (docs/08 W09).
    const hereId = view.modules.flatMap((m) => m.topics).find((t) => t.kind === 'required' && t.state !== 'completed' && t.has_content)?.id;
    const current = view.modules.findIndex((m) => m.topics.some((t) => t.kind === 'required' && t.state !== 'completed'));

    return (
        <div className="stack">
            <h1 ref={h} tabIndex={-1}>Roadmap</h1>
            <Say mood={paused ? 'rest' : 'guide'} size={100}>{say(paused ? 'paused_roadmap' : 'roadmap')}</Say>
            <div className="card path-head">
                <h2>{view.roadmap.title}</h2>
                <p className="muted small">Version {view.roadmap.version} · {view.roadmap.estimated_effort}</p>
                <ProgressCount done={view.progress.completed_required} total={view.progress.total_required} percent={view.progress.percent} />
                <p className="small muted">{view.roadmap.coverage_note} Skills are in Evidence.</p>
                <div className="row">
                    {paused
                        ? <button type="button" className="btn" disabled={busy} onClick={() => void act(() => api.patch(`/enrolments/${view.enrolment.id}`, { status: 'active' }), 'Resumed. Your reviews moved forward, so nothing is waiting.')}>Resume roadmap</button>
                        : <button type="button" className="btn secondary" disabled={busy} onClick={() => void act(() => api.patch(`/enrolments/${view.enrolment.id}`, { status: 'paused' }), 'Paused. Your progress and drafts are kept.', 'rest')}>Pause roadmap</button>}
                </div>
            </div>
            {paused && <Banner title="Paused.">Your progress and drafts are kept. Reviews wait with you and nothing piles up.</Banner>}
            {notice && <p role="status" className="muted">{notice}</p>}
            {view.modules.map((m, mi) => {
                const done = m.topics.filter((t) => t.kind === 'required' && t.state === 'completed').length;
                const total = m.topics.filter((t) => t.kind === 'required').length;

                return (
                    <details className="module" key={m.module_key} open={mi === Math.max(current, 0)}>
                        <summary><span className="num" aria-hidden="true">{mi + 1}</span>{m.title} <span className="muted small">{done}/{total}{mi > current && current >= 0 && done === 0 ? ' · Preview' : ''}</span></summary>
                        <ol className="topics">
                        {m.topics.map((t) => {
                            const st = TOPIC_STATE[t.state];

                            return (
                                <li className={`topic ${t.state}`} key={t.id}>
                                    <span className="node" aria-hidden="true">{t.state === 'completed' ? '✓' : t.state === 'in_progress' ? '…' : t.state === 'deferred' ? '–' : ''}</span>
                                    <div>
                                    <div className="t-head">
                                        {t.id === hereId && <Avatar size={30} />}
                                        <strong>{t.title}</strong>
                                        <span className="muted small">{st.label}{t.completion_path === 'challenge_out' ? ' by challenge' : ''}</span>
                                        {t.kind === 'optional' && <span className="tag">Optional</span>}
                                    </div>
                                    <div className="small muted">About {t.estimated_minutes} min{!t.has_content ? ' · Content opening soon' : ''}</div>
                                    {t.unmet_prerequisites.length > 0 && t.state !== 'completed' && (
                                        <div className="needs">Builds on: {t.unmet_prerequisites.map((p) => p.title).join(', ')}{t.unmet_prerequisites.some((p) => p.strict) ? ' (finish this first)' : ' (helpful first, not required)'}</div>
                                    )}
                                    {t.state !== 'completed' && <div className="needs">Done when: attempt, feedback review and a topic check on a new scenario.</div>}
                                    {t.state === 'deferred' && <div className="needs">Deferred, no credit. Come back or take the challenge any time.</div>}
                                    {t.state !== 'completed' && !paused && (
                                        <div className="row">
                                            {t.challenge_available && <button type="button" className="link" disabled={busy} onClick={() => setChallenge(t)}>I already know this</button>}
                                            {t.state === 'deferred'
                                                ? <button type="button" className="link" disabled={busy} onClick={() => void act(() => api.del(`/topics/${t.id}/defer`), `${t.title} is back on your list.`)}>Bring back</button>
                                                : <button type="button" className="link" disabled={busy} onClick={() => void act(() => api.post(`/topics/${t.id}/defer`), `Deferred, no credit. Come back or take the challenge any time.`)}>Defer</button>}
                                        </div>
                                    )}
                                    </div>
                                </li>
                            );
                        })}
                        </ol>
                    </details>
                );
            })}

            <Dialog open={challenge !== null} title="Take the challenge?" onClose={() => setChallenge(null)}>
                <p>Two short questions on new scenarios, no hints, no solution and no timer. If you meet both, {challenge?.title} is completed. If not, nothing is lost: the mission is next, and you will know where to start.</p>
                <p className="small muted">You get one attempt per topic.</p>
                <div className="stack">
                    <button type="button" className="btn block" autoFocus disabled={busy} onClick={() => void startChallenge()}>Start the challenge</button>
                    <button type="button" className="btn secondary block" onClick={() => setChallenge(null)}>Not now</button>
                </div>
            </Dialog>
        </div>
    );
}

const ORDER: Level[] = ['retained', 'demonstrated', 'practised', 'introduced'];

export function EvidencePage() {
    const h = useFocusOnChange('evidence');
    const [items, setItems] = useState<EvidenceItem[] | null>(null);
    const [error, setError] = useState<string | null>(null);

    useEffect(() => {
        api.get<{ evidence: EvidenceItem[] }>('/evidence').then((r) => setItems(r.evidence)).catch((e) => setError(e instanceof ApiError ? e.message : 'Evidence could not be loaded.'));
    }, []);

    if (error) return <Banner role="alert" tone="attention" title="Evidence">{error}</Banner>;
    if (!items) return <div className="loading" role="status"><Mascot mood="hello" size={110} />Loading…</div>;

    return (
        <div className="stack">
            <h1 ref={h} tabIndex={-1}>Evidence</h1>
            {items.length > 0 && <Say mood="idea" size={96}>{say('evidence')}</Say>}
            <p className="muted">What you can show, with dates and limits. It is separate from roadmap completion, and levels never go down.</p>
            {items.length === 0 && (
                <div className="empty card">
                    <Mascot mood="think" size={130} />
                    <p><strong>Nothing here yet.</strong></p>
                    <p className="muted">Evidence appears once you have attempted a task and reviewed its feedback. Reading alone never raises a level.</p>
                </div>
            )}
            {ORDER.map((level) => {
                const group = items.filter((i) => i.level === level);
                if (group.length === 0) return null;

                return (
                    <section className="level-group" key={level} aria-labelledby={`g-${level}`}>
                        <h2 id={`g-${level}`}><LevelMarker level={level} /> · {group.length} {group.length === 1 ? 'skill' : 'skills'}</h2>
                        <p className="small muted">{LEVEL_EXPLAIN[level]}</p>
                        {group.map((i) => (
                            <div className="skill" key={i.skill_id}>
                                <strong>{i.title}</strong>
                                <div className="small">{BASIS_LABEL[i.basis]}</div>
                                <div className="small muted">
                                    {assistanceLabel(i.assistance)}. Last confirmed {formatDate(i.last_confirmed_at)}.
                                    {i.limitations.some((l) => LIMITATION_LABEL[l]) && ` ${i.limitations.filter((l) => LIMITATION_LABEL[l]).map((l) => LIMITATION_LABEL[l]).join('. ')}.`}
                                </div>
                                {i.refresh_suggested && <div className="small"><span className="tag">Refresh suggested</span> A later check was not met. Your level stands.</div>}
                                {i.next_practice_on && <div className="small muted">Next practice: from {formatDate(i.next_practice_on)}</div>}
                            </div>
                        ))}
                    </section>
                );
            })}
        </div>
    );
}
