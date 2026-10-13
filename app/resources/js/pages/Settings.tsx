import { useState } from 'react';
import { useNavigate } from 'react-router-dom';
import { useFocusOnChange } from '../components/ui';
import { useAuth } from '../lib/auth';
import { clearGuest } from '../lib/guestStore';
import { isIosSafari, useInstallPrompt } from '../lib/pwa';

export function SettingsPage() {
    const h = useFocusOnChange('settings');
    const nav = useNavigate();
    const { me, signOut } = useAuth();
    const install = useInstallPrompt();
    const [theme, setTheme] = useState<string>(() => { try { return localStorage.getItem('devstep.theme') ?? 'system'; } catch { return 'system'; } });

    const apply = (t: string) => {
        setTheme(t);
        try { t === 'system' ? localStorage.removeItem('devstep.theme') : localStorage.setItem('devstep.theme', t); } catch { /* storage blocked */ }
        if (t === 'system') delete document.documentElement.dataset.theme; else document.documentElement.dataset.theme = t;
    };

    return (
        <div className="stack">
            <h1 ref={h} tabIndex={-1}>Settings</h1>
            <fieldset>
                <legend>Theme</legend>
                {[['system', 'Match my device'], ['light', 'Light'], ['dark', 'Dark']].map(([v, label]) => (
                    <label className="choice" key={v}><input type="radio" name="theme" checked={theme === v} onChange={() => apply(v)} /><span>{label}</span></label>
                ))}
            </fieldset>
            <section aria-labelledby="app-h" className="stack">
                <h2 id="app-h">Use DevStep as an app</h2>
                {install.installed ? (
                    <p className="muted">DevStep is installed on this device.</p>
                ) : install.canPrompt ? (
                    <>
                        <p className="muted">Add DevStep to your home screen or desktop. It opens full screen and starts faster. Your account and progress are the same.</p>
                        <button type="button" className="btn secondary" onClick={() => void install.install()}>Install DevStep</button>
                    </>
                ) : isIosSafari() ? (
                    <p className="muted">On iPhone or iPad: tap the Share button in Safari, then “Add to Home Screen”.</p>
                ) : (
                    <p className="muted">In your browser menu, choose “Install DevStep” or “Add to Home Screen” to use it like an app. Not every browser offers this.</p>
                )}
            </section>
            {me ? (
                <>
                    <p className="muted">Signed in as {me.email}. Time zone: {me.time_zone ?? 'not set'}.</p>
                    <button type="button" className="btn secondary" onClick={async () => { await signOut(); nav('/'); }}>Sign out</button>
                </>
            ) : (
                <div className="stack">
                    <p className="muted">You are a guest. Your answers live only in this browser on this device.</p>
                    <button type="button" className="btn secondary" onClick={() => { clearGuest(); nav('/'); }}>Clear data on this device</button>
                </div>
            )}
        </div>
    );
}
