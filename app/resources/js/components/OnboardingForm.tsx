import { type FormEvent, useMemo, useState } from 'react';
import { browserTimeZone } from '../lib/format';
import type { Onboarding } from '../lib/guestStore';
import type { Mode } from '../types';

const GOALS = [
    { key: 'performance', label: 'Find and fix what makes things slow' },
    { key: 'reliability', label: 'Make my app more reliable under load' },
    { key: 'architecture', label: 'Make better architecture decisions' },
    { key: 'general', label: 'Get better at the whole picture' },
];
const STACKS = [
    { key: 'laravel', label: 'Laravel / PHP' }, { key: 'node', label: 'Node / TypeScript' }, { key: 'python', label: 'Python' }, { key: 'dotnet', label: '.NET' },
    { key: 'java', label: 'Java / Kotlin' }, { key: 'ruby', label: 'Ruby' }, { key: 'go', label: 'Go' }, { key: 'other', label: 'Something else' },
];
const DAYS = [[1, 'Mon'], [2, 'Tue'], [3, 'Wed'], [4, 'Thu'], [5, 'Fri'], [6, 'Sat'], [7, 'Sun']] as const;

function zones(): string[] {
    try {
        return (Intl as unknown as { supportedValuesOf: (k: string) => string[] }).supportedValuesOf('timeZone');
    } catch {
        return [browserTimeZone()];
    }
}

/** Goal, stack, available days, session length and time zone (F01). No skill ratings, no giant tree (PRD §5). */
export function OnboardingForm({ onDone, submitLabel, busy }: { onDone: (o: Onboarding) => void; submitLabel: string; busy?: boolean }) {
    const [goal, setGoal] = useState('performance');
    const [stack, setStack] = useState<string[]>([]);
    const [days, setDays] = useState<number[]>([1, 2, 3, 4, 5]);
    const [mode, setMode] = useState<Mode>('practise');
    const [tz, setTz] = useState(browserTimeZone());
    const [errors, setErrors] = useState<string[]>([]);
    const allZones = useMemo(zones, []);

    const submit = (e: FormEvent) => {
        e.preventDefault();
        const errs: string[] = [];
        if (days.length === 0) errs.push('Choose at least one day you might practise. You can change it any time.');
        if (!allZones.includes(tz)) errs.push('Choose a time zone from the list, for example Europe/London.');
        setErrors(errs);
        if (errs.length) return;
        onDone({ time_zone: tz, available_days: days, preferred_mode: mode, weekly_session_target: Math.min(3, days.length), stack_tags: stack, goal: { outcome_key: goal } });
    };

    return (
        <form onSubmit={submit} className="stack" noValidate>
            {errors.length > 0 && (
                <div className="errsum" role="alert" tabIndex={-1}>
                    <strong>Please check:</strong>
                    <ul>{errors.map((m) => <li key={m}>{m}</li>)}</ul>
                </div>
            )}
            <fieldset>
                <legend>What do you want to get better at?</legend>
                {GOALS.map((g) => (
                    <label className="choice" key={g.key}>
                        <input type="radio" name="goal" checked={goal === g.key} onChange={() => setGoal(g.key)} />
                        <span>{g.label}</span>
                    </label>
                ))}
            </fieldset>
            <fieldset>
                <legend>What do you build with? <span className="field-help">Optional. Examples will use SQL and plain pseudo-code either way.</span></legend>
                <div className="chips">
                    {STACKS.map((s) => (
                        <label className="chip" key={s.key}>
                            <input type="checkbox" checked={stack.includes(s.key)} onChange={(e) => setStack(e.target.checked ? [...stack, s.key] : stack.filter((k) => k !== s.key))} />
                            <span>{s.label}</span>
                        </label>
                    ))}
                </div>
            </fieldset>
            <fieldset>
                <legend>Which days could you practise? <span className="field-help">A flexible guide, not a streak. Any practice counts.</span></legend>
                <div className="chips">
                    {DAYS.map(([n, label]) => (
                        <label className="chip" key={n}>
                            <input type="checkbox" checked={days.includes(n)} onChange={(e) => setDays(e.target.checked ? [...days, n].sort() : days.filter((d) => d !== n))} />
                            <span>{label}</span>
                        </label>
                    ))}
                </div>
            </fieldset>
            <fieldset>
                <legend>How long usually?</legend>
                <label className="choice"><input type="radio" name="mode" checked={mode === 'practise'} onChange={() => setMode('practise')} /><span><strong>Practise</strong>, about 10 minutes</span></label>
                <label className="choice"><input type="radio" name="mode" checked={mode === 'small'} onChange={() => setMode('small')} /><span><strong>Start small</strong>, about 3 minutes</span></label>
            </fieldset>
            <div>
                <label className="field" htmlFor="tz">Time zone <span className="field-help">Used to decide when your day starts. Start typing to search.</span></label>
                <input id="tz" type="text" list="zones" value={tz} onChange={(e) => setTz(e.target.value)} autoComplete="off" />
                <datalist id="zones">{allZones.map((z) => <option key={z} value={z} />)}</datalist>
            </div>
            <button className="btn block" type="submit" disabled={busy}>{submitLabel}</button>
        </form>
    );
}
