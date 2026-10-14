import { setSoundMode, useSoundMode } from '../lib/sound';
import { SoundOffIcon, SoundOnIcon } from './icons';

/** Mute is always one tap away while practising (docs/15 §8). Sound is off until the learner turns it on. */
export function SoundToggle() {
    const mode = useSoundMode();
    const on = mode === 'on';

    return (
        <button type="button" className="icon-btn" aria-pressed={on} onClick={() => setSoundMode(on ? 'off' : 'on')}>
            {on ? <SoundOnIcon /> : <SoundOffIcon />}
            <span className="sr-only">Sound effects</span>
            <span className="icon-btn-state" aria-hidden="true">{on ? 'On' : 'Off'}</span>
        </button>
    );
}
