# 14 · App phase: installable app first, store apps on evidence

Status: Proposal — for discussion (Phase A is built; Phases B and C are gated)

**Purpose.** Make DevStep easy to reach and easy to come back to from a phone or a desktop, without breaking the PRD's decision to launch as a responsive web app (PRD §1, §7). This document defines three steps, what each one changes, what it must never do, and the evidence that unlocks the next.

## Summary

- **Phase A, installable web app (PWA): built.** A web manifest, icons, a service worker and an install control in Settings. DevStep opens full screen from the home screen, starts faster, and the app shell opens offline. It is the same code, API, account and progress. **Proposal, built**
- **Phase B, store apps: gated.** A thin native wrapper (Capacitor is the leading option) around the same single-page app, published to the Apple and Google stores. Built only if the pilot shows phone practice matters and installable web is not enough. **Proposal, locked**
- **Phase C, native-only features: gated again.** Push reminders, widgets and share targets. Each needs its own evidence. **Proposal, locked**
- **Privacy rule for Phase A.** The service worker never caches authenticated API responses. It caches only the app shell, fingerprinted build assets, icons, the public sample and the public roadmap catalogue. Learners may share a device. **Proposal, built and tested**
- **Offline is honest, not magical.** Reading and writing continue. "Check answer" and hints need a connection, so assistance is always recorded by the server (`03` Flow 6, `02` AD-09). A signed-in learner who is offline sees "You are offline", never the guest page and never a silent sign-out. **Proposal, built**
- **Accessibility is gated, not claimed.** An axe-core audit runs against every screen in light and dark themes and fails the build on any serious or critical WCAG 2.2 AA finding. It supplements the manual release check (`08` A-21); it does not replace it. **Proposal, built**
- **Nothing in this phase changes the learning rules.** No new tables, endpoints or evidence paths. Native apps remain a client of the `/v1` API (`02` §9).

## 1. Why this order

| Question | Answer | Label |
| --- | --- | --- |
| Does the PRD allow a native app now? | No. "Native apps" are out of the MVP (PRD §7), and the delivery plan lists them under *Not now* with the trigger "pilot shows phone sessions are a large share and web friction hurts return" (`12` §9). | PRD |
| Does an installable web app cross that line? | No. It is the responsive web app with a manifest and a service worker. It adds no second codebase, no store review and no per-store release cycle. | Proposal |
| Is it worth doing before the pilot? | Yes. It costs about a day, lowers the cost of returning (one tap from the home screen), and gives the pilot real evidence about phone use. `device_class` and `session_started` events already record phone versus desktop (`10` §3.3). | Proposal |
| What does the PRD still need to learn? | "Do they value phone practice enough to use it between desktop labs?" (PRD §16). Phase A makes that measurable. | PRD |

## 2. Phase A: what was built

| Piece | Where | Behaviour |
| --- | --- | --- |
| Manifest | `public/manifest.webmanifest` | Name, `standalone` display, theme colour, 192 and 512 px icons, a maskable icon, and two shortcuts (Today, Evidence). Start URL `/today?source=app`. |
| Icons | `resources/icons/*.svg`, `public/icons/*.png` | One mark (three rising steps). PNGs are rendered from the SVG sources by `npm run icons`. |
| Service worker | `public/sw.js` | Caching rules in §3. Registered only in production builds. Replaces itself on the next load (`skipWaiting`, `clients.claim`). |
| Install control | Settings → "Use DevStep as an app" | Uses the browser's install prompt where it exists. On iPhone and iPad it explains Share → Add to Home Screen. Nowhere else: no banners, no pop-ups (`08` IR-13). |
| Offline notice | App shell | A persistent, polite banner while offline: reading and writing continue, checking needs a connection. |
| Offline account screen | Root | If `/v1/me` cannot be reached, the learner sees "You are offline" with *Try again*. They are not signed out. |

## 3. Caching rules (the privacy contract)

| Request | Strategy | Why |
| --- | --- | --- |
| Any non-`GET` request | Not touched | Writes always go to the network. |
| `/v1/*` except the two public reads below | Network only, never stored | Answers, drafts, sessions, evidence and the account must not outlive the session on a shared device. |
| `GET /v1/guest/sample`, `GET /v1/roadmaps*` | Stale while revalidate | Public, no personal data, no answer keys (`02` §5). Lets a first-time visitor read the sample offline. |
| `/build/assets/*` | Cache first | Fingerprinted, so a changed file has a new URL. |
| `/icons/*`, manifest | Stale while revalidate | Small and rarely changing. |
| Page navigations | Network first, fall back to the cached shell | The shell is the same HTML for every route and carries no personal data. |
| `/up` | Not touched | Health checks must hit the server. |

**Test:** `e2e/app-phase.mjs` signs in, browses every screen, then lists every cache entry and fails if any personal API response is present. **Hypothesis to verify on devices:** how long iOS keeps a non-installed site's cache and local storage after disuse. Local drafts are a convenience; the server copy is the record.

## 4. What works offline

| Action | Offline | Notes |
| --- | --- | --- |
| Open the app | Yes, after one online visit | App shell and assets are cached. |
| Read the guest sample | Yes, if loaded before | Public sample is cached; hints and checks need a connection. |
| Open an account screen (Today, Evidence) | No | Shows "You are offline". Personal data is never stored by the service worker. |
| Keep writing an answer in an open session | Yes | The local draft is written on every edit (`02` §6.9) and synced when back online, with `409` conflict handling. |
| Check an answer or use a hint | No | Never queued. The server must record assistance first (`03` Flow 6). |
| Complete a session | No | Needs the server to apply evidence and progress exactly once. |

## 5. Accessibility gate

| Check | How | Status |
| --- | --- | --- |
| Automated WCAG 2.0 to 2.2 A and AA, plus best-practice rules | axe-core on landing, sample (three states), sign-up, Today, Roadmap, Evidence and Settings, in light and dark. Fails on `serious` or `critical`. | Built (`npm run test:e2e:app`) |
| The audit can fail | Verified against a deliberately bad page: it reports missing button name, missing image alt, missing label and low contrast. | Verified once; repeat if axe is upgraded |
| Keyboard, screen reader, 200% zoom, 320 px, reduced motion | Manual release check, `08` A-21. | **Still required before each pilot release** |
| Native screen readers (VoiceOver, TalkBack) in a wrapper | Manual, per store release. | Phase B only |

Automated tools find roughly the mechanical part of accessibility. A passing audit means "no known machine-detectable failures", not "accessible". The manual checklist remains the acceptance gate (PRD §10).

## 6. Phase B: store apps (locked)

**Unlock evidence (all of):**

1. The pilot shows a large share of sessions on phones (`device_class = phone`), reported with raw counts (`10` §4).
2. Return after absence or week-4 retention is materially worse on phones or on non-installed browsers than on installed use, or interviews name "it is not an app" as a reason for stopping.
3. At least one named, measured web-app limit that a wrapper fixes (for example, no reliable push for reminders on the learner's platform, or storage eviction losing drafts).
4. The founder has capacity: store review, two release trains and device testing are recurring work for one part-time operator.

**Shape if unlocked (Proposal):** the same single-page app in a Capacitor shell. The `/v1` API is unchanged, with cookie sessions in the web view first; bearer tokens only if a platform forces it (`02` §9). No app-only content, no app-only evidence path. Accounts are created by invite, as on the web.

**Risks and costs (all `unverified`):**

| Item | Note |
| --- | --- |
| Store developer fees | Apple and Google charge developer-programme fees. Check the current prices on their official pages before deciding. `unverified` |
| Review policy | Apple has historically questioned apps that only wrap a website. A wrapper needs a real native benefit to pass. `unverified`; read the current guideline before starting |
| Payments | None at the pilot. If a paid path arrives, store rules on in-app purchase would need a legal and product read first. |
| Privacy labels | Each store requires a data-use declaration. It must match `09` §3 (analytics are pseudonymous; no tracking SDKs). |
| Accessibility | A wrapper inherits the web app's accessibility, but VoiceOver and TalkBack behaviour needs its own manual pass. |

## 7. Phase C: native-only features (locked)

| Feature | Unlock evidence |
| --- | --- |
| Push reminders | Pilot shows reminders are valued and email goes unseen (`12` §9). Needs opt-in, the same one-per-day cap, quiet hours and an unsubscribe equivalent (PRD §6, F10). |
| Home-screen widget showing the one next action | Learners ask for it, and it can show Today's single action without exposing a backlog or overdue counts (`05` RV-12). |
| Share target ("send this plan to DevStep") | A real use appears in interviews. Must never ingest employer code (PRD §11). |

## Open questions for discussion

1. **Build Phase A before the planning gate?** *Recommended default:* yes, it is built and tested; keep it. It adds no obligation and helps the pilot.
2. **Where should the install control live?** *Recommended default:* Settings only, plus the iOS instructions there. Revisit after the pilot if installs are very low. A nudge must be at most once, dismissible and never during a session.
3. **Which wrapper for Phase B?** *Recommended default:* Capacitor, because the front end is already a standalone single-page app. Decide only after the unlock evidence.
4. **Should the offline shell also cache an open session's step content for reading?** *Recommended default:* no. It means caching authenticated content. Revisit only if commuting practice is a proven need (`12` §9).
5. **Is the guest analytics ID in storage covered by cookie-law consent?** Same open question as `09` Open question 5; installing the app does not change it.

## PRD traceability

| PRD reference | Covered in |
| --- | --- |
| §1 Responsive web app first; phone-friendly practice | Summary, §1, §2 |
| §7 Out of MVP: native apps | §1, §6 |
| §10 Accessibility product requirements (keyboard, screen reader, reduced motion, non-colour cues, themes) | §5 |
| §11 Technical MVP boundaries (no production access, versioned API as the contract) | §6 |
| §12 Offline drafts, outage tolerance, privacy | §3, §4 |
| §16 Open discovery question on phone practice | §1 |
