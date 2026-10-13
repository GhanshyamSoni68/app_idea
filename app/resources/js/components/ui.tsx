import { type ReactNode, useEffect, useRef } from 'react';
import { LEVEL_GLYPH, LEVEL_LABEL } from '../lib/format';
import { ProgressBar } from './Progress';
import type { Level } from '../types';

export function Banner({ tone = 'info', title, children, role }: { tone?: 'info' | 'attention'; title?: string; children: ReactNode; role?: 'status' | 'alert' }) {
    return (
        <div className={`banner ${tone}`} role={role}>
            {title && <strong>{title}</strong>}
            {children}
        </div>
    );
}

/** Evidence level marker: the shape carries the meaning and the word is always shown beside it (A-12). */
export function LevelMarker({ level }: { level: Level }) {
    return (
        <span className="marker">
            <span className="glyph" aria-hidden="true">{LEVEL_GLYPH[level]}</span>
            {LEVEL_LABEL[level]}
        </span>
    );
}

/** "N of M" first, percentage second (rounded down by the server), then the bar. */
export function ProgressCount({ done, total, percent, label = 'required topics' }: { done: number; total: number; percent: number; label?: string }) {
    return (
        <div className="progress">
            <span>
                <strong>{done} of {total}</strong> {label} · {percent}%
            </span>
            <ProgressBar value={done} max={Math.max(total, 1)} label={`${done} of ${total} ${label}`} />
        </div>
    );
}

/** Native <dialog>: focus moves in, is trapped, Escape closes it and focus returns to the trigger (A-06). */
export function Dialog({ open, title, onClose, children }: { open: boolean; title: string; onClose: () => void; children: ReactNode }) {
    const ref = useRef<HTMLDialogElement>(null);
    useEffect(() => {
        const d = ref.current;
        if (!d) return;
        if (open && !d.open) d.showModal();
        if (!open && d.open) d.close();
    }, [open]);

    return (
        <dialog ref={ref} aria-labelledby="dlg-title" onClose={onClose} onCancel={onClose}>
            <h2 id="dlg-title">{title}</h2>
            {children}
        </dialog>
    );
}

/** Moves focus to the heading when a screen or step changes (A-06). */
export function useFocusOnChange(dep: unknown) {
    const ref = useRef<HTMLHeadingElement>(null);
    useEffect(() => {
        ref.current?.focus();
    }, [dep]);

    return ref;
}
