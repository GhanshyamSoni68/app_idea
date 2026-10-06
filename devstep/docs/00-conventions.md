# 00 · Conventions and shared vocabulary

Status: **Planning — for discussion.** No code has been written. Nothing in
this folder commits the project to a build; it exists to decide what to build.

Source of truth for requirements: [`prd/devstep-prd-v0.1.md`](prd/devstep-prd-v0.1.md).
Requirement IDs (F01–F15, R01–R08) refer to that PRD.

## How every planning doc is written

- **Status line** at the top: `Status: Proposal — for discussion`.
- **Purpose** in one or two sentences, then a **Summary** of at most eight bullets.
- Prefer tables and diagrams over prose. No filler, no marketing tone.
- Label claims: **PRD** (stated in the PRD, cite the ID or section),
  **Proposal** (our design choice), **Hypothesis** (needs validation),
  **Open question** (needs a decision from the founder).
- End with **Open questions for discussion** (numbered; each has a
  *recommended default* so discussion can move fast) and
  **PRD traceability** (which IDs/sections the doc covers).
- British spelling, matching the PRD (practise, behaviour, catalogue).
- No implementation code. Pseudo-code, pseudo-SQL, and illustrative YAML/JSON
  are fine when they make a rule unambiguous.
- Never invent prices, statistics, or vendor features. If a figure was not
  checked on an official page, write `unverified` next to it.

## Diagrams

Mermaid in fenced ```` ```mermaid ```` blocks (renders on GitHub). Allowed
types: `flowchart`, `sequenceDiagram`, `erDiagram`, `stateDiagram-v2`,
`gantt`. Do **not** use the experimental `C4Context` syntax — draw C4 views as
`flowchart` with `subgraph`s.

Keep syntax conservative so every diagram renders:
- Quote any node label containing punctuation or spaces with parentheses:
  `A["Today API (GET /v1/today)"]`.
- Line breaks inside labels: `<br/>` only. No other HTML.
- No `;` inside sequence-diagram messages; avoid `#`, `{}` in labels.
- `erDiagram` attribute types are single words (`uuid`, `text`, `timestamptz`,
  `int`, `bool`, `jsonb`, `date`); keys as `PK`, `FK`, `UK`.

## Actors

| Actor | Who |
| --- | --- |
| Learner | Employed developer, 1–5 years' experience (PRD §4). May be a **guest** before sign-up. |
| Author | Writes missions, labs, rubrics. Initially the founder. |
| Reviewer | Competent technical reviewer who tries every exercise before publish (PRD §8). |
| Operator | Whoever runs production. Initially the founder — design for one part-time person. |

## Logical modules (one deployable modular monolith)

PRD §11 names the core modules; `profile` and `roadmap` are split out for clarity.

| Module | Owns |
| --- | --- |
| `identity` | Accounts, login, guest → account claim, export, deletion. |
| `profile` | Learning preferences, goal, stack context, availability, baseline diagnostic. |
| `catalogue` | Published, immutable content: roadmaps, versions, modules, topics, missions, assessment items, labs, skills. Read-only at runtime. |
| `roadmap` | Enrolments, topic progress, completion-rule evaluation, version migration. |
| `learning` | Learning sessions, steps, drafts, attempts, hint and reveal usage. |
| `assessment` | Evaluating attempts against rubrics; writing skill evidence. |
| `scheduling` | Today recommendation, review queue and intervals, recovery after absence. |
| `notifications` | Reminder preferences, dispatch, suppression, unsubscribe. |
| `analytics` | Pseudonymous event capture and metric queries. |

Outside the runtime app: the **content pipeline** (author → validate → review →
publish into `catalogue`) and the **lab kits** (run on the learner's machine).

## Canonical names

Use these names in every doc. A doc may add a record or endpoint; mark it
`(added)` and keep the naming style.

**Tables** (snake_case, plural). From PRD §8A and §11: `users`,
`learning_preferences`, `goals`, `skills`, `skill_prerequisites`,
`content_versions`, `missions`, `assessment_items`, `attempts`,
`skill_evidence`, `review_schedule`, `artifacts`, `notification_preferences`,
`notification_deliveries`, `roadmaps`, `roadmap_versions`, `roadmap_topics`,
`topic_dependencies`, `roadmap_enrolments`, `topic_progress`,
`topic_completion_rules`.

Renamed or added to avoid ambiguity:
- `learning_sessions` — the PRD's `sessions` (a practice session). Login
  sessions, if stored, are `auth_sessions`.
- `roadmap_modules` — module grouping inside a roadmap version.
- `labs` — catalogue lab definitions; learner lab evidence goes in `artifacts`.
- `session_drafts` — autosaved in-progress answers.
- `idempotency_keys` — completion, attempt submission, email dispatch.
- `analytics_events` — pseudonymous events (no free text).
- `deletion_requests`, `export_requests` — account lifecycle.

**Enumerations**

| Name | Values |
| --- | --- |
| Session mode | `small` (≈3 min, "Start small"), `practise` (≈10 min), `build` (30–45 min lab) |
| Topic workflow state | `not_started`, `in_progress`, `completed`, `deferred` |
| Skill evidence level | `introduced`, `practised`, `demonstrated`, `retained` |
| Evidence basis | `auto_scored`, `self_assessed`, `learner_submitted`, `human_reviewed` |
| Assistance | `none`, `hint` (with count), `worked_example`, `solution_revealed` |
| Content status | `draft`, `in_review`, `published`, `retired` |
| Topic kind | `required`, `optional` |

**Fixed rules from the PRD** (do not contradict; propose changes as open questions):
- Review intervals ≈ 1, 3, 7, 21 days after successful unassisted retrieval (configurable).
- Review cap: 2 items in a `practise` session, 1 in `small`. No red overdue counter.
- A revealed solution never counts as demonstration. Reading alone never raises a skill level.
- `retained` needs a delayed alternate assessment ≥ 7 days after demonstration.
- Manual defer never earns completion credit; challenge-out can.
- Roadmap progress = completed required topics ÷ all required topics in the
  enrolled roadmap version; show the count beside the percentage.
- At most one reminder per scheduled learning day; opt-in only.
- Timestamps stored in UTC; schedules evaluated in the learner's IANA time zone.
- No arbitrary server-side code execution; labs run locally.
- AI is optional and never the sole authority for mastery.

**API operations** (starting vocabulary; `02-system-architecture.md` owns the
final list). All under `/v1`, JSON, authenticated unless noted.

| Operation | Method and path |
| --- | --- |
| Today recommendation | `GET /v1/today` |
| Start or resume session | `POST /v1/sessions` (returns the open session if one exists) |
| Read session | `GET /v1/sessions/{id}` |
| Save draft | `PUT /v1/sessions/{id}/draft` (carries `base_revision`) |
| Use hint / reveal solution | `POST /v1/sessions/{id}/hints`, `POST /v1/sessions/{id}/reveal` |
| Submit attempt | `POST /v1/sessions/{id}/attempts` (`Idempotency-Key` header) |
| Complete session | `POST /v1/sessions/{id}/complete` (`Idempotency-Key` header) |
| Roadmap catalogue | `GET /v1/roadmaps`, `GET /v1/roadmaps/{slug}` |
| Enrol, pause, resume, migrate | `POST /v1/enrolments`, `PATCH /v1/enrolments/{id}`, `POST /v1/enrolments/{id}/migrate` |
| Defer topic / start challenge-out | `POST /v1/topics/{id}/defer`, `POST /v1/topics/{id}/challenge` |
| Skill evidence | `GET /v1/evidence` |
| Preferences, goal, diagnostic | `GET/PUT /v1/me/preferences`, `PUT /v1/me/goal`, `POST /v1/me/diagnostic` |
| Reminder settings | `GET/PUT /v1/me/notifications`; `POST /v1/notifications/unsubscribe` (signed token, no login) |
| Lab evidence | `POST /v1/labs/{id}/artifacts` |
| Claim guest progress | `POST /v1/guest/claim` |
| Export / delete account | `POST /v1/me/export`, `GET /v1/me/export/{id}`, `DELETE /v1/me` |
| Client analytics events | `POST /v1/events` |

## Document map

| File | Topic |
| --- | --- |
| `01-tech-stack-and-hosting.md` | Stack choice (ADR), deployment diagram, cost model |
| `02-system-architecture.md` | C4 views, module boundaries, API catalogue, cross-cutting concerns |
| `03-key-flows.md` | Sequence diagrams for the critical flows |
| `04-data-model.md` | ERD, table definitions, constraints, ownership, retention |
| `05-learning-engine.md` | Recommendation, review scheduling, evidence and completion rules |
| `06-content-system.md` | Content format, authoring/review/publish workflow, versioning |
| `07-curriculum-plan.md` | The "From CRUD to Reliable Systems" path and lab kits |
| `08-ux-and-screens.md` | Navigation, user flows, wireframes, accessibility |
| `09-security-privacy-ops.md` | Threat model, privacy, backups, monitoring, runbooks |
| `10-measurement-and-validation.md` | Events, metrics, discovery interviews, concierge trial, pilot |
| `11-market-and-positioning.md` | Competitive refresh and positioning |
| `12-delivery-plan.md` | Phases, epics, milestones, risks |
| `13-decisions-and-open-questions.md` | Consolidated decision log for discussion |
