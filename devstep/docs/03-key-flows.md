# 03 · Key flows

Status: Proposal — for discussion

## Purpose

Show how the logical modules work together in the fourteen flows that make or
break the DevStep core loop, so the founder can judge scope, risk and effort
before choosing a stack. Diagrams show calls, ownership and failure behaviour.
Algorithms (`05-learning-engine.md`), columns (`04-data-model.md`), screens
(`08-ux-and-screens.md`) and vendors (`01-tech-stack-and-hosting.md`) are out of scope.

## Summary

- One daily loop: Today → one session → attempt → evidence → review schedule →
  completion → next action. Every other flow either feeds this loop or protects it.
- Before sign-up, guest work exists **only in the learner's browser**. When the
  guest claims it, the server evaluates the answers again, so a guest cannot
  forge evidence. **Proposal**
- Anything that could run twice (attempt, completion, claim, migration, lab
  evidence, reminder send) has an idempotency key or a conditional update. The
  database guarantees "exactly once". The client does not.
- Drafts are saved locally first, then sent to the server with `base_revision`.
  If the revision is stale the server returns `409` and the learner chooses
  which version to keep. Nothing is silently overwritten across devices or after
  offline work (PRD §12).
- Hints, worked examples and reveals are recorded on the server and travel with
  every attempt. A reveal caps evidence at `practised` and schedules a fresh
  alternate attempt (PRD §9).
- A missed session or a long absence never creates catch-up debt. The learner
  gets resume or a three-minute refresher, and reviews stay capped (F06, R05).
- Reminders are checked in each learner's IANA time zone, at most one per
  learning day, and suppressed once a session is completed that day. Missing one
  reminder is better than sending two. **Proposal**
- Content and roadmap versions cannot change once published. Retiring keeps
  history, and roadmap migration is always the learner's explicit choice, with
  progress before and after shown (F11, R06).

## How to read the diagrams

| Participant | Meaning |
| --- | --- |
| Learner, Author, Reviewer | People (see actors in `00-conventions.md`). |
| Browser | The single-page app (SPA) running in the learner's browser. |
| Local draft store | Browser-side durable storage for drafts, guest work and queued requests. |
| API | The HTTP layer of the single deployable (the modular monolith). Authenticates, scopes to owner, opens the DB transaction. |
| `identity` … `analytics` | Canonical modules. Arrows between them are in-process calls, not network hops (`02-system-architecture.md`). |
| DB | The one durable database. |
| Scheduler | A job runner backed by the database (ticks and queued jobs). |
| Email provider | The outbound email service (choice in `01`). |
| Content repo, CI publisher | The authoring repository and the continuous-integration (CI) pipeline that validates and publishes content. |
| Learner machine | Where lab kits run. The server never executes learner code. |

Notation: diagrams write path parameters as `:id` because Mermaid labels avoid
braces. `/v1/sessions/:id` means `/v1/sessions/{id}`. `(added)` marks an
endpoint or event not in the conventions list. Unless a note says otherwise,
each API request is one DB transaction.

## Cross-cutting rules used by every flow

| Rule | Behaviour | Label |
| --- | --- | --- |
| Owner scoping | The user ID comes from the login session, never from the request body. Requests for another learner's session, draft, attempt or export return `404`. | PRD §11, §12 |
| Idempotency keys | The Browser creates one key for each thing the learner means to do (submit, complete, claim, migrate, lab evidence) and stores it with the local draft, so a reload reuses it. The server stores (user, operation, key, request hash, response) in `idempotency_keys` under a unique constraint, in the same transaction as the effect. Same key and same body: the stored response is replayed. Same key and different body: `422`. Key still in flight: the request waits for the first to commit, then replays, or gets `409` to retry. | Proposal |
| Conditional transitions | State changes use "update where state = expected". The number of rows changed shows which request won. | Proposal |
| Drafts | Optimistic concurrency with `base_revision` (flow 4). | PRD §12 |
| Analytics | Emitted after commit. Domain events come from the server. Events only the UI knows about (for example `recommendation_seen`) use `POST /v1/events`. Payloads hold IDs, content version, mode and timestamps only, never answer text, code or email. An analytics outage never blocks learning. | PRD F12, §12, §13 |
| Time | Stored in UTC. A **learning day** is the local calendar date in the learner's IANA zone. | PRD §11, Proposal |
| Content pinning | Sessions and attempts reference the exact published content version. Retired versions stay readable. | PRD §11, §12 |
| Non-blocking dependencies | An email, AI or analytics failure degrades a feature but never blocks a session. | PRD §12 |

Pseudo-SQL illustrating "exactly once":

```sql
-- topic completion: only the request that changes the row has side effects
UPDATE topic_progress
   SET state = 'completed', completed_at = now()
 WHERE enrolment_id = :enrolment AND topic_id = :topic AND state <> 'completed';
-- 1 row  → this request completed the topic (recount progress, maybe milestone)
-- 0 rows → already completed, return the stored summary, no second credit
```

## Daily core loop overview

```mermaid
flowchart TD
    REM["Optional reminder email<br/>(flow 10)"] --> OPEN["Learner opens DevStep"]
    OPEN --> GAP{"Back after a gap?"}
    GAP -->|"yes"| WB["Welcome back: summary,<br/>resume or 3-minute refresher (flow 9)"]
    GAP -->|"no"| TODAY["Today: one action, time, why<br/>(flow 3)"]
    WB --> PICK{"Learner choice"}
    TODAY --> PICK
    PICK -->|"start or resume"| SESS["Session player, drafts autosaved<br/>(flow 4)"]
    PICK -->|"smaller"| SMALL["Start small: curated<br/>3-minute task"]
    PICK -->|"rest day"| REST["Planned rest,<br/>no penalty"]
    PICK -.->|"desktop, optional"| LAB["Build session: lab<br/>(flow 13)"]
    SMALL --> SESS
    SESS --> ATT["Submit attempt<br/>(flow 5)"]
    SESS -.->|"stuck"| HELP["Hint, worked example<br/>or reveal (flow 6)"]
    HELP -.-> ATT
    ATT --> EVID["Rubric feedback, skill evidence,<br/>review schedule updated"]
    EVID --> MORE{"More steps?"}
    MORE -->|"yes"| SESS
    MORE -->|"no"| DONE["Complete session (flow 7):<br/>topic progress once, next action"]
    LAB --> DONE
    DONE --> STOP["Explicit stopping point"]
    STOP -.->|"next learning day"| REM
    REST -.-> REM
```

Supporting flows sit outside this loop: guest first visit (1), onboarding (2),
challenge-out and defer (8), content publish (11), roadmap migration (12), and
export and deletion (14).

## Flow index

| # | Flow | Trigger | Main modules | PRD IDs |
| --- | --- | --- | --- | --- |
| 1 | Guest sample, then sign-up and claim | Public sample link | catalogue, assessment, identity, learning | §5, F03, F04, F09, F12 |
| 2 | Onboarding | First sign-in | profile, roadmap, notifications, assessment | F01, R01, F10, §5 |
| 3 | Today recommendation | Learner opens Today | scheduling, learning, roadmap, catalogue | F02, R02, F05, §9 |
| 4 | Start or resume, autosave, offline, second device | Learner starts an action | learning | F03, F09, R05, §12 |
| 5 | Submit, evaluate, evidence, review | Learner submits an answer | learning, assessment, scheduling | F04, F05, F09, §9 |
| 6 | Hint and solution reveal | Learner asks for help | learning, scheduling | §9, F03, F04, F05 |
| 7 | Complete session, topic once, next task | Learner finishes a session | learning, roadmap, scheduling | R02, R04, F09 |
| 8 | Challenge-out versus defer | Learner acts on a topic | roadmap, learning, assessment | R03, R04, §6 |
| 9 | Return after absence | Learner returns after a gap | scheduling, roadmap, learning | F06, R05, §6, §10 |
| 10 | Reminder dispatch and unsubscribe | Scheduler tick or email link | notifications, learning | F10, §6, §12 |
| 11 | Content publish and retire | Author opens a pull request | Content repo, CI publisher, catalogue | F11, §8, §8A |
| 12 | Roadmap version migration | New roadmap version published | roadmap, catalogue | R06, R01, §8A |
| 13 | Lab with local checks | Learner opens a lab | catalogue, learning, assessment | F07, §9, §10, §16 |
| 14 | Export and account deletion | Learner request | identity, all modules | F09, §12 |

---

## Flow 1 — First visit as guest, then account and claim

**Trigger:** someone opens the public sample link (PRD §15, recruitment).
**Preconditions:** a published sample mission exists. It uses auto-scored items
only, so feedback needs no self-check UI. **Proposal**

### 1a · Guest completes one sample scenario

```mermaid
sequenceDiagram
    participant L as Guest learner
    participant B as Browser
    participant LS as Local draft store
    participant API
    participant CAT as catalogue
    participant ASM as assessment
    participant AN as analytics
    L->>B: Open public sample link
    B->>API: GET /v1/guest/sample (added, no login)
    API->>CAT: Sample mission at latest published version
    CAT-->>API: Steps, hints, content version
    API-->>B: 200 sample, cacheable, no personal data
    B->>LS: Create random guest id, store sample and empty draft
    B->>L: Notice - guest work is saved only in this browser on this device
    loop Each step
        L->>B: Answer or move on
        B->>LS: Save draft (device only)
    end
    opt Learner asks for a hint
        B->>LS: Record assistance hint and count (device only)
        B->>L: Authored hint from the sample bundle
    end
    L->>B: Submit answer
    B->>API: POST /v1/guest/attempts (added, guest id, item, answer, assistance, content version)
    API->>ASM: Evaluate against rubric, stateless
    ASM-->>API: Outcome and feedback
    API->>AN: first_answer_submitted and attempt_evaluated with guest pseudonym
    API-->>B: 200 feedback, no learner record stored
    B->>LS: Store attempt, feedback and timestamps (device only)
    B->>L: Feedback plus create an account to keep this
```

### 1b · Create account and claim guest progress

```mermaid
sequenceDiagram
    participant L as Guest learner
    participant B as Browser
    participant LS as Local draft store
    participant API
    participant ID as identity
    participant LRN as learning
    participant ASM as assessment
    participant AN as analytics
    L->>B: Create account or sign in
    B->>API: POST /v1/auth/signup (added, owned by 02)
    API->>ID: Create user and login session
    API-->>B: 201 signed in
    B->>LS: Read guest bundle
    alt Guest bundle present
        B->>L: Add your guest progress to this account?
        L->>B: Yes
        B->>API: POST /v1/guest/claim (bundle, Idempotency-Key is the guest id)
        API->>ID: Claim guest id for this user
        alt Already claimed by this user
            ID-->>API: Stored claim result, replayed
        else Claimed by a different user
            ID-->>API: Reject, nothing copied
        else First claim
            ID->>LRN: Import attempts, and unfinished draft as an open session
            LRN->>ASM: Evaluate each answer again at its content version
            ASM-->>LRN: skill_evidence written, level at most practised
            ID->>AN: Link guest pseudonym to user pseudonym
        end
        API-->>B: 200 claimed counts, or 409 if rejected
        B->>LS: Clear guest bundle only after 200
    else No bundle on this device
        B->>L: Explain that no guest work was found on this device
    end
    B->>L: Continue to onboarding (flow 2)
```

| What goes wrong | Behaviour |
| --- | --- |
| Browser storage blocked (private window) | The sample still runs in memory. The notice says the work disappears when the tab closes. **Proposal** |
| Storage cleared, or sign-up on a different device | Guest work cannot be recovered. This is stated before the first answer (PRD §5). The first device can still claim later while signed in there. |
| Tampered bundle (answers edited, hints hidden) | The server evaluates the answers again. Claimed evidence is capped at `practised`. Guest assistance is self-reported, which is acceptable at that cap. **Proposal** |
| Malformed or oversized bundle | `422`. The bundle stays on the device and the learner can retry or skip. |
| Sample version retired before claim | The claim is accepted. Attempts reference the retired version, and history is kept (PRD §12). |
| Abuse of the unauthenticated evaluate endpoint | Rate limited per IP address and guest ID. Stateless. No free text stored (PRD §12). |
| Claim request fails on the network | The bundle is kept and the claim is retried on the next app open while signed in. |
| Pilot invite link | The invite code is held in the local store and sent at sign-up for cohort tagging (`10`). |

**Idempotency and concurrency:** the guest ID is the claim key. `identity`
records claimed guest IDs under a unique constraint, so a replay returns the
stored result and a second user cannot claim the same bundle. Attempts imported
by a claim are marked as guest-sourced (`04` decides how).

**Analytics events:** `first_answer_submitted` and `attempt_evaluated` (server
side, guest pseudonym), `account_created` (added), `guest_progress_claimed` (added).

**PRD coverage:** §5 (first value before an account; say whether work is saved
only on the device), F03, F04, F09, F12.

---

## Flow 2 — Onboarding

**Trigger:** first sign-in with no enrolment.
**Preconditions:** a signed-in user. Guest progress is either claimed or absent
(flow 1). The browser reports an IANA time zone that the learner confirms.

```mermaid
sequenceDiagram
    participant L as Learner
    participant B as Browser
    participant API
    participant PRF as profile
    participant NTF as notifications
    participant RM as roadmap
    participant CAT as catalogue
    participant ASM as assessment
    B->>L: Stack, one outcome, days, session length, defaults pre-filled
    L->>B: Answers and confirmed time zone
    B->>API: PUT /v1/me/preferences (stack, days, session length, time zone)
    API->>PRF: Save learning_preferences
    B->>API: PUT /v1/me/goal (one outcome)
    API->>PRF: Save goal, replacing any earlier goal
    opt Learner opts in to a reminder
        B->>API: PUT /v1/me/notifications (on, time, quiet hours)
        API->>NTF: Save notification_preferences with consent time
    end
    B->>API: POST /v1/enrolments (roadmap slug)
    API->>RM: Enrol and pin the latest published roadmap version
    RM->>CAT: Version, required topics, estimated effort
    API-->>B: 201 enrolment with outcome, topics, effort, what completion means
    alt Learner takes the diagnostic
        B->>API: GET /v1/me/diagnostic (added)
        API-->>B: Short scenario items grouped by area, each skippable
        L->>B: Answer some areas, skip others
        B->>API: POST /v1/me/diagnostic (answers, skipped areas)
        API->>ASM: Evaluate answered items only
        API->>PRF: Store baseline for answered areas, nothing for skipped
    else Learner skips the whole diagnostic
        B->>API: POST /v1/me/diagnostic (skipped all)
        API->>PRF: Record skipped, every area unknown
    end
    API-->>B: 200 onboarding complete, unknown areas listed
    B->>L: Go to Today (flow 3)
```

Defaults are pre-filled from the PRD pilot schedule: three 10-minute sessions
and one optional 30–45-minute lab a week (PRD §5).

| What goes wrong | Behaviour |
| --- | --- |
| Learner leaves partway | Each step is saved. On return, onboarding resumes at the first missing step. Today shows "Finish setting up (about 1 min)" until an enrolment exists. **Proposal** |
| Diagnostic area skipped | No baseline row and no `skill_evidence` is written, so the area shows as unknown (F01). |
| Strong diagnostic result | Challenge-out is offered for those topics (flow 8). Topics are never completed automatically (R03). **Proposal** |
| Weak result, or all prerequisites unmet | No penalty. The path starts from the beginning and nothing is lowered. |
| Goal does not fit the only launch roadmap | The goal is stored as written and enrolment explains the fit honestly. It counts as a demand signal for R07 (`10`). |
| Reminder time inside quiet hours | Rejected by validation, with an explanation. **Proposal** |
| Guest progress already claimed | Enrolment derives starting topic states from existing attempts, so the sample topic becomes `in_progress`. **Proposal** |
| Time zone undetectable | The learner picks one. Reminders stay off until a zone is stored. |

**Idempotency and concurrency:** the `PUT`s replace whole resources and are
naturally idempotent. `POST /v1/enrolments` relies on "one active enrolment per
user", so a retry returns the existing enrolment. The diagnostic is accepted
once per enrolment and a second `POST` returns the stored result, which keeps
the baseline stable for comparison (PRD §13). **Proposal**

**Analytics events:** `onboarding_completed` (diagnostic taken, count of skipped
areas, reminder opt-in flag, session length; no free text), `enrolment_created`
(added; needed for enrolment-to-first-topic activation, PRD §8A).

**PRD coverage:** F01, R01, F10 (consent), §5 onboarding.

---

## Flow 3 — Today recommendation

**Trigger:** the learner opens Today, or the Browser refreshes it after a session.
**Preconditions:** an active enrolment. The selection rules belong to
`05-learning-engine.md`. This flow shows only the calls.

```mermaid
sequenceDiagram
    participant L as Learner
    participant B as Browser
    participant API
    participant SCH as scheduling
    participant PRF as profile
    participant LRN as learning
    participant RM as roadmap
    participant CAT as catalogue
    L->>B: Open Today
    B->>API: GET /v1/today
    API->>SCH: Recommend for user, default mode
    SCH->>PRF: Session length, planned days, time zone
    SCH->>LRN: Open session for this user?
    alt Unfinished session exists
        LRN-->>SCH: Session, current step, mission, mode
        SCH->>SCH: Primary action is resume
    else No open session
        SCH->>SCH: Due reviews this learning day, capped at 2 or 1 by mode
        SCH->>RM: Prerequisite-ready topics in the pinned version
        RM-->>SCH: Ready topics, states, weekly progress counts
        SCH->>CAT: Missions that fit the session length
        CAT-->>SCH: Candidate missions at pinned versions
        SCH->>SCH: Choose one primary action and rationale (05)
    end
    SCH-->>API: Action, minutes, why, roadmap, module, topic, smaller option
    API-->>B: 200 recommendation with recommendation id
    B->>L: One action with time and why it matters
    B->>API: POST /v1/events recommendation_seen
    opt Learner asks for something smaller
        L->>B: Start small
        B->>API: GET /v1/today?mode=small
        API->>SCH: Recommend for user, small mode
        SCH->>CAT: Curated small equivalent for the same skill
        CAT-->>SCH: Small mission or one retrieval item
        API-->>B: 200 small recommendation, review cap 1
    end
```

| What goes wrong | Behaviour |
| --- | --- |
| Smaller requested while a session is open | Today offers "Do the next step only (about 3 min) and stop" at a natural stopping point. The open session is never cut off mid-explanation or thrown away (PRD §9). **Proposal** |
| More reviews due than the cap allows | Only the cap is shown. The rest stay due and are spread across later sessions. No overdue counter (F05, §6). |
| No prerequisite-ready topic (for example a deferred prerequisite) | Today names the blocking topic and offers it or a challenge-out (PRD §12 edge case; see open question 3). |
| Content exhausted, or roadmap complete | Today shows the completion summary, maintenance reviews and an offer of another roadmap, with no automatic enrolment (PRD §8A). |
| Learner picks a rest day | No session. The Browser offers to snooze today's reminder through `PUT /v1/me/notifications`. The weekly target is flexible and nothing is owed (PRD §6). |
| Enrolment paused, or learner returning after a gap | See flow 9. |
| Newer roadmap version published | Non-blocking notice (flow 12). |
| Slow dependency | Today calls no external service (email, AI, analytics) synchronously. Budget: p95 under 500 ms (PRD §12). |

**Idempotency and concurrency:** `GET /v1/today` is read-only. The
recommendation is calculated on request and not stored. The opaque
`recommendation_id` is echoed back by `POST /v1/sessions` so the funnel can be
joined in analytics. Two devices see the same recommendation for the same state.
**Proposal**

**Analytics events:** `recommendation_seen` (kind: resume, review, new or small;
mode; context: normal, missed or returning; recommendation ID).

**PRD coverage:** F02, F05 (cap), R02 (next prerequisite-ready task), §9
selection order, §10 Today, §12 performance budget.

---

## Flow 4 — Start or resume a session, autosave, offline, second device

**Trigger:** the learner starts or resumes the recommended action.
**Preconditions:** signed in. The local draft store is available (if not, the
UI warns that offline protection is off).

### 4a · Start or resume, autosave and offline

```mermaid
sequenceDiagram
    participant L as Learner
    participant B as Browser
    participant LS as Local draft store
    participant API
    participant LRN as learning
    participant CAT as catalogue
    participant DB
    L->>B: Start or resume the recommended action
    B->>API: POST /v1/sessions (mission, mode, recommendation id)
    API->>LRN: Start or resume
    LRN->>DB: Find open session for user and session kind
    alt Open session exists
        LRN-->>API: Existing session, current step, draft revision n
    else None open
        LRN->>CAT: Steps for the mission at the pinned content version
        LRN->>DB: Insert learning_sessions as open and session_drafts at revision 0
        LRN-->>API: New session, step 1, revision 0
    end
    API-->>B: 200 resumed or 201 started
    B->>LS: Cache steps and draft with its revision
    loop Each step transition, and every few seconds while typing
        L->>B: Type, or move to the next step
        B->>LS: Write draft locally first, mark unsynced
        B->>API: PUT /v1/sessions/:id/draft (base_revision n)
        alt Online and revision matches
            API->>DB: Update draft where revision is n
            API-->>B: 200 revision n plus 1
            B->>LS: Mark synced at the new revision
        else Offline or request failed
            B->>L: Show unsynced - saved on this device
            Note over B,LS: Retry with backoff and on reconnect, same base_revision
        else Revision is stale
            API-->>B: 409 conflict, continue in 4b
        end
    end
```

### 4b · Second device and a stale `base_revision`

```mermaid
sequenceDiagram
    participant PA as Phone (device A)
    participant LA as Laptop (device B)
    participant API
    participant LRN as learning
    participant DB
    PA->>API: PUT /v1/sessions/:id/draft (base_revision 5)
    API->>LRN: Save draft
    LRN->>DB: Update where revision is 5
    API-->>PA: 200 revision 6
    LA->>API: PUT /v1/sessions/:id/draft (base_revision 5, edited offline)
    API->>LRN: Save draft
    LRN->>DB: Update where revision is 5 changes 0 rows
    LRN-->>API: Stale, current revision is 6
    API-->>LA: 409 conflict with server draft at revision 6
    LA->>LA: Keep local draft, show both versions side by side
    alt Learner keeps the other device's version
        LA->>LA: Replace local draft with revision 6
    else Learner keeps this device's version
        LA->>API: PUT draft (base_revision 6, chosen after conflict)
        API->>DB: Update where revision is 6
        API-->>LA: 200 revision 7
        Note over PA,API: The phone's next save with base 6 gets 409 and the same choice
    else Learner decides later
        LA->>LA: Keep both copies locally, session stays unsynced
    end
```

| What goes wrong | Behaviour |
| --- | --- |
| Start tapped twice | A unique constraint allows one open session per user and kind. The second insert fails and the existing session is returned. |
| Browser closed mid-step | The local draft is restored on reopen. If it is newer than the server copy, it syncs with its `base_revision`. |
| Offline when starting a new session | Not possible, because the server must create the session. An open session already cached can continue offline. **Proposal** |
| Offline at submit | The submission is queued. See flow 5. |
| Retried `PUT` whose first try actually succeeded | It gets `409`, but the server draft matches the local content hash, so the client treats it as success. **Proposal** |
| Learner does not want the open session | Today's secondary option calls `POST /v1/sessions` with a set-aside flag. The old session becomes `abandoned` and its draft is kept (R05). **Proposal** |
| Different user signs in on the same browser | Local drafts are keyed by user ID. The previous user's drafts are never shown. Signing out warns about unsynced drafts, then clears them (PRD §12). |
| Content version retired during a long offline period | The session stays pinned to its version and the draft syncs normally. |
| Draft too large or invalid | `413` or `422`. The local copy is kept and the learner is told. |

**Idempotency and concurrency:** draft saves need no idempotency key because
`base_revision` makes them conditional. The Browser sends one save per session
at a time and merges pending edits into the next save. In the MVP a conflict
asks the learner to choose for the whole draft (see open question 5).

**Analytics events:** `session_started` (new session) or `session_resumed`
(existing session), both from the server. Conflict counts and unsynced durations
are operational metrics (`09`), not analytics events.

**PRD coverage:** F03 (drafts between steps), F09 (cross-device progress), R05
(drafts preserved), §12 (local draft, unsynced status, no silent overwrite).

---

## Flow 5 — Submit attempt, evaluate, write evidence, update review schedule

**Trigger:** the learner submits an answer in the player.
**Preconditions:** an open session owned by the learner. The draft is synced, or
the submission is queued (see table).

```mermaid
sequenceDiagram
    participant L as Learner
    participant B as Browser
    participant API
    participant LRN as learning
    participant ASM as assessment
    participant SCH as scheduling
    participant DB
    L->>B: Submit answer
    B->>B: Create Idempotency-Key K, store it with the local draft
    B->>API: POST /v1/sessions/:id/attempts (K, item, answer, draft revision)
    API->>DB: Begin, insert idempotency_keys for user, K and request hash
    alt K already completed with the same hash
        DB-->>API: Stored response
        API-->>B: 200 replay, no new records
    else K exists with a different hash
        API-->>B: 422 key reused for a different request
    else New key
        API->>LRN: Record attempt with content version and assistance so far
        LRN->>ASM: Evaluate against rubric
        alt Structured item
            ASM->>DB: Insert skill_evidence (level, basis auto_scored, assistance, version)
            ASM-->>LRN: Outcome and feedback
        else Open-ended response
            ASM-->>LRN: Exemplar and self-check checklist
            Note over B,ASM: The self-check is a second phase on the same endpoint with a new key. Evidence is written then with basis self_assessed.
        end
        LRN->>SCH: Outcome and assistance for the skill
        SCH->>DB: Upsert review_schedule with next due date and alternate prompt
        API->>DB: Store response under K and commit
        API-->>B: 201 feedback, evidence wording, next step
    end
    B->>L: Feedback that names the evidence
```

The level written (`introduced` … `retained`) and the next interval (about 1, 3,
7 or 21 days) are decided by `05-learning-engine.md`. Feedback names evidence,
for example "You used a query plan to choose an investigation", never "mastered"
(PRD §5).

| What goes wrong | Behaviour |
| --- | --- |
| Network drops after commit, before the response arrives | The client retries with the same K and gets a replay. No duplicate attempt or evidence (PRD §12). |
| Double-click, or two tabs sending the same K at once | The unique insert makes the second request wait until the first commits, then it replays. Otherwise it gets `409` and retries. **Proposal** |
| Two devices submit the same item and draft revision with different keys | Treated as a duplicate on (session, item, draft revision). The existing result is returned. **Proposal** |
| Offline at submit | The submission is queued in the local store with K and shown as "Saved — will be checked when you're back online". It is sent on reconnect. No feedback while offline. **Proposal** |
| Evaluation error (rubric defect) | Rollback and `500`. K is not stored as completed, so a retry works. The answer stays in the draft. The operator is alerted (`09`). |
| Content retired between start and submit | Evaluated against the pinned version, which never changes. |
| Repeated incorrect answers | A worked example and a prerequisite refresher are offered and an earlier alternate review is scheduled. No punitive wording (PRD §6, §9). |
| Correct but heavily assisted | Recorded with its assistance, capped below `demonstrated`, with an earlier review (PRD §9). |
| Item was a due review | `review_completed` is emitted and the interval is extended or shortened (05). |

**Idempotency and concurrency:** the attempt, the evidence, the review schedule
and the idempotency record are committed in one transaction (one deployable,
one database). Evidence is append-only, so a later attempt never edits earlier
evidence.

**Analytics events:** `first_answer_submitted` (first attempt in the session),
`attempt_evaluated` (outcome category, assistance, content version, mode),
`review_completed` (when the item came from the review queue). No answer text.

**PRD coverage:** F04 (task version, outcome, assistance), F05, F09
(idempotency), §9, §11 (attempts reference the exact content version), §12
(duplicate submissions).

---

## Flow 6 — Hint use and solution reveal

**Trigger:** the learner asks for a hint, a worked example or the solution.
**Preconditions:** an open session on an item with authored hints. Not a
challenge-out session (flow 8).

```mermaid
sequenceDiagram
    participant L as Learner
    participant B as Browser
    participant API
    participant LRN as learning
    participant CAT as catalogue
    participant SCH as scheduling
    participant DB
    L->>B: Ask for a hint
    B->>API: POST /v1/sessions/:id/hints (item, hint index 1)
    API->>LRN: Record assistance hint, count 1, if this index is unused
    LRN->>CAT: Authored hint 1 for the item at the pinned version
    API-->>B: 200 hint text and hints remaining
    opt Still stuck
        B->>API: POST /v1/sessions/:id/hints (item, kind worked_example)
        API->>LRN: Record assistance worked_example
        API-->>B: 200 worked example and prerequisite refresher link
    end
    opt Learner chooses to reveal the solution
        L->>B: Reveal solution
        B->>L: Confirm - this will not count as demonstration, a fresh attempt comes later
        L->>B: Confirm
        B->>API: POST /v1/sessions/:id/reveal (item)
        API->>LRN: Record solution_revealed once per item and session
        LRN->>SCH: Schedule a fresh alternate attempt for the skill
        SCH->>DB: Upsert review_schedule with alternate prompt and earlier due date
        LRN->>CAT: Authored solution and explanation
        API-->>B: 200 solution and explanation
        B->>L: Solution shown, learning continues
    end
    L->>B: Submit answer (flow 5)
    Note over B,SCH: The attempt carries the strongest assistance used. With solution_revealed the level is at most practised.
```

| What goes wrong | Behaviour |
| --- | --- |
| Hint request retried with the same index | Not counted twice. The same hint is returned. |
| Client asks for a later hint index first | The server returns only the next unused hint, so hints stay graduated. |
| No hints left | A worked example, then the reveal, is offered. |
| Offline | Hints come from the API so that assistance is always recorded. Offline, the UI says "Hints need a connection" and the learner can keep writing. **Proposal** (open question 10) |
| Explanation viewed after an evaluated attempt | That is feedback, not a reveal. Evidence already written is unchanged. |
| Reveal followed by a correct answer | The attempt is stored with `solution_revealed`, the level is at most `practised`, and a fresh attempt is scheduled (PRD §9). |
| Reveal on two devices | Recorded once per item per session (unique). |
| Repeated reveals over weeks | Tracked as a guardrail metric (PRD §13). No punitive UI. |
| Future AI tutor (F13, P1) | Its hints would be recorded as `hint` with a source, with authored hints as fallback. Not designed here. |

**Idempotency and concurrency:** the hint index and the "once per item and
session" rule for reveals make retries safe. Assistance is stored on the session
step, and the attempt copies the strongest assistance used at submit time.

**Analytics events:** `hint_used` (hint index and kind), `answer_revealed`
(item, content version).

**PRD coverage:** §9 (reveal never demonstrates, schedule a fresh attempt), F03
(hints), F04 (assistance recorded), F05, §6 ("I do not understand").

---

## Flow 7 — Complete session, update topic exactly once, next task

**Trigger:** the learner finishes the last step or stops at an explicit stopping point.
**Preconditions:** an open session. The draft is synced, and any `409` conflict
has been resolved first (flow 4b).

```mermaid
sequenceDiagram
    participant L as Learner
    participant B as Browser
    participant API
    participant LRN as learning
    participant RM as roadmap
    participant SCH as scheduling
    participant DB
    L->>B: Finish the last step
    B->>API: POST /v1/sessions/:id/complete (Idempotency-Key K)
    API->>DB: Begin, claim K
    alt K already completed
        API-->>B: 200 stored completion summary
    else First request with K
        API->>LRN: Close session where state is open
        alt Session already closed by another request
            LRN-->>API: Already completed, no further changes
        else Closed by this request
            LRN->>RM: Session completed for topic T
            RM->>DB: Read completion rules and evidence for T
            alt Rules met and topic not yet completed
                RM->>DB: Set topic completed where state is not completed
                RM->>DB: Recount completed required topics
                opt Every required topic now completed
                    RM->>DB: Record roadmap completion milestone once
                end
            else Rules not yet met
                RM-->>LRN: Topic stays in_progress
            end
        end
        API->>SCH: Next prerequisite-ready action
        SCH-->>API: Next action with time and why
        API->>DB: Store summary under K and commit
        API-->>B: 200 evidence, topic state, n of m required topics, next action
    end
    B->>L: Explicit stopping point and the next action
```

| What goes wrong | Behaviour |
| --- | --- |
| Retry after a timeout with the same K | Replays the stored summary (F09 idempotent completion). |
| Two devices complete the same session with different keys | The conditional close lets only one win. The other gets `200` with "already completed" and no second topic credit. |
| Two sessions satisfy the same topic at once (for example practice and challenge-out) | The conditional topic update records one `completed_at`. |
| Completion with unsynced draft | The Browser syncs first. A `409` is resolved before completing. |
| Stopping before every item is answered | Allowed at any explicit stopping point. Unanswered items stay unattempted and the topic rules decide credit. A `small` session counts as practice, not proof (PRD §5). |
| Feedback not yet reviewed | The pilot topic rule needs an attempt, a feedback review and an alternate topic check (PRD §8A). Feedback review is recorded when the learner moves past the feedback step (05). |
| Roadmap completed | The milestone is recorded once. The summary separates optional labs and self-assessed evidence (R04). Later reviews or migration never take it away (PRD §8A). |
| Delayed review later failed | The topic stays completed. The Evidence view shows the practice need (PRD §8A). |
| Nothing prerequisite-ready next | Same as flow 3 (maintenance, or explain the blocker). |

**Idempotency and concurrency:** there are three guards: the idempotency key
(retries), the conditional session close (devices), and the conditional topic
update (sessions racing for the same topic). The milestone row is unique per
enrolment.

**Analytics events:** `session_completed` (mode, duration bucket, content
version), `topic_completed` (added; basis is rules met or challenge-out),
`roadmap_completed` (added).

**PRD coverage:** R02, R04, F09 (idempotent completion), §8A completion rules
and progress formula.

---

## Flow 8 — Challenge-out versus manual defer

**Trigger:** the learner opens a topic in Roadmap, or Today suggests a
challenge after a strong diagnostic result.
**Preconditions:** the topic is in the pinned roadmap version and is not completed.

```mermaid
sequenceDiagram
    participant L as Learner
    participant B as Browser
    participant API
    participant RM as roadmap
    participant LRN as learning
    participant CAT as catalogue
    participant DB
    L->>B: Open a topic in Roadmap
    B->>L: Options - learn it, challenge out, or defer
    alt Challenge out
        B->>API: POST /v1/topics/:id/challenge
        API->>RM: Check topic is pinned, not completed, challenge available
        RM->>CAT: Alternate challenge items, auto-scored only
        API->>LRN: Create session with purpose challenge, hints and reveal off
        API-->>B: 201 challenge session
        Note over B,LRN: Answers go through flow 5 with assistance none
        B->>API: POST /v1/sessions/:id/complete (Idempotency-Key)
        API->>RM: Evaluate the challenge rule for the topic
        alt Passed, unassisted
            RM->>DB: Topic completed once, basis challenge_out
            API-->>B: Topic complete, evidence demonstrated
        else Not passed
            RM->>DB: Topic state unchanged, attempts kept as practised
            API-->>B: Not yet - here is what to practise, no penalty
        end
    else Defer
        B->>L: Explain - deferred topics never count toward completion
        L->>B: Confirm defer
        B->>API: POST /v1/topics/:id/defer
        API->>RM: Defer topic
        RM->>DB: Set deferred where not completed, progress count unchanged
        RM-->>API: Dependent topics affected
        API-->>B: 200 deferred, dependents shown with a prerequisite warning
    end
```

| What goes wrong | Behaviour |
| --- | --- |
| Defer on a completed topic | `409`. Completion is historical. **Proposal** |
| Returning to a deferred topic | Starting any mission in it moves it to `in_progress`. **Proposal** |
| Deferred topic is a prerequisite | Dependents become usable with a visible warning. Completion still needs the deferred topic. **Proposal** (open question 3) |
| Roadmap with deferred required topics | Not complete (R03, R04). The summary lists them and offers a challenge-out. |
| Challenge failed | Retry no sooner than the next learning day, with different items if any exist, otherwise the normal path. **Proposal** |
| Challenge left halfway | The session stays open (resume-first) or is set aside. The topic state is unchanged. |
| Open-ended items | Excluded from challenge sets, because self-report alone leaves skill unverified (PRD §6). |
| Defer tapped twice | Already deferred, so it returns `200`. |
| A short session is already open | The Browser offers to finish it or set it aside first (one open short session). |

**Idempotency and concurrency:** both the challenge completion and the defer use
conditional updates where the state is not `completed`, so a defer can never
overwrite a challenge pass that raced it.

**Analytics events:** `session_started` (purpose challenge), `attempt_evaluated`,
`session_completed`, `topic_completed` (added, basis `challenge_out`),
`topic_deferred` (added).

**PRD coverage:** R03, R04, §6 ("I already know this"), §8A (defer earns no credit).

---

## Flow 9 — Return after a missed session or a long absence

**Trigger:** the learner opens DevStep after a planned learning day passed with
no completed session ("missed"), or after 7 or more days with no completed
session ("long absence"). Both thresholds are a **Proposal** (open question 6).
**Preconditions:** an active or paused enrolment.

```mermaid
sequenceDiagram
    participant L as Learner
    participant B as Browser
    participant API
    participant SCH as scheduling
    participant RM as roadmap
    participant LRN as learning
    L->>B: Open DevStep after a gap
    opt Enrolment was paused by the learner
        B->>L: Paused since that date, resume when ready
        L->>B: Resume
        B->>API: PATCH /v1/enrolments/:id (status active)
        API->>RM: Resume, reschedule future work, no missed-lesson debt
    end
    B->>API: GET /v1/today
    API->>SCH: Recommend for user
    SCH->>LRN: Last completed session, open session, recent evidence
    alt Missed planned session, under 7 days
        SCH->>SCH: Same-size plan, reviews capped by mode, nothing doubled
        SCH-->>API: Context missed, resume or reschedule
    else Long absence, 7 days or more
        SCH->>SCH: One retrieval check from practised skills, overdue reviews spread out
        SCH-->>API: Context returning, summary of prior work, refresher, resume option
    end
    API-->>B: 200 recommendation with context
    B->>L: Welcome back. Continue your last task or try a three-minute refresher.
    alt Continue last task
        B->>API: POST /v1/sessions (returns the open session)
        API-->>B: 200 resumed with the saved draft
    else Three-minute refresher
        B->>API: POST /v1/sessions (mode small, retrieval check)
        API-->>B: 201 small session, then flows 5 and 7
    end
```

| What goes wrong | Behaviour |
| --- | --- |
| Large review backlog | 2 visible in `practise`, 1 in `small`. The rest stay due and are spread. No red counter (PRD §6, §9). |
| Retrieval check fails | Nothing is lowered. A worked example and an earlier review follow (PRD §9). |
| Open session is weeks old | Still resumable. The refresher is offered first when the open step depends on recall (05). |
| Content of the open session retired during the absence | Resumes on the pinned version. If the objective materially changed, "refresh recommended" is shown (PRD §12). |
| Migration offer pending | Shown only after the learner's first action, to keep the return light. **Proposal** |
| Paused enrolment | No reminders and no "missed" counting while paused (R05). |
| Learner chooses reschedule | `PUT /v1/me/preferences` changes the planned days. Future work is recalculated and nothing is owed. |
| Returning on a new device | Server state is used. The old device's unsynced drafts sync when it next comes online (flow 4b if they conflict). |
| Tone | No guilt, no streak loss, no comparisons (PRD §6). |

**Idempotency and concurrency:** the return context is calculated, not stored.
It disappears once a session completes. Resuming the enrolment is a conditional
`PATCH` from paused to active.

**Analytics events:** `recommendation_seen` (context `missed` or `returning`),
`session_resumed` or `session_started` (mode `small`), `review_completed`,
`session_completed`. The return-after-absence metric is derived from these (`10`).

**PRD coverage:** F06, R05, F05 (cap, deferred items kept), §6 (missed session,
long absence, many reviews), §10 return screen.

---

## Flow 10 — Reminder dispatch, pause, snooze and unsubscribe

**Trigger:** a Scheduler tick (proposed every 15 minutes), a settings change, or
an unsubscribe link.
**Preconditions:** the learner has opted in (F10). A learning day is the local
date in the learner's IANA zone.

### 10a · Tick, per-learner evaluation, send

```mermaid
sequenceDiagram
    participant SJ as Scheduler
    participant NTF as notifications
    participant LRN as learning
    participant DB
    participant EP as Email provider
    SJ->>NTF: Reminder tick
    NTF->>DB: Learners with reminders on, not paused, not snoozed, not deleted
    loop Each candidate, in small batches
        NTF->>NTF: Local time and learning day in the learner's time zone
        alt Not a planned day, before reminder time, past send window, or quiet hours
            NTF->>NTF: Skip, checked again next tick
        else Due by time
            NTF->>LRN: Session completed this learning day?
            alt Already completed today
                NTF->>DB: Insert delivery for user and learning day as suppressed
            else Not completed
                NTF->>DB: Insert delivery for user and learning day as sending
                alt Row already exists for this learning day
                    NTF->>NTF: Skip, at most one per learning day
                else Inserted
                    NTF->>EP: Send reminder with unsubscribe link and header
                    alt Provider accepts
                        EP-->>NTF: Provider message id
                        NTF->>DB: Mark sent
                    else Timeout or temporary error
                        EP-->>NTF: Error
                        NTF->>DB: Mark retry with a next try inside the window
                    else Permanent rejection
                        EP-->>NTF: Address rejected
                        NTF->>DB: Mark failed, flag for an in-app notice
                    end
                end
            end
        end
    end
```

### 10b · Pause, snooze and unsubscribe

```mermaid
sequenceDiagram
    participant L as Learner
    participant MC as Mail client
    participant B as Browser
    participant API
    participant NTF as notifications
    participant DB
    alt Pause or snooze in settings
        L->>B: Pause until a date, or snooze today
        B->>API: PUT /v1/me/notifications (paused until, or snoozed until)
        API->>NTF: Update preferences
        NTF->>DB: Save, cancel pending retries for affected days
        API-->>B: 200, server emits reminder_paused
    else Unsubscribe link in an email
        L->>MC: Click unsubscribe
        MC->>B: Open confirmation page with signed token
        B->>L: Stop reminder emails?
        L->>B: Confirm
        B->>API: POST /v1/notifications/unsubscribe (token, no login)
        API->>NTF: Verify signature, purpose and key version
        alt Token valid
            NTF->>DB: Reminders off, source recorded as unsubscribe link
            API-->>B: 200 unsubscribed, repeat calls also 200
        else Token invalid or tampered
            API-->>B: 400, offer sign-in to manage reminders
        end
    else One-click unsubscribe from the mail client
        MC->>API: POST /v1/notifications/unsubscribe (token from header link)
        API->>NTF: Verify and turn reminders off
        API-->>MC: 200
    end
```

| What goes wrong | Behaviour |
| --- | --- |
| Clocks go forward (DST), so the reminder time does not exist that day | Send at the next valid local time that day. |
| Clocks go back (DST), so an hour repeats | Unique (user, learning day) row, so only one send (PRD §12 DST edge case). |
| Learner changes time zone | The next tick uses the new zone. The unique row prevents a second send on the same date. Crossing many zones may skip a day, which is accepted. |
| Scheduler down for hours | No backlog burst. A reminder is sent only inside a send window (proposed: 2 h after the reminder time), otherwise it is recorded as skipped. **Proposal** |
| Overlapping ticks or two workers | Insert-or-skip on the unique delivery row, plus row locks when claiming retries. |
| Crash after the provider call, before "sent" is recorded | The row stays `sending` and is not sent again unless the provider honours an idempotency key (unverified, `01`). Missing one reminder is better than sending two. **Proposal** |
| Provider outage | Retries inside the window, then `failed`. Learning is unaffected (PRD §12). An alert fires on a high failure rate (`09`). |
| Hard bounce | Reminders are paused automatically, with an in-app notice to fix the address. **Proposal** |
| Session completed after the reminder was sent | Nothing more that day. A `small` session counts as completed. |
| Paused enrolment or deletion pending | No candidates. |
| Link scanners that prefetch the URL | `GET` only shows the confirmation page. Only `POST` changes state. **Proposal** |
| Token for a deleted account | `200`, no effect, nothing disclosed. |
| Re-subscribing | Only from signed-in settings, with a new consent timestamp. |
| Weeks of reminders with no return | Open question 9 (offer to pause or reduce frequency). |
| Email content | No urgency, no fear of job loss, no comparisons, no answer text (PRD §6). |

**Idempotency and concurrency:** (user ID, learning day) is the
email-dispatch idempotency key that PRD §11 requires, enforced by a unique
constraint on `notification_deliveries`. Each tick is safe to run twice. The
one-click `POST` follows RFC 8058 (one-click unsubscribe), so mail clients can
unsubscribe without a page.

**Analytics events:** `reminder_paused`, plus `reminder_sent` (added),
`reminder_suppressed` (added, with reason) and `reminder_disabled` (added,
source settings or link) for the "reminders disabled" guardrail (PRD §13).

**PRD coverage:** F10, §6 (at most one per learning day, snooze, pause, quiet
hours, time zones), §12 (email failure must not block, DST).

---

## Flow 11 — Content publish and retire

**Trigger:** an Author opens a pull request in the Content repo.
**Preconditions:** the content format and validation rules are defined in
`06-content-system.md`. The Reviewer is not the Author.

```mermaid
sequenceDiagram
    participant AU as Author
    participant CR as Content repo
    participant CI as CI publisher
    participant RV as Reviewer
    participant CAT as catalogue
    participant DB
    AU->>CR: Pull request with missions, items, rubrics, sources (in_review)
    CR->>CI: Run validation on the pull request
    CI->>CI: Schema and required fields - objective, hints, sources, version scope
    CI->>CI: References resolve, prerequisite graphs acyclic, alternate prompts present
    alt Validation fails
        CI-->>CR: Failing check with report, for example cycle A to B to A
        CR-->>AU: Fix and push again
    else Validation passes
        CI-->>CR: Passing check and a preview bundle
        RV->>CI: Try every exercise in the preview
        alt Changes needed
            RV->>CR: Request changes
        else Approved
            RV->>CR: Approve, reviewer and last-reviewed date recorded
            AU->>CR: Merge to the main branch
            CR->>CI: Build bundle with version and content hash
            CI->>CAT: POST /v1/admin/catalogue/bundles (added, operator token)
            CAT->>DB: One transaction inserts content_versions as published
            CAT-->>CI: Published version ids, or already published for this hash
        end
    end
    opt Retire content later
        AU->>CR: Pull request marking items retired with a reason
        CI->>CAT: Publish the retirement after review and merge
        CAT->>DB: Set status retired, delete nothing
    end
```

Status mapping (**Proposal**): `draft` is a branch, `in_review` is an open pull
request, `published` and `retired` exist in `catalogue`. Drafts never reach the
runtime database, so `catalogue` stays read-only at runtime apart from this path.

| What goes wrong | Behaviour |
| --- | --- |
| Prerequisite cycle in `topic_dependencies` or `skill_prerequisites` | CI rejects it and reports the cycle path (PRD §8A). |
| Missing source URL, version scope, misconception notes or alternate prompts | CI rejects it (PRD §8, F11). |
| Accessibility gaps (missing alt text, unlabelled code language) | A CI lint fails (PRD §8 accessibility review). |
| Author approves their own pull request | Does not count. The repository requires a different approver. With a solo founder, a second reviewer is needed before the pilot (PRD §14). |
| Publish fails partway | The transaction rolls back. Rerunning is safe because of the content hash. |
| Same bundle published twice | No change. |
| Edit to a published mission | Creates a new content version. Open sessions keep their pinned version and attempts keep their reference (PRD §11). |
| Objective, rubric or topic set changes | Needs a new roadmap version (flow 12). Typo fixes are patch versions inside the topic. Policy in `06`. |
| Retiring content that is in an open session | The session can finish. New sessions do not select the content. Evidence is kept (PRD §12). |
| Objective materially changed | Related evidence shows "refresh recommended" (PRD §12). |
| Bad publish | Publish a newer version that replaces it. Never delete rows that attempts reference. **Proposal** |

**Idempotency and concurrency:** the bundle content hash is the publish key.
Publishes run one after another (one CI job at a time on the main branch).

**Analytics events:** none. Publishes go to an operator audit log (`09`). Every
learner event already carries the content version.

**PRD coverage:** F11 (draft, review, publish, retire, sources, version,
reviewer, last-reviewed date), §8 (reviewer tries every exercise), §8A (reject
cycles), §12 (retired content keeps evidence).

---

## Flow 12 — Roadmap version migration offer

**Trigger:** a newer roadmap version is published (flow 11) while learners are
enrolled in an older one.
**Preconditions:** an enrolment pinned to the older version (R01). The offer is
calculated on request, with no batch job. **Proposal**

```mermaid
sequenceDiagram
    participant L as Learner
    participant B as Browser
    participant API
    participant RM as roadmap
    participant CAT as catalogue
    participant DB
    Note over CAT: New roadmap version published. Enrolments stay pinned.
    B->>API: GET /v1/today
    API->>RM: Newer published version than the pinned one, not declined?
    API-->>B: Recommendation plus a non-blocking update notice
    L->>B: View what changed
    B->>API: GET /v1/enrolments/:id/migration (added)
    API->>RM: Build preview for the learner's current state
    RM->>CAT: Compare pinned and new version - topics, rules, assessment versions
    RM-->>API: Added, removed and changed topics, retained credit, checks needed
    API-->>B: Preview with progress before and after, and a preview hash
    B->>L: For example 12 of 18 now, 12 of 20 after, 2 topics need a short check
    alt Accept
        L->>B: Switch to the new version
        B->>API: POST /v1/enrolments/:id/migrate (target, preview hash, Idempotency-Key)
        API->>RM: Migrate
        RM->>DB: Check preview hash is current and no session is open
        RM->>DB: Pin new version, carry retained credit, keep old pin in history
        API-->>B: 200 new progress, challenge-out offers for changed topics
    else Decline
        L->>B: Stay on my current version
        B->>API: PATCH /v1/enrolments/:id (decline offer for this version)
        API->>RM: Record decline, stay pinned
        API-->>B: 200, offer stays available in roadmap settings
    end
```

Rules that stop progress shrinking without notice (**Proposal**, from R06 and
§8A): never migrate automatically. Always show the topic count and percentage
before and after. Carry credit only where the objective and assessment version
are compatible, and list the others as "needs a short check" with a
challenge-out. A roadmap milestone already earned stays. `skill_evidence` is
never changed by a migration.

| What goes wrong | Behaviour |
| --- | --- |
| Preview is stale at accept (another publish, or progress changed) | `409` with a fresh preview. Nothing changed. |
| Session open during migration | The learner is asked to finish it or set it aside first, so content never changes mid-session. **Proposal** |
| New version adds required topics | The denominator grows. This is shown before accepting and never applied silently (R06). |
| A completed topic was removed | Kept in history as "covered in the previous version", outside the new denominator. Visible in the preview. |
| Old version later retired | Enrolled learners can still continue and complete it. Retired is not deleted. **Proposal** |
| Roadmap already completed on the old version | The milestone stays. The new version is offered as "what's new" maintenance practice. |
| Serious error found in the old version | An in-app notice on the affected topic. Migration stays the learner's choice. **Proposal** |
| Accept retried | Replayed through the idempotency key. |
| Reviews from the old version | Continue by default. **Proposal** |

**Idempotency and concurrency:** the idempotency key plus the preview hash, both
checked inside the migration transaction, prevent applying a migration twice or
applying an out-of-date one.

**Analytics events:** `migration_offered` (added, client, first time the notice
is shown), `migration_accepted` (added), `migration_declined` (added).

**PRD coverage:** R06, R01 (pinned version), §8A (reuse evidence only when
compatible, otherwise challenge-out; milestone never revoked).

---

## Flow 13 — Lab: starter kit, local checks, learner-submitted evidence

**Trigger:** the learner opens a lab from Today or Roadmap, usually on a desktop.
**Preconditions:** the lab is published with a versioned starter kit, a
checksum, a setup check, local checks and a rubric (F07). The server never runs
learner code (PRD §11).

```mermaid
sequenceDiagram
    participant L as Learner
    participant M as Learner machine
    participant B as Browser
    participant API
    participant CAT as catalogue
    participant LRN as learning
    participant ASM as assessment
    L->>B: Open lab from Today or Roadmap
    B->>API: GET /v1/labs/:id (added)
    API->>CAT: Lab at the pinned version
    API-->>B: Prerequisites, setup check, tasks, rubric, kit link and checksum
    B->>API: POST /v1/sessions (mode build, lab)
    API-->>B: Build session, resumable across days
    L->>M: Download kit, verify checksum, run setup check
    alt Setup check fails
        M-->>L: Failing items with fixes
        B->>L: Troubleshooting, or no-setup scenario labelled as different evidence
    else Setup check passes
        M-->>L: Setup OK with kit version
        loop Each task and checkpoint
            L->>M: Change code, run experiment on synthetic data
            L->>B: Record decision or result notes
            B->>API: PUT /v1/sessions/:id/draft (base_revision)
        end
        L->>M: Run local checks
        M-->>L: Structured check report, no source code
        L->>B: Paste report, decision record and rubric self-check
        B->>API: POST /v1/labs/:id/artifacts (Idempotency-Key)
        API->>API: Validate format and size only, never execute
        API->>LRN: Store artifacts row linked to session and kit version
        LRN->>ASM: Write skill_evidence with basis learner_submitted
        API-->>B: 201 evidence recorded, with its limitations
        B->>API: POST /v1/sessions/:id/complete (Idempotency-Key)
    end
```

| What goes wrong | Behaviour |
| --- | --- |
| Setup check fails | Troubleshooting, plus a no-setup scenario clearly labelled as different evidence (PRD §16). |
| Checksum mismatch | Download again. Do not continue. |
| Kit version differs from the pinned lab version | Evidence is accepted with a "kit version differs" limitation. **Proposal** |
| Learner pastes employer code or secrets | The UI warns before submit. Size limit. No source upload in the MVP. Text is rendered safely (PRD §11, §12). |
| Report edited by hand | Accepted as learner-submitted evidence, labelled as such, and never shown as a verified credential (PRD §9). |
| Lab spans several days | The build session stays open and does not block short sessions (open question 2). |
| Lab opened on a phone | Marked as desktop work. Today on the phone offers a short session instead (PRD §1). |
| Duplicate submit | Replayed through the idempotency key. |
| File uploads wanted later | Object storage is added only then (PRD §11). The MVP accepts structured text and JSON only. **Proposal** |
| Human review later | Adds a new `human_reviewed` evidence row without overwriting the old one. **Proposal** |
| Labs are optional | They never affect core roadmap completion (PRD §8A). |

**Idempotency and concurrency:** the artifact submission has an idempotency key.
Lab drafts use `base_revision` as in flow 4.

**Analytics events:** `session_started` (mode `build`), `lab_evidence_submitted`,
`lab_setup_checked` (added; passed or failed only, reported by the client).

**PRD coverage:** F07, §9 (local test output is learner-submitted evidence), §10
Lab screen, §11 (no server-side execution), §16 (setup fallback).

---

## Flow 14 — Account export and account deletion

**Trigger:** the learner asks for their data, or asks to delete the account.
**Preconditions:** signed in and recently re-authenticated (`09` defines how recent).

### 14a · Export

```mermaid
sequenceDiagram
    participant L as Learner
    participant B as Browser
    participant API
    participant ID as identity
    participant SJ as Scheduler
    participant DB
    participant EP as Email provider
    L->>B: Request my data
    B->>API: POST /v1/me/export
    API->>ID: Create export_requests as queued, unless one is already active
    API-->>B: 202 export id, status queued
    SJ->>ID: Export job picks the request
    par Sections for this user only
        ID->>DB: Read profile, goal, preferences, notification settings
    and
        ID->>DB: Read enrolments, topic progress, sessions, drafts, attempts
    and
        ID->>DB: Read skill evidence, review schedule, artifacts
    end
    ID->>DB: Save export file with expiry (store chosen in 01), status ready
    ID->>EP: Email - your export is ready, link only, no data
    L->>B: Open export page
    B->>API: GET /v1/me/export/:id
    API->>ID: Owner check and status
    API-->>B: 200 ready, short-lived download link
```

### 14b · Deletion request and purge

```mermaid
sequenceDiagram
    participant L as Learner
    participant B as Browser
    participant LS as Local draft store
    participant API
    participant ID as identity
    participant NTF as notifications
    participant SJ as Scheduler
    participant DB
    participant EP as Email provider
    L->>B: Delete my account
    B->>L: What is deleted and when, with an offer to export first
    L->>B: Confirm with re-authentication
    B->>API: DELETE /v1/me
    API->>ID: Create deletion_requests, purge due after the undo window
    par Immediate effects
        ID->>DB: Revoke auth_sessions, block normal sign-in
    and
        ID->>NTF: Reminders off, cancel pending deliveries
    and
        ID->>DB: Cancel active exports and delete their files
    end
    ID->>EP: Email - deletion scheduled, sign in to cancel before the date
    API-->>B: 202 scheduled, with purge date
    B->>LS: Clear local drafts and cached data for this user
    SJ->>ID: Daily purge job finds due requests
    loop Each module purge step, recorded so a rerun resumes
        ID->>DB: Delete this user's rows for one module
    end
    ID->>DB: Unlink analytics pseudonym, keep tombstone with hashed id and date
    ID->>EP: Email - deletion completed
    ID->>DB: Delete email address and users row, mark request done
```

| What goes wrong | Behaviour |
| --- | --- |
| Export requested while one is active | Returns the active request (`202`, same ID). |
| Export link used after expiry | `410`. Request a new one. The file is deleted at expiry (proposed: 7 days). **Proposal** |
| Someone else's export ID | `404` (PRD §12). |
| Export contents | The learner's own records, including their answer text and notes, as machine-readable JSON with a short readme. No other user's data and no internal secrets. **Proposal** |
| "Export ready" email fails | The export is still listed in settings (PRD §12). |
| Learner changes their mind in the undo window | Signing in shows only "Cancel deletion" (`POST /v1/me/deletion/cancel`, added). Reminders stay off until consent is given again. **Proposal** (open question 15) |
| Purge fails partway | Step progress is recorded, so a rerun is safe. An alert fires if a request is still open on day 25. The deadline is 30 days (PRD §12). |
| Backups | Deleted data stays until the documented backup expiry (`09`). After any restore, the tombstones are applied again before reopening. **Proposal** |
| Analytics | Events become unlinkable once the pseudonym mapping is deleted. Aggregates remain. **Proposal**, confirmed in `09`. |
| Another device still signed in | The next request returns `401` with an "account deleted" reason, and that Browser clears its local data. |
| Same email signs up again | A new, empty account. Nothing is restored. |

**Idempotency and concurrency:** a repeated `DELETE /v1/me` returns the existing
request. Each purge step is an idempotent delete scoped to the user. The
`users` row goes last so foreign keys never dangle. Exports are limited to one
active request per user.

**Analytics events:** `export_requested` (added, optional). Nothing is emitted
for a user after the deletion request. Earlier events are unlinked.

**PRD coverage:** F09 (export, deletion), §12 (removal within 30 days, backup
expiry, owner-only access to exports).

---

## Analytics events by flow

| Event | Source | Flows |
| --- | --- | --- |
| `onboarding_completed` | PRD §13 | 2 |
| `recommendation_seen` | PRD §13 | 3, 9 |
| `session_started` | PRD §13 | 4, 8, 9, 13 |
| `session_resumed` | PRD §13 | 4, 9 |
| `first_answer_submitted` | PRD §13 | 1, 5 |
| `hint_used` | PRD §13 | 6 |
| `answer_revealed` | PRD §13 | 6 |
| `attempt_evaluated` | PRD §13 | 1, 5, 8 |
| `session_completed` | PRD §13 | 7, 8, 9 |
| `review_completed` | PRD §13 | 5, 9 |
| `lab_evidence_submitted` | PRD §13 | 13 |
| `reminder_paused` | PRD §13 | 10 |
| `account_created`, `guest_progress_claimed` | added | 1 |
| `enrolment_created` | added | 2 |
| `topic_completed`, `roadmap_completed`, `topic_deferred` | added | 7, 8 |
| `reminder_sent`, `reminder_suppressed`, `reminder_disabled` | added | 10 |
| `migration_offered`, `migration_accepted`, `migration_declined` | added | 12 |
| `lab_setup_checked`, `export_requested` | added | 13, 14 |

The final event catalogue and payloads belong to `10-measurement-and-validation.md`.

## Endpoints used in this doc

All the canonical operations from `00-conventions.md` are used. Additions, for
`02-system-architecture.md` to accept or reject:

| Endpoint | Flow | Why |
| --- | --- | --- |
| `GET /v1/guest/sample` (no login) | 1 | Serve the sample before sign-up. |
| `POST /v1/guest/attempts` (no login, stateless, rate-limited) | 1 | Rubric feedback for guests without storing learner records. |
| `POST /v1/auth/signup` (and login) | 1 | Account creation. Final shape owned by `02` and `09`. |
| `GET /v1/me/diagnostic` | 2 | Fetch the diagnostic items for the pinned version. |
| `GET /v1/enrolments/{id}/migration` | 12 | Migration preview with before and after progress. |
| `GET /v1/labs/{id}` | 13 | Lab definition, kit link and checksum. |
| `POST /v1/me/deletion/cancel` | 14 | Cancel during the undo window. |
| `POST /v1/admin/catalogue/bundles` (operator token) | 11 | CI publishes a validated bundle. |

Parameters rather than new endpoints: `GET /v1/today?mode=small`; a set-aside
flag on `POST /v1/sessions`; `kind=worked_example` on the hints endpoint; a
`self_check` phase on the attempts endpoint; a decline field on
`PATCH /v1/enrolments/{id}`.

## Open questions for discussion

1. **How is the guest sample evaluated?** *Recommended default:* on the server
   but stateless (`POST /v1/guest/attempts`). Answers are evaluated again at
   claim, and claimed evidence is capped at `practised`. Evaluating only in the
   client would expose the rubrics.
2. **How many open sessions can a learner have?** *Default:* one per kind,
   where `short` covers `small` and `practise`, and `build` covers labs.
   `POST /v1/sessions` returns the open session of the requested kind, so a
   multi-day lab never blocks a 10-minute session.
3. **Does deferring a prerequisite block the topics that depend on it?**
   *Default:* no. Dependents become usable with a visible warning, and
   completion still requires the deferred topic.
4. **Can a learner submit while offline?** *Default:* yes. The submission is
   queued with its idempotency key and sent on reconnect. No feedback until then.
5. **How fine-grained is draft conflict handling?** *Default:* the learner
   chooses for the whole draft in the MVP. Add per-step merging only if conflict
   metrics show it is needed.
6. **What counts as missed and as long absence?** *Default:* missed means a
   planned learning day passed with no completed session. Long absence means 7
   or more days with none (the same as the PRD §13 metric).
7. **When does a learning day end?** *Default:* local midnight in the learner's
   IANA zone.
8. **What is the reminder send window and delivery rule?** *Default:* send
   within 2 hours of the reminder time, at most once (a crash may lose a
   reminder but never duplicates one).
9. **How do reminders respond to reminder fatigue?** *Default (Hypothesis):*
   after 14 days without a completed session, the next reminder offers to pause
   or switch to weekly, and reminders never escalate.
10. **Are hints served only by the server?** *Default:* yes for signed-in
    learners, so assistance is always recorded. Only the guest sample bundles
    hints locally.
11. **Is the onboarding diagnostic the same as the §13 baseline transfer
    assessment?** *Default:* no. The diagnostic is under 5 minutes and only
    places the learner. The baseline is run separately in the pilot (`10`).
12. **What are the challenge-out rules?** *Default:* auto-scored items only, no
    hints or reveal, and one retry per learning day.
13. **Can a learner migrate with a session open?** *Default:* no. Finish it or
    set it aside first.
14. **How does content reach the catalogue?** *Default:* CI calls an
    operator-only `POST /v1/admin/catalogue/bundles` with a token held by CI.
    Publishing is idempotent by content hash. The alternative is an import
    command run at release (`01` and `06` decide).
15. **Should account deletion have an undo window?** *Default:* yes, 7 days,
    with the purge on day 8 and a hard deadline of 30 days. This saves the
    operator from restoring backups after accidental deletions.
16. **How long does an export file stay available?** *Default:* 7 days, then it
    is deleted.

## PRD traceability

| PRD item | Covered by |
| --- | --- |
| F01 Goal and baseline | Flow 2 |
| F02 Today screen | Flows 3, 9 |
| F03 Learning player and drafts | Flows 4, 5, 6 |
| F04 Evidence-based progress | Flows 1, 5, 6, 13 |
| F05 Review scheduling | Flows 3, 5, 6, 9 |
| F06 Recovery flow | Flow 9 |
| F07 Continuing project | Flow 13 |
| F08 Skill evidence view | Evidence wording in flows 5, 7, 13. Screen in `08`. |
| F09 Account and continuity | Flows 1, 4, 5, 7, 14 |
| F10 Optional email reminder | Flows 2, 10 |
| F11 Content operations | Flow 11 |
| F12 Evaluation instrumentation | Every flow, plus "Analytics events by flow" |
| F13 Bounded AI tutor (P1) | Flow 6 note only |
| F14, F15 (P1) | Not covered |
| R01 Enrolment | Flows 2, 12 |
| R02 Topic progression | Flows 3, 7 |
| R03 Prerequisites and skips | Flow 8 |
| R04 Completion milestone | Flows 7, 8 |
| R05 Pause and return | Flows 4, 9 |
| R06 Version stability | Flow 12 |
| R07, R08 | Not covered (later) |
| §5 Onboarding, guest first value | Flows 1, 2 |
| §6 Motivation and return behaviour | Flows 3, 6, 8, 9, 10 |
| §8 Content review gates | Flow 11 |
| §8A Roadmaps and completion rules | Flows 7, 8, 11, 12 |
| §9 Adaptation and assessment | Flows 3, 5, 6, 8, 13 |
| §10 Screens (Today, Lab, Return) | Flows 3, 9, 13 |
| §11 Technical boundaries | Cross-cutting rules, flows 5, 13 |
| §12 Quality, privacy, operations | Flows 4, 5, 10, 11, 14 |
| §13 Events | "Analytics events by flow" |
