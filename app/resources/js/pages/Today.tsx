import { useCallback, useEffect, useState } from 'react';
import { Link, useNavigate } from 'react-router-dom';
import { api, ApiError, track } from '../api';
import { Mascot, type Mood, Say } from '../components/Mascot';
import { playCue } from '../lib/sound';
import { Banner, useFocusOnChange } from '../components/ui';
import { VerifyBanner } from './Account';
import { WeekDots } from '../components/WeekDots';
import { type Context, say } from '../lib/stepSays';
import type { Today as TodayType } from '../types';
import { MODE_LABEL } from '../lib/format';
import { Markdown } from '../lib/markdown';
import type { Action, Mode, Today as TodayData } from '../types';

interface RoadmapPreview { title: string; outcome: string; estimated_effort: string; required_topic_count: number; coverage_note: string }

const GREETING: Record<TodayType['situation'], { mood: Mood; context: Context }> = {
    first_run: { mood: 'guide', context: 'today_first' },
    ready: { mood: 'focus', context: 'today_ready' },
    resume: { mood: 'focus', context: 'today_resume' },
    returning: { mood: 'hello', context: 'today_returning' },
    done_for_today: { mood: 'cheer', context: 'today_done' },
    paused: { mood: 'rest', context: 'today_paused' },
    nothing_ready: { mood: 'rest', context: 'today_nothing' },
    roadmap_complete: { mood: 'cheer', context: 'complete_roadmap' },
};

export function TodayPage({ subjectId }: { subjectId: string }) {
    const nav = useNavigate();
    const h = useFocusOnChange('today');
    const [data, setData] = useState<TodayData | null>(null);
    const [error, setError] = useState<string | null>(null);
    const [busy, setBusy] = useState(false);
    const [preview, setPreview] = useState<RoadmapPreview | null>(null);

    const load = useCallback(async () => {
        try {
            setData(await api.get<TodayData>('/today'));
        } catch (e) {
            setError(e instanceof ApiError ? e.message : 'Today could not be loaded.');
        }
    }, []);
    useEffect(() => { void load(); }, [load]);

    useEffect(() => {
        if (data?.situation === 'returning') track('return_screen_seen', { days_inactive: Math.min(365, data.welcome_back?.days_away ?? 0), offered_small: true }, subjectId);
        if (data?.situation === 'first_run') {
            api.get<{ roadmap: RoadmapPreview }>('/roadmaps/crud-to-reliable').then((r) => setPreview(r.roadmap)).catch(() => undefined);
        }
        if (data) track('recommendation_seen', { recommendation_id: 'present', recommendation_kind: ['resume', 'review', 'mission'].includes(data.primary_action.kind) ? data.primary_action.kind : 'none', offered_smaller: !!data.smaller_option }, subjectId);
    }, [data?.situation, data?.primary_action?.recommendation_id]); // eslint-disable-line react-hooks/exhaustive-deps

    const setPaused = async (paused: boolean) => {
        if (!data?.enrolment) return;
        setBusy(true);
        setError(null);
        try {
            await api.patch(`/enrolments/${data.enrolment.id}`, { status: paused ? 'paused' : 'active' });
            await load();
        } catch (e) {
            setError(e instanceof ApiError ? e.message : 'We could not change that.');
        } finally {
            setBusy(false);
        }
    };

    const start = async (a: Action | null, mode: Mode, origin?: string) => {
        setBusy(true);
        setError(null);
        try {
            if (a?.kind === 'resume' && a.session_id) {
                const r = await api.post<{ session: { id: string } }>('/sessions', { session_id: a.session_id, origin: 'today' });
                nav(`/session/${r.session.id}`);

                return;
            }
            playCue('welcome', { once: 'welcome' }); // once, on an intentional start; never on resume
            const r = await api.post<{ session: { id: string } }>('/sessions', { mode, recommendation_id: a?.recommendation_id ?? undefined, if_open: 'suspend', origin: origin ?? (mode === 'small' ? 'smaller_mode' : 'today') });
            nav(`/session/${r.session.id}`);
        } catch (e) {
            setError(e instanceof ApiError ? e.message : 'We could not start that.');
            setBusy(false);
        }
    };

    const enrol = async () => {
        setBusy(true);
        try {
            await api.post('/enrolments');
            await load();
        } catch (e) {
            setError(e instanceof ApiError ? e.message : 'We could not enrol you.');
        } finally {
            setBusy(false);
        }
    };

    useEffect(() => {
        if (data?.situation === 'roadmap_complete') playCue('milestone', { once: 'roadmap-complete' });
    }, [data?.situation]);

    if (error && !data) return <Banner tone="attention" role="alert" title="Today could not be loaded.">{error}</Banner>;
    if (!data) return <div className="loading" role="status"><Mascot mood="hello" size={110} />Loading Today…</div>;

    const a = data.primary_action;
    const wp = data.weekly_progress;

    return (
        <div className="stack">
            <h1 ref={h} tabIndex={-1}>Today</h1>
            <VerifyBanner />
            <Say mood={GREETING[data.situation].mood} size={104}>{say(GREETING[data.situation].context, data.local_date)}</Say>
            {error && <p className="err" role="alert">{error}</p>}

            {data.situation === 'first_run' && (
                <div className="card primary stack">
                    <h2>From CRUD to Reliable Systems</h2>
                    {preview ? (
                        <>
                            <p>{preview.outcome}</p>
                            <p className="small muted">{preview.required_topic_count} required topics · {preview.estimated_effort}</p>
                            <Banner title="What completing it means.">{preview.coverage_note}</Banner>
                        </>
                    ) : <p>{a.rationale}</p>}
                    <button type="button" className="btn block" disabled={busy} onClick={() => void enrol()}>Enrol in this roadmap</button>
                </div>
            )}

            {data.situation === 'paused' && (
                <div className="card primary stack">
                    <h2>{a.title}</h2>
                    <p>{a.rationale} Your progress and drafts are kept, and nothing is waiting to be caught up.</p>
                    <button type="button" className="btn block" disabled={busy} onClick={() => void setPaused(false)}>Resume</button>
                </div>
            )}

            {data.situation === 'returning' && data.welcome_back && (
                <div className="card primary stack">
                    <h2>Welcome back</h2>
                    <p>Continue your last task or try a three-minute refresher. Your plan moved on; there is nothing to catch up.</p>
                    <p className="small muted">Last time: {data.welcome_back.last_work.title}</p>
                    <button type="button" className="btn block" disabled={busy} onClick={() => void start(a, 'small', 'return_screen')}>Try the 3-minute refresher</button>
                    {data.secondary_actions[0] && (
                        <button type="button" className="btn secondary block" disabled={busy} onClick={() => void start(data.secondary_actions[0], data.secondary_actions[0].mode ?? 'practise')}>
                            {data.secondary_actions[0].kind === 'resume' ? 'Continue' : "Today's plan"}: {data.secondary_actions[0].title}
                        </button>
                    )}
                </div>
            )}

            {['ready', 'resume'].includes(data.situation) && (
                <div className="card primary stack">
                    <div>
                        {a.context && <p className="context">{a.context}</p>}
                        <h2>{a.title}</h2>
                        <p className="muted small">
                            {a.estimated_minutes ? `About ${a.estimated_minutes} min` : ''}{a.mode ? ` · ${MODE_LABEL[a.mode]}` : ''}
                            {a.reviews_included > 0 ? ` · Includes ${a.reviews_included} short ${a.reviews_included === 1 ? 'review' : 'reviews'}` : ''}
                        </p>
                    </div>
                    <p><strong>Why: </strong><Markdown source={a.rationale} inline /></p>
                    <button type="button" className="btn block" disabled={busy} onClick={() => void start(a, a.mode ?? 'practise')}>
                        {data.situation === 'resume' ? 'Continue' : 'Start'}
                    </button>
                </div>
            )}

            {data.situation === 'done_for_today' && (
                <div className="card primary stack">
                    <h2>Done for today</h2>
                    <p>That is practice. You can stop here; nothing is waiting to be caught up.</p>
                    <button type="button" className="btn secondary" disabled={busy} onClick={() => void start(null, 'practise')}>Practise anyway{a.estimated_minutes ? ` (about ${a.estimated_minutes} min)` : ''}</button>
                </div>
            )}

            {['nothing_ready', 'roadmap_complete'].includes(data.situation) && (
                <div className="card primary"><h2>{a.title}</h2><p>{a.rationale}</p></div>
            )}

            {data.smaller_option && ['ready'].includes(data.situation) && (
                <p><button type="button" className="btn secondary" disabled={busy} onClick={() => void start(data.smaller_option, 'small')}>Start small · about {data.smaller_option.estimated_minutes} min</button></p>
            )}
            {data.situation !== 'returning' && data.secondary_actions.map((s) => (
                <p key={s.session_id ?? s.title}><button type="button" className="btn secondary" disabled={busy} onClick={() => void start(s, s.mode ?? 'practise')}>Continue: {s.title}</button></p>
            ))}

            {data.enrolment && (
                <div className="card">
                    <WeekDots weekStart={wp.week_start} dates={wp.practice_dates} today={data.local_date} target={wp.practice_days_target} />
                    <p className="caption" style={{ marginTop: 10 }}><Link to="/roadmap">{data.enrolment.completed_required} of {data.enrolment.total_required} required topics</Link></p>
                </div>
            )}
        </div>
    );
}
