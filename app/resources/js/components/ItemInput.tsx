import { useState } from 'react';
import { Markdown } from '../lib/markdown';
import type { Item } from '../types';

export type Answer = Record<string, unknown>;

/** Answer input for each item type. Value changes feed the autosaved draft; nothing is submitted here. */
export function ItemInput({ item, value, onChange, disabled }: { item: Item; value: Answer; onChange: (a: Answer) => void; disabled: boolean }) {
    const name = `q-${item.content_version_id}`;

    if (item.type === 'single_choice') {
        return (
            <fieldset disabled={disabled}>
                <legend className="sr-only">Choose one answer</legend>
                {item.options!.map((o) => (
                    <label className="choice" key={o.key}>
                        <input type="radio" name={name} checked={value.choice === o.key} onChange={() => onChange({ choice: o.key })} />
                        <Markdown source={o.text} inline />
                    </label>
                ))}
            </fieldset>
        );
    }

    if (item.type === 'multi_select') {
        const chosen = (value.choices as string[] | undefined) ?? [];

        return (
            <fieldset disabled={disabled}>
                <legend className="sr-only">Choose all answers that apply</legend>
                {item.options!.map((o) => (
                    <label className="choice" key={o.key}>
                        <input
                            type="checkbox"
                            checked={chosen.includes(o.key)}
                            onChange={(e) => onChange({ choices: e.target.checked ? [...chosen, o.key] : chosen.filter((k) => k !== o.key) })}
                        />
                        <Markdown source={o.text} inline />
                    </label>
                ))}
            </fieldset>
        );
    }

    if (item.type === 'ordering') {
        const order = (value.order as string[] | undefined) ?? item.options!.map((o) => o.key);
        const text = Object.fromEntries(item.options!.map((o) => [o.key, o.text]));
        const move = (i: number, d: number) => {
            const next = [...order];
            [next[i], next[i + d]] = [next[i + d], next[i]];
            onChange({ order: next });
        };

        return (
            <div>
                <p className="muted small" id={`${name}-help`}>Use the arrow buttons to put these in order, first at the top.</p>
                <ol className="order-list" aria-describedby={`${name}-help`}>
                    {order.map((k, i) => (
                        <li key={k}>
                            <span className="txt">{text[k]}</span>
                            <button type="button" disabled={disabled || i === 0} onClick={() => move(i, -1)} aria-label={`Move up: ${text[k]}`}>↑</button>
                            <button type="button" disabled={disabled || i === order.length - 1} onClick={() => move(i, 1)} aria-label={`Move down: ${text[k]}`}>↓</button>
                        </li>
                    ))}
                </ol>
            </div>
        );
    }

    return <SelfCheckInput item={item} value={value} onChange={onChange} disabled={disabled} />;
}

/** Open-ended answers: write first, then compare with an example answer and rate each point (docs/08 IR-11). */
function SelfCheckInput({ item, value, onChange, disabled }: { item: Item; value: Answer; onChange: (a: Answer) => void; disabled: boolean }) {
    const text = (value.text as string | undefined) ?? '';
    const ratings = (value.ratings as Record<string, number> | undefined) ?? {};
    const [compare, setCompare] = useState(false);
    const long = text.trim().length >= (item.min_chars ?? 40);

    return (
        <div className="stack">
            <div>
                <label className="field" htmlFor={`${item.content_version_id}-t`}>
                    Your answer <span className="field-help">At least {item.min_chars ?? 40} characters. Your answer stays on your account and is never put in analytics.</span>
                </label>
                <textarea id={`${item.content_version_id}-t`} value={text} disabled={disabled} onChange={(e) => onChange({ ...value, text: e.target.value })} />
            </div>
            {!compare && !disabled && (
                <button type="button" className="btn secondary" disabled={!long} onClick={() => setCompare(true)}>
                    Compare with an example answer
                </button>
            )}
            {(compare || disabled) && (
                <div>
                    <div className="banner info"><strong>Example answer</strong> <Markdown source={item.exemplar ?? ''} inline /></div>
                    <p className="small muted">This is self-assessed. A person has not reviewed it. Rate each point honestly: 0 missing, 1 partly, 2 clearly there.</p>
                    {item.rubric!.map((c) => (
                        <fieldset key={c.id} disabled={disabled}>
                            <legend>{c.text} <span className="tag">{c.weight === 'essential' ? 'Essential' : 'Supporting'}</span></legend>
                            <div className="chips">
                                {[0, 1, 2].map((r) => (
                                    <label className="chip" key={r}>
                                        <input type="radio" name={`${item.content_version_id}-${c.id}`} checked={ratings[c.id] === r} onChange={() => onChange({ ...value, ratings: { ...ratings, [c.id]: r } })} />
                                        <span>{r === 0 ? '0 · Missing' : r === 1 ? '1 · Partly' : '2 · Clearly'}</span>
                                    </label>
                                ))}
                            </div>
                        </fieldset>
                    ))}
                </div>
            )}
        </div>
    );
}

export function answerReady(item: Item, a: Answer): boolean {
    switch (item.type) {
        case 'single_choice': return typeof a.choice === 'string';
        case 'multi_select': return Array.isArray(a.choices) && a.choices.length > 0;
        case 'ordering': return true; // an unchanged order is a valid answer
        case 'self_check': {
            const r = (a.ratings as Record<string, number> | undefined) ?? {};

            return typeof a.text === 'string' && a.text.trim().length >= (item.min_chars ?? 40) && item.rubric!.every((c) => typeof r[c.id] === 'number');
        }
    }
}

/** The answer actually sent: an ordering the learner left untouched is the order shown to them. */
export function effectiveAnswer(item: Item, a: Answer): Answer {
    if (item.type === 'ordering' && !Array.isArray(a.order)) return { order: item.options!.map((o) => o.key) };

    return a;
}
