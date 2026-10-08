# DevStep — Developer Upskilling Without the Overwhelm

Product requirements document · v0.1 · 5 October 2026

Working name only; brand/domain availability has not been checked.

## 1. Product decision

Build a guided practice companion for working developers who want to improve but struggle to start and sustain learning. It turns one career-relevant goal into a small next action, connects those actions to a continuing project, and brings concepts back until the learner can apply them independently.

**Promise:** “Know what to practise next, start with the time you have, and see evidence that you are improving.”

The initial audience is employed full-stack/backend developers who can build CRUD applications but lack confidence in system design, performance, and reliability. Launch with one carefully authored path: **From CRUD to Reliable Systems**. Use a responsive web app first, with a phone-friendly practice experience and desktop project work.

Success means more demonstrated capability and sustainable return visits. Time in the app, article consumption, and daily streaks are insufficient measures.

Do not market fear of becoming obsolete. A developer does not need every new framework or microservices on every project. The product should help users select relevant skills and reason about trade-offs.

## 2. Research findings and limits

This is desk research using learning research, official product pages, and primary technical sources. Product pages were checked on 5 October 2026. No customer interviews, paid-product walkthroughs, market-size study, or willingness-to-pay validation have been conducted. Proposed features, time limits, targets, and commercial assumptions below are hypotheses unless explicitly identified as source findings.

| Finding | Evidence | Product implication — proposed, not experimentally validated here |
| --- | --- | --- |
| Avoidance can involve managing unpleasant feelings associated with a task. | Sirois and Pychyl discuss short-term mood regulation and task aversiveness [1]. | Make the first action concrete; offer a smaller task and a neutral restart after absence. |
| Testing oneself and distributing practice over time have strong support in the reviewed learning literature. | Dunlosky et al. review ten techniques and rate practice testing and distributed practice highly [2]. | Ask learners to retrieve and apply knowledge, then revisit it after a delay. |
| Autonomy, competence, and relatedness are central to self-determination theory. | Ryan and Deci; official theory overview [3]. | Offer meaningful choice, visible skill evidence, and supportive feedback. |
| Roadmaps, short lessons, projects, and AI tutoring already exist. | Official roadmap.sh, Boot.dev, and Educative pages [4–6]. | “AI + bite-sized lessons” is not sufficient differentiation. |
| Microservices introduce operational and boundary-design costs. | Fowler's practitioner argument for starting with a monolith [8]. | Teach architecture selection rather than rewarding complexity. |

These sources do not establish that a three-minute session cures procrastination, that a mascot improves learning, or that this app improves employment outcomes. Research on general learning does not automatically establish transfer to professional engineering. Test delayed retention and unfamiliar engineering scenarios separately.

## 3. Competitive landscape

This is a positioning comparison, not an exhaustive feature audit. An opportunity below does not imply a competitor lacks the corresponding feature.

| Product / alternative | Publicly described offering or role | Implication for DevStep |
| --- | --- | --- |
| roadmap.sh [4] | Developer roadmaps, projects, short lesson packs, AI tutor. | Compete on the quality of the next action and resumption experience, not another map. |
| Boot.dev [5] | Interactive backend/DevOps learning, game-like curriculum, coding and projects. | A strong overlap; focus initially on already-working developers and engineering decisions. |
| Educative [6] | Interactive technical courses, system design, hands-on learning, AI assistance. | A large course catalogue is an expensive and weak initial competition strategy. |
| Habitica [7] | Habits, dailies, and to-do task tracking. | Task completion alone cannot establish engineering understanding. |
| Documentation plus a calendar or general AI chat | A practical substitute to test in interviews and the pilot. | The product must save planning effort and improve follow-through enough to justify another tool. |

**Proposed wedge:** a constrained, adaptive practice routine for employed developers, combining one continuing engineering scenario, limited daily choice, easy return after absence, and visible evidence of retained skills.

Potential defensibility comes from the quality of authored scenarios, reviewer rubrics, and validated learning sequences. Generic AI explanations and points are readily reproducible. Demand for this wedge remains unproven.

## 4. User, problem, and jobs to be done

Primary persona: a developer with roughly 1–5 years of experience, comfortable shipping features in one stack, with irregular learning time and unfinished courses. Experience range is a recruitment hypothesis, not an eligibility restriction.

Core job: “After work, when I know I should improve but feel tired or unsure where to begin, give me one manageable task that builds toward an ability I care about.”

Supporting jobs:

- Translate a broad intention such as “learn scaling” into an achievable next step.
- Connect unfamiliar concepts to applications I already understand.
- Resume without rebuilding a plan or repaying missed work.
- Tell whether I can use a concept, rather than merely recognise its name.
- Decide whether an emerging technology deserves attention for my goals.

Initial exclusions: complete beginners, interview-cramming users needing a comprehensive syllabus, enterprise compliance training, and users seeking treatment for a health condition. The product makes no clinical claim.

## 5. Learning experience

### Onboarding

Ask for current stack, one desired outcome, available days, preferred session length, and optional reminder time. Offer a short, skippable scenario diagnostic; mark skipped areas as unknown. Do not display a giant skill tree or ask users to rate dozens of technologies.

Default pilot schedule: three 10-minute sessions plus one optional 30–45-minute lab per week. Users may change it. The schedule is a testable starting point, not a scientifically optimal dose.

First value should arrive before account creation: complete one sample scenario, then create an account to preserve progress. Clearly explain whether guest work is saved only on that device.

### Session formats

| Mode | Intended use | Activity and completion |
| --- | --- | --- |
| Start small: about 3 minutes | Low energy, little time, returning after a gap. | One recall or decision task, feedback, explicit stopping point. Counts as practice, not proof of mastery. |
| Practise: about 10 minutes | Default session. | Recall, a short explanation, a scenario decision, feedback, one changed-condition question. |
| Build: about 30–45 minutes | Optional deeper work on desktop. | Modify a provided local project, inspect results, and record a decision or test result. |

Time labels are estimates. Never impose a countdown for assessment. Short sessions lower the entry cost; sustained implementation work remains necessary for deeper skill.

### Example: a slow work-order dashboard

1. Show a trace summary and a supplied SQL query for an application the learner understands.
2. Ask what they would investigate before adding servers.
3. Explain the relevant query-plan concept with a small worked example.
4. Have the learner interpret a prepared plan and choose an experiment.
5. Explain why alternatives may or may not help under the stated assumptions.
6. In the desktop lab, run the supplied query against seeded synthetic data, inspect the plan, and compare a change. Use official PostgreSQL guidance for the lesson [9].
7. Later, ask a different query-plan question without showing the original answer.

The reward should identify evidence: “You used a query plan to choose an investigation.” Avoid “You mastered database scaling” after one exercise.

## 6. Motivation and return behaviour

| Barrier / situation | Required response |
| --- | --- |
| “This feels too big.” | Show one objective, expected time, and a concrete first action. |
| “I am tired.” | Offer the three-minute mode or a planned rest day. |
| “I do not understand.” | Provide a worked example, a prerequisite refresher, and graduated hints. |
| “I already know this.” | Offer a challenge-based skip; self-report alone leaves proficiency unverified. |
| Missed planned session | Preserve progress, offer resume or reschedule, do not double the next workload. |
| Long absence | Summarise prior work and start with a brief retrieval check. |
| Many pending reviews | Cap the visible review queue and spread the remaining work across future sessions. |

Use a flexible weekly practice target. Celebrate starting, correcting a misconception, applying a skill, and returning after a gap. Keep effort rewards separate from skill assessment.

An optional future companion can build a workshop as the learner progresses. It must not become sick, lose possessions, or guilt the user after inactivity. A simple progress illustration is sufficient for the MVP; expensive animation is not a launch dependency.

Reminders are opt-in, configurable, and limited to at most one per scheduled learning day. Support snooze, pause, time zones, and quiet hours. Do not create urgency with job-loss predictions or comparisons with other users.

## 7. MVP scope and acceptance criteria

P0 is required for the pilot. P1 follows evidence that the core loop works.

| ID | Priority | Requirement | Acceptance criteria |
| --- | --- | --- | --- |
| F01 | P0 | Goal and baseline | Store one goal, stack context, availability, and optional diagnostic; skipped skills remain unknown. |
| F02 | P0 | Today screen | One recommended action with time and rationale; allow smaller mode or resume. No mandatory browsing. |
| F03 | P0 | Authored learning player | Support scenario, explanation, response, hints, feedback, and completion. Save drafts between steps. |
| F04 | P0 | Evidence-based progress | Reading alone cannot raise skill level. Record task version, outcome, and assistance used. |
| F05 | P0 | Review scheduling | Revisit attempted skills with alternate prompts; respect a per-session cap and preserve deferred items. |
| F06 | P0 | Recovery flow | After absence, offer a small restart; keep prior work and prevent automatic catch-up overload. |
| F07 | P0 | Continuing project | Six bounded labs with starter files, synthetic data, instructions, rubrics, and local checks. No production access needed. |
| F08 | P0 | Skill evidence view | Separate introduced, practised, demonstrated, and retained evidence; expose dates and limitations. |
| F09 | P0 | Account and continuity | Secure login, cross-device saved progress, idempotent completion, account deletion, and progress export. |
| F10 | P0 | Optional email reminder | Consent, schedule, time-zone handling, pause, and unsubscribe; completed sessions suppress redundant sends. |
| F11 | P0 | Content operations | Draft/review/publish/retire workflow, source URLs, version, reviewer, and last-reviewed date. |
| F12 | P0 | Evaluation instrumentation | Capture the learning funnel and delayed assessment outcomes without putting free-text answers into analytics. |
| F13 | P1 | Bounded AI tutor | Hints tied to reviewed lesson material; visible sources and authored fallback. Never sole authority for mastery. |
| F14 | P1 | Technology relevance briefing | At most one curated item a week: why relevant, prerequisite, action, source, and publication date. |
| F15 | P1 | Optional companion | Cosmetic progress tied to effort; no punishment or impact on skill ratings. |

Out of MVP: native apps, social feed, public leaderboards, live tutoring marketplace, universal course generation, repo scanning, enterprise dashboards, full browser IDE, arbitrary server-side code execution, and automated job-market ranking. No need to build every programming-language path before validating one.

## 8. Curriculum and content inventory

One six-module path, designed for approximately six weeks at the default pace but fully resumable. Content budget: 18 core short missions, at least two alternate retrieval prompts per mission, six guided labs, and distinct baseline/final transfer assessments. Adaptive review can extend completion time.

Use a fictional work-order application throughout. Concepts are portable; the first implementation examples use Laravel/PHP and SQL, matching the creator's practical strengths. Confirm audience fit before commissioning additional stack versions.

| Module | Three short mission themes | Lab / evidence |
| --- | --- | --- |
| 1. Understand a system | Request path; requirements and constraints; component boundaries. | Annotated architecture and a short decision record. |
| 2. Investigate performance | Latency evidence; query plans; indexes and pagination. | Reproducible before/after experiment with limitations. |
| 3. Introduce caching | Suitable cached data; invalidation; stale results and access boundaries. | Cache one safe read and describe failure behaviour. |
| 4. Background work | Queue use cases; retries; duplicate processing and idempotency. | A retry-safe synthetic report job with local checks. |
| 5. Reliability and growth | Stateless requests; concurrency and load evidence; logs and metrics. | Diagnose a supplied failure and propose a measured change. |
| 6. Choose architecture | Modular monolith; service boundaries; extraction costs and trade-offs. | Defend keeping the monolith or extracting one service under explicit constraints. |

Future tracks, after validation: API security, deployment/observability, and reliable AI feature integration. Emerging technology content must connect to a task and a user goal. Do not automatically turn every release into required homework.

Every mission needs a measurable objective, prerequisites, time estimate, worked example, misconception notes, graded prompts, hints, feedback, source references, stack/version scope, and accessibility review. A competent technical reviewer must try the exercises before publication. Review volatile content quarterly and on known breaking changes; this cadence is an operational proposal.

## 8A. Developer roadmaps — core user requirement

Users must be able to select a developer roadmap, cover its required topics, and reach an explicit completion milestone. The roadmap provides long-term direction; Today converts it into a manageable next action.

### MVP roadmap experience

- Launch with one complete authored roadmap: From CRUD to Reliable Systems. Support additional roadmap definitions without rebuilding the learning player.
- Show modules, topics, prerequisites, objectives, estimated effort, and completion conditions in an expandable ordered list. A visual map is a later enhancement; a graphical editor is not required.
- Support one active roadmap at a time. As the catalogue grows, allow preview and switching without losing progress; ask whether reviews from the previous roadmap should continue.
- Allow users to adjust weekly availability, pause, resume, and preview later topics. Explain prerequisites and offer challenge-based skips for experienced learners.
- Distinguish required topics from optional enrichment. Every topic expands into manageable missions and its evidence requirements.
- Show each daily task's roadmap, module, topic, and practical purpose.
- On completion, show covered skills, evidence, and remaining review opportunities. Offer maintenance practice or another roadmap without automatic enrolment.

### Completion rules

Topic workflow states: not-started, in-progress, completed, deferred. These are separate from skill evidence states: introduced, practised, demonstrated, retained. A completion percentage is not a mastery percentage.

A topic is complete after its specified activities and topic check are satisfied, or after passing an alternate challenge-out assessment. Manually skipping marks it deferred and does not earn completion credit. Open-ended self-assessed evidence stays explicitly labelled.

Progress equals completed required topics divided by all required topics in the enrolled roadmap version. Show the topic count beside the percentage because effort varies. Optional topics do not affect the denominator. Complete the roadmap when every required topic satisfies its conditions; delayed review continues separately and never revokes that historical milestone.

For the pilot, the 18 core mission topics require an attempt, feedback review, and an alternate topic check. Six deeper labs remain optional and separately recorded. Core roadmap completion therefore signals coverage and checked understanding, not verified implementation proficiency. Explain this distinction before enrolment and in the completion summary.

| ID | Priority | Requirement | Acceptance criteria |
| --- | --- | --- | --- |
| R01 | P0 | Enrolment | Pin a published roadmap version; show outcome, required topics, and estimated effort. |
| R02 | P0 | Topic progression | Update completion exactly once and recommend the next prerequisite-ready task. |
| R03 | P0 | Prerequisites and skips | Challenge-out can fulfil a topic; manual defer cannot count as completion. |
| R04 | P0 | Completion milestone | Award only after all required topics meet their rules; distinguish optional labs and self-assessed evidence. |
| R05 | P0 | Pause and return | Preserve topic states and drafts; reschedule future work without missed-lesson debt. |
| R06 | P0 | Version stability | New content cannot silently reduce progress; offer a migration showing changes and retained credit. |
| R07 | P1 | Additional roadmaps | Publish backend fundamentals, API security, deployment, or AI integration after validating demand and reviewing content. |
| R08 | Later | Custom roadmap | Assemble reviewed modules with prerequisite warnings; defer arbitrary AI-generated curricula. |

Additional records: roadmaps, roadmap_versions, roadmap_topics, topic_dependencies, roadmap_enrolments, topic_progress, topic_completion_rules. Reuse evidence across roadmaps only when objectives and assessment versions are compatible; otherwise offer challenge-out. Reject prerequisite cycles before publication.

Measure enrolment-to-first-topic activation, topic completion, module drop-off, roadmap completion by cohort and elapsed time, and return for maintenance practice. Interpret these alongside retained understanding.

## 9. Adaptation and assessment rules

Start with explainable rules, not reinforcement learning or an opaque recommendation model.

Selection order: resume an unfinished activity; select a bounded amount of due retrieval practice; then select the next prerequisite-ready mission fitting the chosen duration. If the user asks for a smaller task, use a curated equivalent rather than truncating a lesson mid-explanation.

Initial review intervals: roughly 1, 3, 7, and 21 days after successful unassisted retrieval. These are configurable heuristics. Incorrect or heavily assisted attempts trigger a worked example and an earlier alternate review. Correct unassisted responses extend the interval. Do not mistake high confidence for competence.

Skill evidence states:

- **Introduced:** encountered the concept.
- **Practised:** submitted an attempt and engaged with feedback.
- **Demonstrated:** met a rubric on a different scenario without revealing the answer.
- **Retained:** met a delayed alternate assessment at least seven days later.

Use structured questions and authored rubrics for automatic feedback. For open-ended architecture responses, show an exemplar and self-check; label that evidence as self-assessed unless a human reviewer evaluates it. Local test output is learner-submitted evidence, not a verified credential. AI feedback, if added, is advisory.

A revealed solution never counts as independent demonstration. Allow learning to continue; schedule a fresh attempt. Cap visible review work at two items in a standard session and one in small mode. No red overdue counter.

## 10. Screens and interaction requirements

Primary navigation: **Today, Roadmap, Evidence**. Settings and reminder preferences are secondary.

- Today: one action, estimated time, why it matters, resume/smaller-mode controls, weekly progress.
- Player: a single learning step at a time, accessible code samples, optional hints, draft autosave, clear stopping points.
- Path: six modules with prerequisites and adjustable pacing; avoid exposing every future topic by default.
- Lab: prerequisites, setup check, ordered tasks, checkpoints, rubric, save-and-return.
- Evidence: what the user can demonstrate, retained checks, artifacts, and next practice needs.
- Return screen: “Welcome back. Continue your last task or try a three-minute refresher.”

Support keyboard and screen-reader use, readable mobile code blocks, reduced motion, non-colour status cues, light/dark themes, and no timed answers. These are product acceptance requirements, not a claim of certified accessibility compliance.

## 11. Technical MVP boundaries

Proposed stack: Laravel application/API, React with TypeScript for the responsive interface, PostgreSQL for durable state, and a background worker for scheduled email. Begin as a modular monolith. Use object storage only if artifact uploads are introduced. Pin supported dependency versions during implementation; this PRD does not prescribe unverified current versions or hosting prices.

Core modules: identity, catalogue/content versions, learning sessions, assessment, scheduling, notifications, analytics. A database-backed job queue is adequate as a pilot design choice; introduce Redis only when measurements or required features justify it.

Main records: users, learning_preferences, goals, skills, skill_prerequisites, content_versions, missions, assessment_items, sessions, attempts, skill_evidence, review_schedule, artifacts, notification_preferences, notification_deliveries. All user records must be scoped to their owner. Attempts reference the exact published content version.

Representative API operations: get today's recommendation; start/resume session; save draft; submit attempt; complete session; list skill evidence; change schedule; export/delete account. Completion and email dispatch require idempotency keys. Store timestamps in UTC and evaluate learning schedules in the user's configured time zone.

No arbitrary uploaded-code execution. Labs run locally using supplied sample code and synthetic data. Repository integration and protected execution environments are separate future projects.

AI is optional to the core loop. A later tutor receives only relevant reviewed content and the current learner question, uses bounded context and usage quotas, and fails back to authored hints. Do not ingest employer repositories or secrets. Keep model-provider configuration server-side; log latency/cost and redact sensitive text.

## 12. Quality, privacy, and operational requirements

- Authorization tests must prevent access to another learner's attempts, drafts, and exports.
- Use TLS, secure session handling, rate limiting, input validation, and safe rendering of user text.
- Persist a draft before step transitions; retain a recoverable local draft during a network interruption. Show unsynced status and prevent silent overwrites across devices.
- Keep analytics pseudonymous; exclude answer text, code, and email addresses from event payloads.
- Provide account export and deletion. Proposed policy: remove active personal records within 30 days of a deletion request and expire backups within a documented period established before launch.
- Do not use learning data to train a model without explicit, separate consent. Disclose any third-party AI processing before enabling it.
- Proposed performance budgets: p95 Today API response under 500 ms at pilot load; cached-content first usable screen within 2.5 seconds on a representative mobile connection. Define and record the test conditions.
- Email failure, AI outage, or analytics outage must not block learning. Back up durable progress and test restoration before the pilot.

Important edge cases: skipped diagnostics, all prerequisites unmet, repeated incorrect answers, exhausted content, obsolete lesson versions, duplicate submissions, abandoned sessions, long absences, daylight-saving changes, and concurrent devices. Retired content must not erase historical evidence; recommend a refresh when an objective materially changes.

## 13. Measurement and validation

North-star candidate: **weekly learners demonstrating or retaining at least one skill on an alternate unassisted task**. Report practice-only users separately so early learners remain visible.

Pilot targets below are decision thresholds chosen for this project, not industry benchmarks or statistically validated forecasts.

| Metric | Definition | Initial target |
| --- | --- | --- |
| Activation | Invited participants who complete one scenario and receive feedback within 24 hours. | At least 60%. |
| Start friction | Median time from Today being ready to first meaningful answer, among sessions reaching an answer; report abandonment separately. | Under two minutes. |
| Week-4 retention | Activated learners completing a meaningful practice task during days 22–28. | At least 35%. |
| Return after absence | Learners completing a task within seven days after returning following seven inactive days. | At least 40%; report denominator. |
| Transfer learning | Change between baseline and unseen final scenarios scored with the same rubric. | Directionally positive; target median gain of 15 percentage points. |
| Delayed retention | Unassisted success on alternate checks at least seven days after demonstration. | At least 65% across completed checks; report missing checks. |
| Burden | Weekly optional response to whether the plan felt manageable. | At least 70% agree; report response rate. |

Events: onboarding_completed, recommendation_seen, session_started, first_answer_submitted, hint_used, answer_revealed, attempt_evaluated, session_completed, session_resumed, review_completed, lab_evidence_submitted, reminder_paused. Each carries only necessary IDs, content version, mode, and timestamps.

Validation sequence:

1. Interview 8–12 working developers about the last actual course they abandoned, the point they stopped, current alternatives, and available learning time. Avoid asking only whether the idea sounds good.
2. Run a two-week concierge trial with 10–15 people using a manually curated sequence. Test whether they return without extensive personal encouragement.
3. If promising, run a six-week product pilot with 30–50 participants. Collect baseline, weekly practice, final transfer tasks, and a delayed check.
4. Compare with a structured static checklist where feasible, using the same content and planned workload. Small samples are directional; do not claim causality or significance without an appropriate study.
5. Interview both completers and dropouts. Decide whether the main problem is task size, relevance, content quality, setup, or notification burden before adding features.

Guardrails: reminders disabled, repeated solution reveals, excessive small-mode-only usage, review backlog, technical errors, and “felt guilty/overwhelmed” feedback. High engagement with no transfer gain is a reason to revise the curriculum, not declare success.

## 14. Delivery plan and cost discipline

Indicative sequence for one experienced full-stack builder with part-time technical content review. These are planning ranges, not a delivery commitment.

| Phase | Approximate duration | Exit condition |
| --- | --- | --- |
| Discovery and sample content | 1–2 weeks | Interviews completed; one scenario and lab tried by target users. |
| Concierge validation | 2 weeks | Evidence of repeat use and a ranked list of friction points. |
| Functional alpha | 3–4 weeks | Auth, Today/player, saved progress, review rules, and first two modules work end to end. |
| Pilot readiness | 2–3 weeks | Six modules reviewed; reminders, recovery, evidence, analytics, and essential operational checks pass. |
| Measured pilot | 6 weeks plus delayed checks | Retention and learning data support a continue/pivot decision. |

Content production runs alongside engineering and may determine the schedule. Recruit a reviewer early; do not treat lesson generation as a free by-product of AI.

Budget categories: hosting/database/backups, email, content review, monitoring, and optional AI usage. Obtain vendor quotes before setting a monthly amount. Keep the baseline functional without model calls and avoid video production, native apps, sandbox hosting, and multiple initial curricula.

Track cost per weekly active learner and cost per demonstrated skill. AI cost model: active learners × assisted sessions × calls per session × measured average call cost. Set per-user and global spending caps before enabling the tutor.

## 15. Business and distribution hypotheses

Begin with a free invited pilot. Recruit through relevant developer communities, personal professional contacts, and a publicly usable sample scenario, subject to community rules. Measure completion and return, not just email signups.

After demonstrated learning and retention, test a paid complete path versus a subscription. A one-time path purchase may fit a finite curriculum; subscription value requires meaningful ongoing practice and new reviewed content. No pricing or willingness-to-pay claim has been validated.

Potential later offering: personal progress review and additional specialist paths. Team plans come only after individual demand; avoid turning personal learning into an employer surveillance score.

## 16. Main risks and decisions

| Risk | Response |
| --- | --- |
| Another app becomes an additional obligation. | One action, adjustable schedule, finite path, and easy pause. |
| Small lessons create shallow familiarity. | Require alternate scenarios, delayed checks, and optional deeper labs; separate exposure from demonstration. |
| Learners cannot set up labs. | Supply a setup check, tested starter, and a no-setup scenario fallback that is clearly labelled as different evidence. |
| Incorrect or outdated content. | Review gates, sources, versioned content, error reporting, retirement procedure. |
| Points or AI answers replace learning. | Separate effort from skill evidence; solution reveals cannot demonstrate mastery. |
| Differentiation proves too weak. | Test against a checklist/general AI workflow before expanding the catalogue. |
| Learners need rest or reduced workload rather than more reminders. | Support pause, lower frequency, and no punitive messaging. |

Recommended immediate scope decision: validate one path with authored feedback, flexible scheduling, and return support. Add AI tutoring, companions, and new technology briefings only after evidence that users start, return, and learn.

Open questions for discovery: Is Laravel-first the best recruiting niche? Are users avoiding decisions, setup, difficulty, or time commitment? Do they value phone practice enough to use it between desktop labs? Which observable skill would they pay to gain? What does their current alternative fail to provide?

## 17. Sources

Research and product references checked 5 October 2026. Product descriptions reflect public pages, not independently verified outcome claims.

1. Sirois, F. & Pychyl, T. (2013), *Procrastination and the Priority of Short-Term Mood Regulation: Consequences for Future Self*. [University repository](https://eprints.whiterose.ac.uk/id/eprint/91793/).
2. Dunlosky et al. (2013), *Improving Students’ Learning With Effective Learning Techniques*. [Publication overview](https://www.psychologicalscience.org/publications/journals/pspi/learning-techniques.html), [publisher's findings summary](https://www.psychologicalscience.org/news/releases/which-study-strategies-make-the-grade.html).
3. Ryan & Deci (2000), *Self-Determination Theory and the Facilitation of Intrinsic Motivation, Social Development, and Well-Being*. [Paper](https://selfdeterminationtheory.org/SDT/documents/2000_RyanDeci_SDT.pdf), [official theory overview](https://selfdeterminationtheory.org/the-theory/).
4. [roadmap.sh](https://roadmap.sh/) — official product page.
5. [Boot.dev](https://www.boot.dev/) — official product page.
6. [Educative](https://www.educative.io/) — official product page.
7. [Habitica FAQ](https://habitica.com/static/faq) — official task-type overview.
8. Martin Fowler (2015), [Monolith First](https://martinfowler.com/bliki/MonolithFirst.html) — practitioner guidance, not a universal architectural rule.
9. PostgreSQL, [Using EXPLAIN](https://www.postgresql.org/docs/current/using-explain.html) — official technical documentation; pin the lab's version when authoring it.
