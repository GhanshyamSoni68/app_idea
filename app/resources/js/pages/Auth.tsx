import { type FormEvent, useState } from 'react';
import { Link, Navigate, useLocation, useNavigate } from 'react-router-dom';
import { api, ApiError } from '../api';
import { OnboardingForm } from '../components/OnboardingForm';
import { Remark } from '../components/Mascot';
import { Banner, useFocusOnChange } from '../components/ui';
import { say } from '../lib/stepSays';
import { useAuth } from '../lib/auth';
import { hasGuestWork, loadGuest } from '../lib/guestStore';
import type { Me } from '../types';

function Errors({ error }: { error: ApiError | null }) {
    if (!error) return null;

    return (
        <div className="errsum" role="alert" tabIndex={-1}>
            <strong>{error.message}</strong>
            {error.fields && <ul>{error.fields.map((f) => <li key={f.field}>{f.message}</li>)}</ul>}
        </div>
    );
}

export function SignUp() {
    const nav = useNavigate();
    const { setMe } = useAuth();
    const h = useFocusOnChange('signup');
    const [form, setForm] = useState({ email: '', password: '', invite_code: '' });
    const [error, setError] = useState<ApiError | null>(null);
    const [busy, setBusy] = useState(false);
    const guest = hasGuestWork();

    const submit = async (e: FormEvent) => {
        e.preventDefault();
        setBusy(true);
        setError(null);
        try {
            const r = await api.post<{ user: Me }>('/auth/sign-up', { ...form, guest_subject_id: loadGuest().subjectId });
            setMe(r.user);
            nav('/today');
        } catch (err) {
            setError(err instanceof ApiError ? err : new ApiError('unknown', 'Something went wrong. Please try again.', 0));
        } finally {
            setBusy(false);
        }
    };

    return (
        <form onSubmit={submit} className="stack" noValidate>
            <h1 ref={h} tabIndex={-1}>Create your account</h1>
            <Remark>{say('signup')}</Remark>
            {guest && <Banner title="Your sample answers move with you.">They are checked again and recorded as practice.</Banner>}
            <Errors error={error} />
            <div>
                <label className="field" htmlFor="email">Email address</label>
                <input id="email" type="email" autoComplete="email" required value={form.email} onChange={(e) => setForm({ ...form, email: e.target.value })} />
            </div>
            <div>
                <label className="field" htmlFor="password">Password <span className="field-help">At least 12 characters. A few random words is fine.</span></label>
                <input id="password" type="password" autoComplete="new-password" required minLength={12} value={form.password} onChange={(e) => setForm({ ...form, password: e.target.value })} />
            </div>
            <div>
                <label className="field" htmlFor="invite">Pilot invite code <span className="field-help">Sent to the email address you were invited with.</span></label>
                <input id="invite" type="text" autoComplete="off" required value={form.invite_code} onChange={(e) => setForm({ ...form, invite_code: e.target.value })} />
            </div>
            <button className="btn block" type="submit" disabled={busy}>{busy ? 'Creating…' : 'Create account'}</button>
            <p>Already have an account? <Link to="/sign-in">Sign in</Link></p>
        </form>
    );
}

export function SignIn() {
    const nav = useNavigate();
    const { setMe } = useAuth();
    const h = useFocusOnChange('signin');
    const [form, setForm] = useState({ email: '', password: '' });
    const [error, setError] = useState<ApiError | null>(null);
    const [busy, setBusy] = useState(false);
    const justReset = (useLocation().state as { reset?: boolean } | null)?.reset === true;

    const submit = async (e: FormEvent) => {
        e.preventDefault();
        setBusy(true);
        setError(null);
        try {
            const r = await api.post<{ user: Me }>('/auth/sign-in', form);
            setMe(r.user);
            nav('/today');
        } catch (err) {
            setError(err instanceof ApiError ? err : new ApiError('unknown', 'Something went wrong. Please try again.', 0));
        } finally {
            setBusy(false);
        }
    };

    return (
        <form onSubmit={submit} className="stack" noValidate>
            <h1 ref={h} tabIndex={-1}>Sign in</h1>
            <Remark>{say('signin')}</Remark>
            {justReset && <Banner role="status" title="Password changed.">Sign in with your new password. You were signed out everywhere.</Banner>}
            <Errors error={error} />
            <div>
                <label className="field" htmlFor="email">Email address</label>
                <input id="email" type="email" autoComplete="username" required value={form.email} onChange={(e) => setForm({ ...form, email: e.target.value })} />
            </div>
            <div>
                <label className="field" htmlFor="password">Password</label>
                <input id="password" type="password" autoComplete="current-password" required value={form.password} onChange={(e) => setForm({ ...form, password: e.target.value })} />
            </div>
            <button className="btn block" type="submit" disabled={busy}>{busy ? 'Signing in…' : 'Sign in'}</button>
            <p><Link to="/forgot">Forgot your password?</Link></p>
            <p>New here? <Link to="/sample">Try the sample</Link> first, or <Link to="/sign-up">create an account</Link> with an invite.</p>
        </form>
    );
}

/** Signed-in users who never completed onboarding (for example a direct invite sign-up). */
export function Onboarding() {
    const nav = useNavigate();
    const { me, refresh } = useAuth();
    const h = useFocusOnChange('onboarding');
    const [busy, setBusy] = useState(false);
    const [error, setError] = useState<string | null>(null);

    // A claimed guest bundle already carries these answers, so there is nothing left to ask.
    if (me?.onboarding_completed) return <Navigate to="/today" replace />;

    return (
        <div className="stack">
            <h1 ref={h} tabIndex={-1}>A few quick questions</h1>
            <p className="muted">They shape what you are offered. Nothing is rated, and you can change every answer later.</p>
            {error && <p className="err" role="alert">{error}</p>}
            <OnboardingForm busy={busy} submitLabel="Save and continue" onDone={async (o) => {
                setBusy(true);
                try {
                    await api.put('/me/preferences', { time_zone: o.time_zone, available_days: o.available_days, preferred_mode: o.preferred_mode, weekly_session_target: o.weekly_session_target, stack_tags: o.stack_tags, diagnostic_status: 'skipped' });
                    await api.put('/me/goal', { outcome_key: o.goal.outcome_key });
                    await refresh();
                    nav('/today');
                } catch (e) {
                    setError(e instanceof ApiError ? e.message : 'We could not save that. Please try again.');
                } finally {
                    setBusy(false);
                }
            }} />
        </div>
    );
}
