import { type FormEvent, useEffect, useState } from 'react';
import { Link, useNavigate, useSearchParams } from 'react-router-dom';
import { api, ApiError, uuid } from '../api';
import { Banner, Dialog, useFocusOnChange } from '../components/ui';
import { useAuth } from '../lib/auth';
import { formatDate } from '../lib/format';

/** Opens from the emailed link. Works whether or not the learner is signed in on this device. */
export function VerifyPage() {
    const [params] = useSearchParams();
    const { refresh } = useAuth();
    const h = useFocusOnChange('verify');
    const [state, setState] = useState<'working' | 'done' | 'failed'>('working');
    const token = params.get('token') ?? '';

    useEffect(() => {
        if (!token) { setState('failed'); return; }
        api.post('/auth/verification/confirm', { token }).then(() => { setState('done'); void refresh(); }).catch(() => setState('failed'));
    }, [token]); // eslint-disable-line react-hooks/exhaustive-deps

    return (
        <div className="stack">
            <h1 ref={h} tabIndex={-1}>Confirm your email</h1>
            {state === 'working' && <p role="status">Checking your link…</p>}
            {state === 'done' && <Banner title="Email confirmed." role="status">Thank you. <Link to="/today">Continue to DevStep</Link></Banner>}
            {state === 'failed' && <Banner tone="attention" role="alert" title="That link did not work.">It may have expired or been used already. Sign in and ask for a new one in Settings. <Link to="/sign-in">Sign in</Link></Banner>}
        </div>
    );
}

export function ForgotPage() {
    const h = useFocusOnChange('forgot');
    const [email, setEmail] = useState('');
    const [sent, setSent] = useState(false);
    const [busy, setBusy] = useState(false);
    const [error, setError] = useState<string | null>(null);

    const submit = async (e: FormEvent) => {
        e.preventDefault();
        setBusy(true);
        setError(null);
        try {
            await api.post('/auth/recovery', { email });
            setSent(true);
        } catch (err) {
            setError(err instanceof ApiError ? err.message : 'Something went wrong. Please try again.');
        } finally {
            setBusy(false);
        }
    };

    return (
        <form onSubmit={submit} className="stack" noValidate>
            <h1 ref={h} tabIndex={-1}>Reset your password</h1>
            {sent
                ? <Banner role="status" title="Check your email.">If that address has a DevStep account, we have sent a link to choose a new password. It works once and expires in an hour.</Banner>
                : (
                    <>
                        {error && <p className="err" role="alert">{error}</p>}
                        <div>
                            <label className="field" htmlFor="email">Email address</label>
                            <input id="email" type="email" autoComplete="email" required value={email} onChange={(e) => setEmail(e.target.value)} />
                        </div>
                        <button className="btn block" type="submit" disabled={busy}>{busy ? 'Sending…' : 'Send reset link'}</button>
                    </>
                )}
            <p><Link to="/sign-in">Back to sign in</Link></p>
        </form>
    );
}

export function ResetPage() {
    const [params] = useSearchParams();
    const nav = useNavigate();
    const h = useFocusOnChange('reset');
    const [password, setPassword] = useState('');
    const [error, setError] = useState<string | null>(null);
    const [busy, setBusy] = useState(false);

    const submit = async (e: FormEvent) => {
        e.preventDefault();
        setBusy(true);
        setError(null);
        try {
            await api.post('/auth/recovery/confirm', { token: params.get('token') ?? '', password });
            nav('/sign-in', { state: { reset: true } });
        } catch (err) {
            setError(err instanceof ApiError ? (err.fields?.[0]?.message ?? err.message) : 'Something went wrong. Please try again.');
        } finally {
            setBusy(false);
        }
    };

    return (
        <form onSubmit={submit} className="stack" noValidate>
            <h1 ref={h} tabIndex={-1}>Choose a new password</h1>
            <p className="muted">Using this link signs you out on every device.</p>
            {error && <div className="errsum" role="alert"><strong>{error}</strong></div>}
            <div>
                <label className="field" htmlFor="password">New password <span className="field-help">At least 12 characters.</span></label>
                <input id="password" type="password" autoComplete="new-password" required minLength={12} value={password} onChange={(e) => setPassword(e.target.value)} />
            </div>
            <button className="btn block" type="submit" disabled={busy}>{busy ? 'Saving…' : 'Save new password'}</button>
        </form>
    );
}

/** Non-blocking: learning never waits on email (PRD §12). */
export function VerifyBanner() {
    const { me } = useAuth();
    const [sent, setSent] = useState(false);
    if (!me || me.email_verified) return null;

    return (
        <div style={{ marginBottom: 16 }}>
            <Banner title="Confirm your email address.">
                We sent a link to {me.email}. You can keep practising meanwhile.{' '}
                {sent
                    ? <span role="status">Sent again.</span>
                    : <button type="button" className="link" onClick={async () => { await api.post('/auth/verification/resend').catch(() => undefined); setSent(true); }}>Send it again</button>}
            </Banner>
        </div>
    );
}

interface ExportInfo { id: string; status: string; download_url: string | null; expires_at: string | null }

export function ExportSection() {
    const [info, setInfo] = useState<ExportInfo | null>(null);
    const [busy, setBusy] = useState(false);
    const [error, setError] = useState<string | null>(null);

    const request = async () => {
        setBusy(true);
        setError(null);
        try {
            const r = await api.post<{ export: ExportInfo }>('/me/export', {}, uuid());
            setInfo(r.export);
        } catch (e) {
            setError(e instanceof ApiError ? e.message : 'We could not prepare your export.');
        } finally {
            setBusy(false);
        }
    };

    return (
        <section aria-labelledby="export-h" className="stack">
            <h2 id="export-h">Your data</h2>
            <p className="muted">Download a copy of your answers, progress and evidence as a ZIP of JSON files. It does not include your password or DevStep's lesson answer keys.</p>
            {error && <p className="err" role="alert">{error}</p>}
            {info?.download_url && (
                <Banner role="status" title="Your export is ready.">
                    <a href={info.download_url} download>Download your data</a>. The link needs you to be signed in and expires {formatDate(info.expires_at)}.
                </Banner>
            )}
            <button type="button" className="btn secondary" disabled={busy} onClick={() => void request()}>{busy ? 'Preparing…' : info ? 'Prepare a new export' : 'Prepare my data'}</button>
        </section>
    );
}

export function DeleteSection() {
    const { refresh } = useAuth();
    const [open, setOpen] = useState(false);
    const [password, setPassword] = useState('');
    const [error, setError] = useState<string | null>(null);
    const [busy, setBusy] = useState(false);

    const confirm = async () => {
        setBusy(true);
        setError(null);
        try {
            await api.del2('/me', { password }, uuid());
            setOpen(false);
            await refresh();
        } catch (e) {
            setError(e instanceof ApiError ? e.message : 'We could not start the deletion.');
        } finally {
            setBusy(false);
        }
    };

    return (
        <section aria-labelledby="delete-h" className="stack">
            <h2 id="delete-h">Delete your account</h2>
            <p className="muted">Removes your account, answers, progress and evidence. You have 7 days to change your mind first.</p>
            <button type="button" className="btn secondary" onClick={() => { setPassword(''); setError(null); setOpen(true); }}>Delete my account</button>
            <Dialog open={open} title="Delete your account?" onClose={() => setOpen(false)}>
                <p>Everything is removed after 7 days, including your answers and evidence. Until then you can cancel, and you can still download your data. Export first if you want a copy.</p>
                <label className="field" htmlFor="del-pass">Your password</label>
                <input id="del-pass" type="password" autoComplete="current-password" value={password} onChange={(e) => setPassword(e.target.value)} />
                {error && <p className="err" role="alert">{error}</p>}
                <div className="stack" style={{ marginTop: 16 }}>
                    <button type="button" className="btn block" autoFocus onClick={() => setOpen(false)}>Keep my account</button>
                    <button type="button" className="btn secondary block" disabled={busy || password.length === 0} onClick={() => void confirm()}>{busy ? 'Starting…' : 'Delete in 7 days'}</button>
                </div>
            </Dialog>
        </section>
    );
}

/** The only screen available while a deletion is pending: cancel it, or export, or sign out. */
export function PendingDeletion() {
    const { me, refresh, signOut } = useAuth();
    const nav = useNavigate();
    const h = useFocusOnChange('pending');
    const [error, setError] = useState<string | null>(null);

    return (
        <div className="stack">
            <h1 ref={h} tabIndex={-1}>Your account is waiting to be deleted</h1>
            <Banner tone="attention" title="Scheduled.">Everything will be removed on {formatDate(me?.deletion.effective_at)}. Until then you can cancel and nothing is lost.</Banner>
            {error && <p className="err" role="alert">{error}</p>}
            <button type="button" className="btn" onClick={async () => {
                try { await api.post('/me/deletion/cancel'); await refresh(); nav('/today'); } catch (e) { setError(e instanceof ApiError ? e.message : 'We could not cancel that.'); }
            }}>Cancel the deletion</button>
            <ExportSection />
            <p><button type="button" className="link" onClick={async () => { await signOut(); nav('/'); }}>Sign out</button></p>
        </div>
    );
}
