import type { CSSProperties, ReactNode } from 'react';
import head128 from '../../mascot/step-head-128.webp';
import head256 from '../../mascot/step-head-256.webp';
import step360 from '../../mascot/step-360.webp';
import step720 from '../../mascot/step-720.webp';
import { prefersStill, useMascotMode } from '../lib/mascotPrefs';
import { MASCOT_NAME } from '../lib/stepSays';

// Step, the DevStep companion (docs/15). One artwork, many moods: the moods are motion and small props around the
// same figure, never a different face, so Step stays recognisable. Decorative by default: anything Step "says" is
// real text next to it, so nothing depends on seeing the image. Motion stops for reduced-motion, "quiet" and "off".

export type Mood = 'wave' | 'hello' | 'cheer' | 'think' | 'rest';

interface MascotProps {
    mood?: Mood;
    /** Width and height in CSS pixels. */
    size?: number;
    /** Only when the image carries meaning of its own; leave unset when text sits beside it. */
    label?: string;
    className?: string;
}

export function Mascot({ mood = 'wave', size = 160, label, className = '' }: MascotProps) {
    const mode = useMascotMode();
    if (mode === 'off') return null;
    const still = prefersStill(mode);

    return (
        <span className={`mascot mood-${mood}${still ? ' still' : ''} ${className}`} style={{ '--size': `${size}px` } as CSSProperties} aria-hidden={label ? undefined : true}>
            <img
                src={step720}
                srcSet={`${step360} 360w, ${step720} 720w`}
                sizes={`${size}px`}
                width={size}
                height={size}
                alt={label ?? ''}
                decoding="async"
                draggable={false}
            />
            {mood === 'cheer' && !still && (
                <span className="confetti" aria-hidden="true">
                    {Array.from({ length: 14 }, (_, i) => (
                        <i key={i} style={{ '--i': i, '--dx': `${(i - 6.5) * 16}px`, '--dy': `${-(34 + (i % 4) * 18)}px`, '--rot': `${i * 80}deg` } as CSSProperties} />
                    ))}
                </span>
            )}
            {mood === 'think' && <span className="props think-dots" aria-hidden="true"><i /><i /><i /></span>}
            {mood === 'rest' && <span className="props zzz" aria-hidden="true"><i>z</i><i>z</i><i>z</i></span>}
        </span>
    );
}

/** Step's face, for the header, notices and small helpers. */
export function Avatar({ size = 40, className = '' }: { size?: number; className?: string }) {
    const mode = useMascotMode();
    if (mode === 'off') return null;

    return (
        <img className={`avatar ${className}`} src={head256} srcSet={`${head128} 128w, ${head256} 256w`} sizes={`${size}px`} width={size} height={size} alt="" aria-hidden="true" decoding="async" draggable={false} />
    );
}

/** Step beside a line of real text. The text is the content; Step is company. */
export function Say({ mood = 'wave', size = 96, children, stack = false, live = false }: { mood?: Mood; size?: number; children: ReactNode; stack?: boolean; live?: boolean }) {
    const mode = useMascotMode();

    return (
        <div className={`say${stack ? ' stack-layout' : ''}${mode === 'off' ? ' no-mascot' : ''}`}>
            <Mascot mood={mood} size={size} />
            <div className="bubble" role={live ? 'status' : undefined}>
                <span className="bubble-name">{MASCOT_NAME}</span>
                <p>{children}</p>
            </div>
        </div>
    );
}

/** Step's short remark beside a small avatar, for places where a full bubble would be too much. */
export function Remark({ children }: { children: ReactNode }) {
    return (
        <div className="say-mini">
            <Avatar size={40} />
            <span>{children}</span>
        </div>
    );
}

/** A bubble from Step with no figure of its own, for use directly under a Mascot that is already on screen. */
export function Bubble({ children }: { children: ReactNode }) {
    return (
        <div className="say stack-layout">
            <div className="bubble">
                <span className="bubble-name">{MASCOT_NAME}</span>
                <p>{children}</p>
            </div>
        </div>
    );
}
