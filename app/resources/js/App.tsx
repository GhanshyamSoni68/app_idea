import { type ReactNode, useEffect, useRef, useState } from 'react';
import { BrowserRouter, Link, Navigate, NavLink, Route, Routes } from 'react-router-dom';
import { api, uuid } from './api';
import { Banner } from './components/ui';
import { useOnline } from './lib/pwa';
import { AuthProvider, useAuth } from './lib/auth';
import { claimBody, clearGuest, hasGuestWork, loadGuest, saveGuest } from './lib/guestStore';
import { ForgotPage, PendingDeletion, ResetPage, VerifyBanner, VerifyPage } from './pages/Account';
import { Onboarding, SignIn, SignUp } from './pages/Auth';
import { GuestSample, GuestStart, Landing, SampleDone } from './pages/Guest';
import { EvidencePage, RoadmapPage } from './pages/Lists';
import { SessionDone, SessionPage } from './pages/Session';
import { SettingsPage } from './pages/Settings';
import { TodayPage } from './pages/Today';

function Shell({ children, signedIn }: { children: ReactNode; signedIn: boolean }) {
    const online = useOnline();

    return (
        <div className="shell">
            <a className="skip" href="#main">Skip to main content</a>
            <header className="topbar">
                <Link className="brand" to={signedIn ? '/today' : '/'}>DevStep</Link>
                {signedIn && (
                    <nav className="main" aria-label="Main">
                        <NavLink to="/today">Today</NavLink><NavLink to="/roadmap">Roadmap</NavLink><NavLink to="/evidence">Evidence</NavLink>
                    </nav>
                )}
                <Link to="/settings">Settings</Link>
            </header>
            <main id="main" tabIndex={-1}>
                {!online && (
                    <div style={{ marginBottom: 16 }}>
                        <Banner tone="attention" role="status" title="You are offline.">You can keep reading and writing. Checking answers needs a connection, and your work stays on this device until you are back online.</Banner>
                    </div>
                )}
                {children}
            </main>
            {signedIn && (
                <nav className="tabbar" aria-label="Main">
                    <NavLink to="/today">Today</NavLink><NavLink to="/roadmap">Roadmap</NavLink><NavLink to="/evidence">Evidence</NavLink>
                </nav>
            )}
        </div>
    );
}

/** After sign-up the browser's guest bundle is claimed: the server re-evaluates every answer (docs/04 §7). */
function useClaim(enabled: boolean) {
    const [state, setState] = useState<'idle' | 'running' | 'failed' | 'done'>(enabled && hasGuestWork() ? 'running' : 'idle');
    const started = useRef(false);

    const run = async () => {
        const b = loadGuest();
        if (!b.sample || Object.keys(b.sample.attempts).length === 0) { setState('done'); return; }
        const key = b.claimKey ?? uuid();
        saveGuest({ ...b, claimKey: key });
        setState('running');
        try {
            await api.post('/guest/claim', claimBody(loadGuest()), key);
            clearGuest();
            setState('done');
        } catch {
            // The bundle stays in this browser, so the claim can be retried safely with the same key.
            setState('failed');
        }
    };

    useEffect(() => {
        if (enabled && !started.current && hasGuestWork()) { started.current = true; void run(); }
    }, [enabled]); // eslint-disable-line react-hooks/exhaustive-deps

    return { state, retry: run };
}

function Authed() {
    const { me, refresh } = useAuth();
    const claim = useClaim(!!me);
    useEffect(() => { if (claim.state === 'done') void refresh(); }, [claim.state]); // eslint-disable-line react-hooks/exhaustive-deps

    if (!me) return <Navigate to="/sign-in" replace />;
    if (me.deletion.pending) return <Shell signedIn={false}><PendingDeletion /></Shell>;
    if (claim.state === 'running') return <Shell signedIn><p role="status">Moving your sample answers into your account…</p></Shell>;

    return (
        <Shell signedIn>
            {claim.state === 'failed' && (
                <Banner tone="attention" role="alert" title="Your sample answers have not moved yet.">
                    They are still safe in this browser. <button type="button" className="link" onClick={() => void claim.retry()}>Try again</button>
                </Banner>
            )}
            <VerifyBanner />
            <Routes>
                <Route path="/verify" element={<VerifyPage />} />
                <Route path="/onboarding" element={<Onboarding />} />
                <Route path="*" element={me.onboarding_completed ? <SignedInRoutes subjectId={me.subject_id} /> : <Navigate to="/onboarding" replace />} />
            </Routes>
        </Shell>
    );
}

function SignedInRoutes({ subjectId }: { subjectId: string }) {
    return (
        <Routes>
            <Route path="/today" element={<TodayPage subjectId={subjectId} />} />
            <Route path="/session/:id" element={<SessionPage />} />
            <Route path="/session/:id/done" element={<SessionDone />} />
            <Route path="/roadmap" element={<RoadmapPage />} />
            <Route path="/evidence" element={<EvidencePage />} />
            <Route path="/settings" element={<SettingsPage />} />
            <Route path="*" element={<Navigate to="/today" replace />} />
        </Routes>
    );
}

function Root() {
    const { me, loading, offline, refresh } = useAuth();
    if (loading) return <Shell signedIn={false}><p role="status">Loading…</p></Shell>;
    if (!me && offline) {
        return (
            <Shell signedIn={false}>
                <div className="stack">
                    <h1 tabIndex={-1}>You are offline</h1>
                    <p>DevStep could not reach your account. You are not signed out, and anything you wrote is kept on this device.</p>
                    <button type="button" className="btn" onClick={() => void refresh()}>Try again</button>
                </div>
            </Shell>
        );
    }

    const guestRoutes = (
        <Shell signedIn={false}>
            <Routes>
                <Route path="/" element={<Landing />} />
                <Route path="/start" element={<GuestStart />} />
                <Route path="/sample" element={<GuestSample />} />
                <Route path="/sample/done" element={<SampleDone />} />
                <Route path="/sign-up" element={<SignUp />} />
                <Route path="/sign-in" element={<SignIn />} />
                <Route path="/forgot" element={<ForgotPage />} />
                <Route path="/reset" element={<ResetPage />} />
                <Route path="/verify" element={<VerifyPage />} />
                <Route path="/settings" element={<SettingsPage />} />
                <Route path="*" element={<Navigate to="/" replace />} />
            </Routes>
        </Shell>
    );

    return me ? <Authed /> : guestRoutes;
}

export default function App() {
    return (
        <BrowserRouter>
            <AuthProvider>
                <Root />
            </AuthProvider>
        </BrowserRouter>
    );
}
