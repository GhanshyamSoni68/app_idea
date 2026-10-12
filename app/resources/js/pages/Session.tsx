import { useCallback, useEffect, useMemo, useRef, useState } from 'react';
import { Link, useLocation, useNavigate, useParams } from 'react-router-dom';
import { api, ApiError, uuid } from '../api';
import type { Answer } from '../components/ItemInput';
import { type PlayerEngine, Player } from '../components/Player';
import { Banner, LevelMarker, ProgressCount, useFocusOnChange } from '../components/ui';
import { assistanceLabel, BASIS_LABEL, formatDate, LEVEL_EXPLAIN, MODE_LABEL } from '../lib/format';
import type { CompleteResult, SessionPayload } from '../types';

type SaveState = 'idle' | 'saving' | 'saved' | 'unsynced' | 'failed';
interface ServerDraft { revision: number; data: Record<string, unknown>; saved_at: string; device_label: string | null }

const STATUS_TEXT: Record<SaveState, string> = { idle: '', saving: 'Saving…', saved: 'Saved', unsynced: 'Saved on this device, not synced', failed: "Couldn't save, retrying" };

function deviceLabel(): string {
    const ua = navigator.userAgent;
    const os = /iPhone|iPad/.test(ua) ? 'iPhone' : /Android/.test(ua) ? 'Android phone' : /Mac/.test(ua) ? 'Mac' : /Windows/.test(ua) ? 'Windows PC' : /Linux/.test(ua) ? 'Linux PC' : 'This device';
    return `${os} browser`;
}

export function SessionPage() {
    const { id = '' } = useParams();
    const nav = useNavigate();
    const [payload, setPayload] = useState<SessionPayload | null>(null);
    const [error, setError] = useState<string | null>(null);

    useEffect(() => {
        api.get<SessionPayload>(`/sessions/${id}`).then(setPayload).catch((e) => setError(e instanceof ApiError ? e.message : 'We could not open that session.'));
    }, [id]);

    if (error) return <Banner tone="attention" role="alert" title="That session could not be opened.">{error} <Link to="/today">Back to Today</Link></Banner>;
    if (!payload) return <p role="status">Opening your session…</p>;
    if (payload.session.status === 'completed') {
        nav(`/session/${id}/done`, { replace: true });

        return null;
    }

    return <Loaded payload={payload} />;
}

function Loaded({ payload }: { payload: SessionPayload }) {
    const nav = useNavigate();
    const id = payload.session.id;
    const localKey = `devstep.draft.${id}`;
    const local = useMemo(() => {
        try { return JSON.parse(localStorage.getItem(localKey) ?? 'null') as { answers: Record<string, Answer>; unsynced: boolean } | null; } catch { return null; }
    }, [localKey]);
    const serverAnswers = (payload.draft?.data?.answers ?? {}) as Record<string, Answer>;
    const [answers] = useState<Record<string, Answer>>({ ...serverAnswers, ...(local?.unsynced ? local.answers : {}) });
    const answersRef = useRef(answers);
    const rev = useRef(payload.draft?.revision ?? 0);
    const stepIdx = useRef(payload.session.current_step);
    const timer = useRef<ReturnType<typeof setTimeout>>(undefined);
    const attemptKeys = useRef<Record<string, string>>({});
    const completeKey = useRef(uuid());
    const device = useRef(deviceLabel());
    const [status, setStatus] = useState<SaveState>(local?.unsynced ? 'unsynced' : 'idle');
    const [conflict, setConflict] = useState<ServerDraft | null>(null);

    const persistLocal = (unsynced: boolean) => {
        try { localStorage.setItem(localKey, JSON.stringify({ answers: answersRef.current, unsynced })); } catch { /* storage blocked: the server draft still works */ }
    };

    const saveDraft = useCallback(async (toStep: number): Promise<void> => {
        persistLocal(true);
        setStatus('saving');
        try {
            const r = await api.put<{ revision: number }>(`/sessions/${id}/draft`, {
                base_revision: rev.current, save_id: uuid(), step_index: toStep, data: { answers: answersRef.current }, device_label: device.current,
            });
            rev.current = r.revision;
            persistLocal(false);
            setStatus('saved');
        } catch (e) {
            if (e instanceof ApiError && e.code === 'draft_conflict') {
                setConflict((e.details?.server as ServerDraft | undefined) ?? null);
                setStatus('failed');
            } else {
                setStatus(e instanceof ApiError && e.code === 'offline' ? 'unsynced' : 'failed');
            }
        }
    }, [id]); // eslint-disable-line react-hooks/exhaustive-deps

    useEffect(() => () => clearTimeout(timer.current), []);

    const engine: PlayerEngine = useMemo(() => {
        const idxOf = (key: string) => payload.steps.find((s) => s.key === key)!.index;

        return {
            async check(step, answer) {
                attemptKeys.current[step.key] ??= uuid();
                const s = payload.steps.find((x) => x.key === step.key)!;
                const r = await api.post<SessionPayload['steps'][number]['attempt'] & object>(`/sessions/${id}/attempts`, {
                    step_index: s.index, assessment_item_id: s.item!.content_version_id, content_version_id: s.content_version_id, answer,
                }, attemptKeys.current[step.key]);

                return r as never;
            },
            hint: (step, n) => api.post(`/sessions/${id}/hints`, { step_index: idxOf(step.key), hint_number: n }),
            reveal: async (step) => (await api.post<{ text: string }>(`/sessions/${id}/reveal`, { step_index: idxOf(step.key), confirm: true })).text,
            async advance(toIndex) {
                stepIdx.current = toIndex;
                clearTimeout(timer.current);
                await saveDraft(toIndex); // moving past a feedback step records "feedback reviewed"
            },
            async finish() {
                clearTimeout(timer.current);
                await saveDraft(stepIdx.current);
                const r = await api.post<CompleteResult>(`/sessions/${id}/complete`, { base_revision: rev.current }, completeKey.current);
                try { localStorage.removeItem(localKey); } catch { /* nothing to remove */ }
                nav(`/session/${id}/done`, { state: r });
            },
            onDraft(stepKey, answer) {
                answersRef.current = { ...answersRef.current, [stepKey]: answer };
                persistLocal(true);
                setStatus('saving');
                clearTimeout(timer.current);
                timer.current = setTimeout(() => void saveDraft(idxOf(stepKey)), 800);
            },
            async saveAndExit() {
                clearTimeout(timer.current);
                await saveDraft(stepIdx.current);
                nav('/today');
            },
        };
    }, [payload, id, nav, saveDraft, localKey]);

    const keepMine = async () => { if (conflict) { rev.current = conflict.revision; setConflict(null); await saveDraft(stepIdx.current); } };
    const useTheirs = () => {
        if (!conflict) return;
        rev.current = conflict.revision;
        answersRef.current = ((conflict.data.answers ?? {}) as Record<string, Answer>);
        persistLocal(false);
        window.location.reload();
    };

    const banners = conflict && (
        <Banner tone="attention" role="alert" title="This draft was changed on another device.">
            <p>Another device ({conflict.device_label ?? 'unknown'}) saved a different version at {formatDate(conflict.saved_at)}. Nothing has been overwritten. Choose which whole version to keep.</p>
            <div className="row">
                <button type="button" className="btn" onClick={() => void keepMine()}>Keep this device's version</button>
                <button type="button" className="btn secondary" onClick={useTheirs}>Use the other version</button>
            </div>
        </Banner>
    );

    const problem = status === 'unsynced' || status === 'failed';

    return (
        <Player
            steps={payload.steps}
            startIndex={payload.session.current_step}
            title="Practice session"
            context={payload.session.context}
            modeLabel={MODE_LABEL[payload.session.mode]}
            engine={engine}
            initialAnswers={answers}
            banners={banners}
            draftStatus={<span role={problem ? 'status' : undefined}>{STATUS_TEXT[status]}</span>}
        />
    );
}

export function SessionDone() {
    const { id = '' } = useParams();
    const loc = useLocation();
    const h = useFocusOnChange('done');
    const [r, setR] = useState<CompleteResult | null>((loc.state as CompleteResult | null) ?? null);
    const [error, setError] = useState<string | null>(null);

    useEffect(() => {
        if (r) return;
        // A refresh: completing again is harmless and returns the same summary (exactly-once).
        api.post<CompleteResult>(`/sessions/${id}/complete`, { base_revision: 0 }, uuid()).then(setR).catch((e) => setError(e instanceof ApiError ? e.message : 'We could not load the summary.'));
    }, [id, r]);

    if (error) return <Banner tone="attention" role="alert" title="Summary unavailable.">{error} <Link to="/today">Back to Today</Link></Banner>;
    if (!r) return <p role="status">Loading…</p>;

    const demonstrated = r.summary.evidence_changes.filter((e) => e.level === 'demonstrated' || e.level === 'retained');
    const p = r.roadmap_progress;

    return (
        <div className="stack">
            <h1 ref={h} tabIndex={-1}>Session complete</h1>
            {demonstrated.length > 0
                ? <p>On a new task, without hints or the solution, you showed: {demonstrated.map((d) => d.title).join(', ')}.</p>
                : <p>You worked through {r.summary.attempts} {r.summary.attempts === 1 ? 'question' : 'questions'}. That is practice.</p>}
            <p><strong>You can stop here.</strong></p>

            {r.summary.evidence_changes.length > 0 && (
                <section aria-labelledby="ev">
                    <h2 id="ev">Evidence recorded</h2>
                    {r.summary.evidence_changes.map((e, i) => (
                        <div className="skill" key={i}>
                            <LevelMarker level={e.level} /> · <strong>{e.title}</strong>
                            <div className="small muted">{LEVEL_EXPLAIN[e.level]} {BASIS_LABEL[e.basis]}. {assistanceLabel(e.assistance)}.</div>
                        </div>
                    ))}
                    <p className="small muted">Evidence is separate from effort. A check like this comes back in a few days.</p>
                </section>
            )}

            {r.topic_changes.map((t) => <Banner key={t.topic_key} title="Topic completed.">{t.title}. Completion shows coverage with a checked understanding.</Banner>)}
            {p && <ProgressCount done={p.completed_required} total={p.total_required} percent={p.percent} />}
            {r.milestone && <Banner title="Roadmap complete.">{r.milestone.note}</Banner>}
            {r.next_suggestion && <p className="muted">Next planned: {r.next_suggestion.weekday}. There is no catch-up; the plan simply moves on.</p>}

            <div className="stack">
                <Link className="btn block" to="/today">Back to Today</Link>
            </div>
        </div>
    );
}
