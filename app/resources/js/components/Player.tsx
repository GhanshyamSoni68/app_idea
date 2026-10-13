import { type ReactNode, useCallback, useEffect, useMemo, useRef, useState } from 'react';
import { ApiError } from '../api';
import { helpUsed, OUTCOME_LABEL } from '../lib/format';
import { Markdown } from '../lib/markdown';
import type { AttemptResult, Item } from '../types';
import { type Answer, answerReady, effectiveAnswer, ItemInput } from './ItemInput';
import { Banner, Dialog, useFocusOnChange } from './ui';

export interface PlayerStep {
    key: string;
    kind: 'scenario' | 'explanation' | 'response';
    title: string | null;
    body: string;
    item: Item | null;
    attempt?: AttemptResult | null;
    stop_after: boolean;
}

export interface PlayerEngine {
    check(step: PlayerStep, answer: Answer): Promise<AttemptResult>;
    hint(step: PlayerStep, n: number): Promise<{ text: string; remaining: number }>;
    reveal(step: PlayerStep): Promise<string>;
    /** Called when the learner moves past a step. Signed-in sessions record "feedback reviewed" here. */
    advance(toIndex: number): Promise<void>;
    finish(): Promise<void>;
    onDraft(stepKey: string, answer: Answer): void;
    saveAndExit(): Promise<void>;
}

interface Props {
    steps: PlayerStep[];
    startIndex: number;
    title: string;
    context: string | null;
    modeLabel: string;
    engine: PlayerEngine;
    initialAnswers?: Record<string, Answer>;
    draftStatus?: ReactNode;
    banners?: ReactNode;
    finishLabel?: string;
    /** False in a challenge: no hints and no solution (docs/05 §7.4). */
    assistance?: boolean;
}

export function Player({ steps, startIndex, title, context, modeLabel, engine, initialAnswers = {}, draftStatus, banners, finishLabel = 'Finish', assistance = true }: Props) {
    const [index, setIndex] = useState(Math.min(startIndex, steps.length - 1));
    const [answers, setAnswers] = useState<Record<string, Answer>>(initialAnswers);
    const [results, setResults] = useState<Record<string, AttemptResult>>(() => Object.fromEntries(steps.filter((s) => s.attempt).map((s) => [s.key, s.attempt!])));
    const [hints, setHints] = useState<Record<string, { texts: string[]; remaining: number }>>({});
    const [revealed, setRevealed] = useState<Record<string, string>>({});
    const [confirmReveal, setConfirmReveal] = useState(false);
    const [busy, setBusy] = useState<'check' | 'next' | null>(null);
    const [error, setError] = useState<string | null>(null);
    const [online, setOnline] = useState(typeof navigator === 'undefined' ? true : navigator.onLine);
    const revealTrigger = useRef<HTMLButtonElement>(null);

    useEffect(() => {
        const on = () => setOnline(true);
        const off = () => setOnline(false);
        window.addEventListener('online', on);
        window.addEventListener('offline', off);

        return () => {
            window.removeEventListener('online', on);
            window.removeEventListener('offline', off);
        };
    }, []);

    const step = steps[index];
    const result = results[step.key];
    const last = index === steps.length - 1;
    const headingRef = useFocusOnChange(`${index}`);
    const feedbackRef = useFocusOnChange(result ? `fb-${step.key}` : null);
    const answer = answers[step.key] ?? {};

    const setAnswer = useCallback((a: Answer) => {
        setAnswers((prev) => ({ ...prev, [step.key]: a }));
        engine.onDraft(step.key, a);
    }, [engine, step.key]);

    const check = async () => {
        if (!step.item) return;
        setBusy('check');
        setError(null);
        try {
            const r = await engine.check(step, effectiveAnswer(step.item, answer));
            setResults((prev) => ({ ...prev, [step.key]: r }));
        } catch (e) {
            setError(e instanceof ApiError ? e.message : 'We could not check that answer. Your work is kept; try again.');
        } finally {
            setBusy(null);
        }
    };

    const nextHint = async () => {
        const shown = hints[step.key]?.texts.length ?? 0;
        try {
            const h = await engine.hint(step, shown + 1);
            setHints((prev) => ({ ...prev, [step.key]: { texts: [...(prev[step.key]?.texts ?? []), h.text], remaining: h.remaining } }));
        } catch (e) {
            setError(e instanceof ApiError ? e.message : 'We could not load that hint.');
        }
    };

    const doReveal = async () => {
        setConfirmReveal(false);
        try {
            const text = await engine.reveal(step);
            setRevealed((prev) => ({ ...prev, [step.key]: text }));
        } catch (e) {
            setError(e instanceof ApiError ? e.message : 'We could not show the solution.');
        }
    };

    const next = async () => {
        setBusy('next');
        setError(null);
        try {
            if (last) await engine.finish();
            else {
                await engine.advance(index + 1);
                setIndex(index + 1);
            }
        } catch (e) {
            setError(e instanceof ApiError ? e.message : 'We could not continue. Your work is kept; try again.');
        } finally {
            setBusy(null);
        }
    };

    const earlier = useMemo(() => steps.slice(0, index).filter((s) => s.kind !== 'response' && s.body), [steps, index]);
    const hintState = hints[step.key];
    const totalHints = step.item?.hint_count ?? 0;
    const outcome = result ? OUTCOME_LABEL[result.outcome] : null;

    return (
        <section aria-labelledby="step-title">
            <div className="player-head">
                <button type="button" className="btn secondary" onClick={() => void engine.saveAndExit()}>Save and exit</button>
                <span aria-live="off">Step {index + 1} of {steps.length}</span>
            </div>
            <p className="player-ctx">{context ?? title}{context ? ` · ${modeLabel}` : ''}</p>
            {banners}
            <h1 id="step-title" tabIndex={-1} ref={headingRef}>{step.title ?? title}</h1>

            {earlier.length > 0 && (
                <details className="small" style={{ marginBottom: 16 }}>
                    <summary>Earlier steps</summary>
                    {earlier.map((s) => (
                        <div key={s.key}><h3>{s.title}</h3><Markdown source={s.body} /></div>
                    ))}
                </details>
            )}

            {step.body && <Markdown source={step.body} />}

            {step.kind === 'response' && step.item && (
                <div className="stack" style={{ marginTop: 16 }}>
                    {step.item.scenario && <Markdown source={step.item.scenario} />}
                    <h2>Question</h2>
                    <Markdown source={step.item.prompt} />
                    <ItemInput item={step.item} value={answer} onChange={setAnswer} disabled={!!result} />

                    {!result && (
                        <>
                            <div aria-live="polite">
                                {hintState?.texts.map((t, i) => (
                                    <div className="hint" key={i}>
                                        <strong>Hint {i + 1} of {totalHints}</strong>
                                        <Markdown source={t} />
                                    </div>
                                ))}
                                {revealed[step.key] && (
                                    <div className="banner info"><strong>Solution</strong> <Markdown source={revealed[step.key]} inline />
                                        <p className="small muted" style={{ margin: '8px 0 0' }}>This question will not count toward your evidence. A fresh one will come back later.</p>
                                    </div>
                                )}
                            </div>
                            {assistance ? (<>
                            <div className="row between">
                                {totalHints > 0 && (hintState?.texts.length ?? 0) < totalHints
                                    ? <button type="button" className="btn secondary" onClick={() => void nextHint()}>{hintState?.texts.length ? `Show hint ${hintState.texts.length + 1}` : 'Need a hint?'}</button>
                                    : <span />}
                                {!revealed[step.key] && <button type="button" className="link" ref={revealTrigger} onClick={() => setConfirmReveal(true)}>Show solution</button>}
                            </div>
                            <p className="small muted">Hints are optional. Using one is recorded and still counts as practice.</p>
                            </>) : <p className="small muted">This is a challenge: no hints and no solution, and no timer. Take your time.</p>}
                            {!online && <Banner tone="attention" role="status" title="You are offline.">Answers are checked online, so “Check answer” is paused. Your work is kept on this device.</Banner>}
                            <button type="button" className="btn block" disabled={!answerReady(step.item, answer) || busy !== null || !online} onClick={() => void check()}>
                                {busy === 'check' ? 'Checking…' : 'Check answer'}
                            </button>
                        </>
                    )}

                    {result && outcome && (
                        <div className={`feedback ${outcome.tone}`} role="region" aria-labelledby="fb-title">
                            <h2 id="fb-title" tabIndex={-1} ref={feedbackRef}><span aria-hidden="true">{outcome.glyph} </span>{outcome.word}</h2>
                            <div aria-live="polite">
                                <Markdown source={result.feedback.text} />
                                {result.feedback.correct_order && (
                                    <div><strong>A good order:</strong>
                                        <ol>{result.feedback.correct_order.map((o) => <li key={o.key}>{o.text}</li>)}</ol>
                                    </div>
                                )}
                                {result.feedback.model_answer && <p><strong>A good answer: </strong><Markdown source={result.feedback.model_answer} inline /></p>}
                                {result.feedback.misconception && <p><strong>A common mistake: </strong><Markdown source={result.feedback.misconception.note} inline /></p>}
                                {result.notice === 'already_answered' && <p className="muted small">This question was already answered, so this is your first result.</p>}
                            </div>
                            <p className="small muted">{helpUsed(result.assistance, result.hint_count)}. {result.evidence_basis === 'self_assessed' ? 'Self-assessed. A person has not reviewed it.' : ''}</p>
                            {outcome.tone === 'notyet' && <p className="small muted">A similar question will come back sooner.</p>}
                        </div>
                    )}
                </div>
            )}

            {error && <p className="err" role="alert">{error}</p>}

            {(step.kind !== 'response' || result) && (
                <div style={{ marginTop: 20 }}>
                    <button type="button" className="btn block" disabled={busy !== null} onClick={() => void next()}>
                        {busy === 'next' ? 'Saving…' : last ? finishLabel : step.stop_after ? 'Continue' : 'Next'}
                    </button>
                    {step.stop_after && !last && <p className="small muted">You can stop here and pick this up later. Nothing is lost.</p>}
                </div>
            )}

            <div className="draft-status">{draftStatus}</div>

            <Dialog open={confirmReveal} title="Show the solution?" onClose={() => { setConfirmReveal(false); revealTrigger.current?.focus(); }}>
                <p>You can keep learning after this. You have not answered yet, so this question will not count toward your evidence. A fresh question on the same idea will come back later.</p>
                <div className="stack">
                    <button type="button" className="btn block" autoFocus onClick={() => setConfirmReveal(false)}>Keep trying</button>
                    <button type="button" className="btn secondary block" onClick={() => void doReveal()}>Show solution</button>
                </div>
            </Dialog>
        </section>
    );
}
