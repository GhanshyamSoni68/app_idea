import type { CSSProperties } from 'react';

/** Rounded progress bar with a real accessible value. "N of M" text always sits beside it (docs/08 §9.2). */
export function ProgressBar({ value, max, label }: { value: number; max: number; label: string }) {
    const pct = max > 0 ? Math.min(100, Math.round((value / max) * 100)) : 0;

    return (
        <div className="bar" role="progressbar" aria-label={label} aria-valuemin={0} aria-valuemax={max} aria-valuenow={value} aria-valuetext={`${value} of ${max}`}>
            <span className="bar-fill" style={{ '--pct': `${pct}%` } as CSSProperties} />
        </div>
    );
}

/** One segment per step in the player. Position is also text ("Step 2 of 5"), never only the bar. */
export function StepBar({ index, total }: { index: number; total: number }) {
    return (
        <div className="segbar" aria-hidden="true">
            {Array.from({ length: total }, (_, i) => <span key={i} className={i < index ? 'done' : i === index ? 'now' : ''} />)}
        </div>
    );
}
