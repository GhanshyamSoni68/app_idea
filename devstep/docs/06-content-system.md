# 06 · Content system

Status: **Proposal — for discussion.** Planning only. No tooling, schema or
content exists yet.

## Purpose

This doc covers how DevStep content is authored, reviewed, versioned, published
into the read-only `catalogue` and retired (PRD F11). It assumes one part-time
founder and one part-time reviewer, and no paid content service.

## Summary

- **Proposal:** content is Markdown and YAML in a private Git repo, reviewed by
  pull request (PR) and published by CI into `catalogue`. No CMS or admin UI.
- **Proposal:** versioned units are mission directories (mission, items,
  assets), labs, transfer assessments and roadmap-version structures. Published
  versions never change, and every attempt references one (PRD §11).
- **PRD §8:** each mission carries all required metadata (§5). Minimum pool:
  the primary items, 2 held-back alternates and a topic check.
- **Proposal:** 22 CI rules block publication, including prerequisite cycles
  (§8A), item minimums, link checks, freshness, accessibility and leakage.
- **PRD §8:** a reviewer other than the author tries every exercise. The publish
  job checks this itself, so no paid Git plan is needed to enforce it.
- **Proposal:** semantic versions (patch, minor, breaking, plus `defect_fix`).
  Only breaking or structural changes create a new roadmap version (R06).
- **Proposal:** retiring a unit stops new use but never deletes it. Attempts and
  evidence keep their references (§12).
- **Hypothesis:** about 425 hours of content work (360 author, 65 reviewer;
  range 270–655). That exceeds the PRD's 8–11 pre-pilot weeks at part-time pace,
  so content is the critical path (PRD §14).

## 1. Scope and boundaries

| In this doc | Owned elsewhere |
| --- | --- |
| Content format, repository layout, required metadata | Topics, mission themes, lab kit contents: `07-curriculum-plan.md` |
| Workflow, roles, review checklist, publish pipeline | Columns, constraints and retention of catalogue tables: `04-data-model.md` |
| Validation rules; which changes need a new roadmap version | Evidence, completion, migration and scheduling rules: `05-learning-engine.md` |
| Content operations, AI drafting policy, content effort | Stack, CI host, import location: `01-tech-stack-and-hosting.md`, `02-system-architecture.md`; screens: `08-ux-and-screens.md`; schedule: `12-delivery-plan.md` |

## 2. Decision: where content lives

| Criterion | A. Files in Git, PR review, CI publish | B. Admin UI inside the app | C. Headless CMS (hosted or self-hosted) |
| --- | --- | --- | --- |
| Extra money | Git host and CI free tiers (limits unverified). Enforcing reviews on a private repo may need a paid plan (unverified); §7.3 avoids needing it. | None, but weeks of build time | Hosted: subscription or limited free tier (unverified). Self-hosted: another service to run, back up and patch. |
| Review workflow | Mature: diffs, line comments, approvals, templates | Has to be built | Vendor- and plan-dependent (unverified); weaker diffs of structured fields |
| History and audit | Full per-line history; each version carries a commit SHA | Audit tables have to be built | Vendor revision history (unverified) |
| Ease for one technical person | High: any editor, bulk edits, works offline | Comfortable, but building it delays the pilot | Medium: content model lives in a vendor UI, outside the codebase |
| Preview | Needs a small preview tool (§12) | Natural | Needs an integration |
| Custom blocking checks (cycles, leakage) | Any check can block a merge | Custom code | Webhooks; hard to block a publish |
| Non-technical authors | Weak | Strong | Strong |
| Lock-in | None (plain text) | Tied to the app | Export needed |
| Concierge trial before the app exists | The same files and preview can be used by hand | Not yet available | Possible, but adds setup |

**Recommendation (Proposal): option A for the MVP.** The author and reviewer
are both technical, the budget allows no extra service, and every PRD gate maps
onto existing Git and CI features: review, sources, versions, audit trail and
rejecting cycles before publication.

| Honest cost of option A | Mitigation |
| --- | --- |
| YAML mistakes and Git friction | JSON Schemas give editor hints and clear CI errors; the Git host's web editor covers small fixes |
| No WYSIWYG preview at first | Static preview (§12); the real player locally once it exists |
| Publishing needs an import step and credentials | Reuse the deploy pipeline (Open question 2); the import is idempotent and atomic |
| A public repo would leak answer keys | Keep content private; lab kits contain no answer keys, so they can be public (Open question 6) |
| Outgrowing files | Revisit if a non-technical author joins or experts must edit in the app; a later admin UI writes the **same schema** and runs the **same validator** |

## 3. Content hierarchy

```mermaid
flowchart TD
  R["Roadmap (stable slug)"] --> RV["Roadmap version (structure immutable)"]
  RV --> MOD["Module"]
  MOD --> T["Topic (required or optional)"]
  T --> M["Mission (versioned unit, ordered steps)"]
  M --> PI["Primary items (shown in the mission)"]
  M --> HB["Held-back items<br/>2+ alternates and a topic check"]
  PI --> HF["Hints, feedback, answer key or rubric"]
  HB --> HF
  M --> WE["Worked example, misconception notes, sources"]
  T -.->|optional topic| L["Lab (build mode, separate evidence)"]
  L --> KIT["Starter kit at a pinned tag"]
  M --> SK["Skills and skill prerequisites"]
  RV --> TA["Baseline and final transfer assessments"]
```

- **PRD §8A:** in the pilot, each of the 18 required topics has one core
  mission. The format allows several per topic.
- **Proposal:** each lab is its module's **optional topic**: outside the
  progress denominator and recorded separately (R04).
- **Proposal:** item roles are `primary` (shown in the mission), `alternate`
  and `topic_check` (both held back), `baseline`, `final_transfer` and
  `diagnostic` (F01). `05-learning-engine.md` decides which held-back item is
  used when, including challenge-out; this doc only guarantees the pool.
- **Proposal, for `02-system-architecture.md`:** the API never sends held-back
  items, answer keys or feedback to the client before submission.

## 4. Repository layout and identifiers

```text
content/                                 # private repo (Open question 1)
├── schemas/                             # one JSON Schema per file kind
├── skills/skills.yaml                   # skills and skill prerequisites
├── roadmaps/crud-to-reliable/
│   ├── roadmap.yaml                     # slug, title, outcome
│   └── versions/                        # v1.yaml, v2.yaml: structure immutable
├── missions/
│   └── m-query-plans/                   # directory name = mission ID
│       ├── mission.md                   # front matter + step bodies
│       ├── items/                       # primary-1, primary-2 (shown);
│       │                                # alt-1, alt-2, check-1 (held back)
│       └── assets/                      # images with alt text, text plans
├── labs/lab-perf-experiment/            # lab.yaml + tasks/*.md
├── assessments/                         # rubric-transfer.yaml (shared),
│                                        # ta-baseline/, ta-final/ (+ items/)
└── release-notes/2026-11.md             # learner-facing, per month
.github/                                 # or the Git host's equivalent:
                                         # pull_request_template.md (§7.3),
                                         # ISSUE_TEMPLATE/content-problem.yml
                                         # (§11.2), workflows/
```

Validator and preview tools are engineering code in the app's language
(`01-tech-stack-and-hosting.md`). Lab starter kits live in a separate,
downloadable repository (contents: `07-curriculum-plan.md`).

**Identifier rules (Proposal).** IDs are lowercase ASCII, stable across
versions, never encode order, and are never reused. Published units are retired,
never deleted, so uniqueness checks always see them.

| Unit | Pattern | Example |
| --- | --- | --- |
| Roadmap / version | slug / `v` + integer | `crud-to-reliable` / `v1` (pinned by enrolment, R01) |
| Module | `mod-<slug>` | `mod-investigate-performance` |
| Topic | `t-<slug>` | `t-query-plans`. Stable across roadmap versions so that R06 can map credit; a topic whose meaning changes gets a new ID |
| Mission / item | `m-<slug>` / `<mission>/<key>` | `m-query-plans` / `m-query-plans/alt-1` |
| Lab, skill, misconception | `lab-`, `sk-`, `mc-` + slug | `lab-perf-experiment`, `sk-read-query-plan`, `mc-scale-out-first` |
| Transfer assessment | `ta-<form>` | `ta-baseline`, `ta-final` |

## 5. Required metadata

### 5.1 Per mission

| Field | Purpose | Source |
| --- | --- | --- |
| `id`, `version`, `status`, `change_class` | Identity, versioning, workflow | F11, Proposal |
| `objective` | One observable outcome ("Given…, choose/explain/identify…") | PRD §8 |
| `prerequisites` (missions, skills), `skills` | Readiness, prerequisite explanations, where evidence attaches | PRD §8, §8A, §9 |
| `time_estimate_minutes` per supported mode | Today time label; never a countdown | PRD §5, §8 |
| `steps`, `small_variant` | One step at a time; a curated 3-minute equivalent, not a truncated lesson | PRD §9, §10 |
| Worked example, misconception notes (body, `mc-` IDs) | Support after errors; linked from wrong-answer feedback | PRD §8, §9 |
| Item references: primary, alternates, topic check | Graded prompts, at least 2 alternates | PRD §8, §8A |
| `sources[]`: `url`, `title`, `supports`, `accessed`, `verified_by` | Each source is tied to a claim. Prefer version-pinned documentation URLs where the publisher offers them | PRD §8, F11, §17 |
| `stack_scope`, `volatility`, `volatility_watch[]` | Which versions the examples are correct for; drives review cadence | PRD §8, Proposal |
| `accessibility` (reviewer, date) | Accessibility review recorded | PRD §8, §10 |
| `author`; `review` (reviewer, last-reviewed date, tried flag, minutes) | Reviewer is not the author, tried the exercise, and time is calibrated | PRD §8, F11 |
| `ai_assisted` (used flag, which parts) | Audit of AI drafting (§13) | PRD §14 |

### 5.2 Per assessment item, lab, transfer assessment and roadmap version

- **Item.** `id`, `role`, `type` (`single_choice`, `multi_select`, `ordering`,
  `numeric`, `self_check`), `evidence_basis`, `skills`, `scenario`, `prompt`,
  answer key or rubric with exemplar, feedback (correct, each wrong option, or
  after self-check), graduated `hints`, `reveal`, misconception links, time
  estimate. Held-back items add `changed_conditions_of` (leakage check). No
  time-limit field exists (PRD §5).
- **Lab.** Mission identity and review fields, plus `starter_kit` (repository,
  immutable tag, checksum, supported environments, setup check),
  `synthetic_data` (generator, seed, scale, `contains_personal_data: false`),
  tasks and checkpoints, `local_checks`, a rubric,
  `evidence.basis: learner_submitted` (PRD §9), a `no_setup_fallback` labelled
  as different evidence (PRD §16), and `review.ran_end_to_end` and
  `review.clean_machine`.
- **Transfer assessment.** `form` (`baseline` or `final`), the shared rubric
  (PRD §13), items, scoring guidance, time estimate and a comparability note.
  Its items never appear in missions or reviews.
- **Roadmap version.** `change_class`, outcome, ordered modules and topics. Each
  topic records its kind, prerequisites, the missions or lab it uses (pinned by
  **major** version) and its completion rule (rule IDs come from
  `05-learning-engine.md`). The version also lists its transfer assessments and
  a `display` block that can be edited (§10.2).

## 6. Illustrative files

> **Illustrative only.** These show what must be captured, not a final schema.
> Themes come from PRD §8; `07-curriculum-plan.md` owns the real ones.

### 6.1 Mission (`missions/m-query-plans/mission.md`)

```markdown
---
id: m-query-plans
version: 1.1.0
change_class: minor              # patch | minor | breaking | defect_fix
status: in_review                # draft | in_review | published | retired
title: Read a query plan before adding servers
objective: >-
  Given a query plan for a slow list endpoint, identify the step that
  dominates cost and choose one experiment that tests it.
skills: [sk-read-query-plan]
prerequisites: { missions: [m-latency-evidence] }
time_estimate_minutes: { practise: 10, small: 3 }
steps:                           # body H2 headings, in order
  - { key: scenario, kind: scenario }
  - { key: first-move, kind: response, item: m-query-plans/primary-1 }
  - { key: worked-example, kind: explanation }
  - { key: read-the-plan, kind: response, item: m-query-plans/primary-2 }
small_variant: [scenario-short, first-move]       # curated (PRD §9)
held_back:                       # never shown inside the mission
  alternates: [m-query-plans/alt-1, m-query-plans/alt-2]
  topic_check: [m-query-plans/check-1]
stack_scope: { portable_concept: true, postgresql: "pinned when authored" }
volatility: volatile
volatility_watch: ["PostgreSQL major release (EXPLAIN output)"]
sources:
  - url: https://www.postgresql.org/docs/current/using-explain.html
    title: "PostgreSQL documentation: Using EXPLAIN"
    supports: "Reading plan nodes; estimated versus actual rows"
    accessed: 2026-10-05
    verified_by: founder         # a human opened it (§13)
accessibility: { reviewed_by: reviewer-a, reviewed_on: 2026-10-20 }
author: founder
review: { reviewer: reviewer-a, last_reviewed: 2026-10-20, tried_exercise: true, tried_minutes: 12 }
ai_assisted: { used: true, parts: ["distractor ideas for primary-2"] }
---

## Scenario
The work-order dashboard takes about four seconds to list open orders…

## Scenario (short) · ## First move · ## Worked example · ## Read the plan
(Each heading is its own section in the real file.)

## Misconceptions
- **mc-scale-out-first**: "Add servers first." More app servers do not
  reduce the rows the database reads per query…
```

### 6.2 Assessment items: a primary and a held-back alternate with a rubric

```yaml
# items/primary-2.yaml
id: m-query-plans/primary-2
role: primary
type: single_choice
evidence_basis: auto_scored
time_estimate_minutes: 2
scenario: |
  The open-orders query filters by site and status, sorts by created
  date and returns 25 rows. The plan (assets/plan-a.txt) shows a
  sequential scan reading about 400,000 synthetic rows.
prompt: Which experiment would you run first?
options:
  - key: a
    text: Add a second application server and measure again.
    misconception: mc-scale-out-first
    feedback: The database reads far more rows than it returns; more app servers do not change that.
  - key: b
    correct: true
    text: Try an index matching the filter and sort, then compare plans.
    feedback: Yes. The cost is in rows read; before and after plans on the same seeded data test that.
  - key: c
    text: Cache the whole page for every user.
    misconception: mc-cache-hides-cause
    feedback: That hides the symptom, and a shared page cache risks showing one site's orders to another.
hints:                           # graduated; the last stops short of the answer
  - Compare rows read with rows returned.
  - Which plan node reads most of those rows?
reveal: The sequential scan dominates. A revealed answer never counts as demonstration.
---
# items/alt-1.yaml (held back)
id: m-query-plans/alt-1
role: alternate
type: self_check                 # open-ended, so evidence_basis: self_assessed
changed_conditions_of: m-query-plans/primary-2   # input to the leakage check
scenario: A report query already uses an index, yet one plan node ran 12,000 times.
prompt: In two or three sentences, say what you would check next and why.
rubric:
  - { id: names-evidence, text: "Points to a specific plan figure." }
  - { id: proposes-test, text: "Proposes one experiment that could disprove the guess." }
  - { id: states-limit, text: "States one assumption or limitation." }
exemplar: The loop count, not the index, dominates…
feedback: { after_self_check: "Ticked fewer than two? Compare with the exemplar's first sentence." }
hints: [Look at how many times each node ran, not only its cost.]
```

### 6.3 Lab definition (`labs/lab-perf-experiment/lab.yaml`)

```yaml
id: lab-perf-experiment
version: 1.0.0
status: draft
objective: >-
  Reproduce a slow query on seeded data, change one thing, and record
  before and after plans with one stated limitation.
mode: build
time_estimate_minutes: 45
prerequisites: { missions: [m-query-plans, m-indexes-pagination] }
starter_kit:
  repository: devstep-labs              # downloadable, no answer keys
  ref: lab-perf-experiment-v1.0.0       # immutable tag
  archive_sha256: "<written by CI>"
  supported_environments: ["pinned when authored"]
  setup_check: "./devstep check-setup"  # command shape decided in 07
synthetic_data: { generator: seed/generate, seed: 20261005, scale: { work_orders: 400000 }, contains_personal_data: false }
tasks: [tasks/01-reproduce.md, tasks/02-change-one-thing.md, tasks/03-record.md]
local_checks:                           # run on the learner's machine only
  - { id: plan-before-captured, describes: "A before plan exists from the seeded DB." }
  - { id: same-dataset, describes: "Both runs used the same seed and scale." }
rubric:
  - { id: reproducible, levels: [not_yet, meets], text: "Another developer could rerun it." }
  - { id: one-variable, levels: [not_yet, meets], text: "Only one thing changed between runs." }
  - { id: limitations, levels: [not_yet, meets], text: "States what the result does not show." }
evidence: { basis: learner_submitted, submit: [decision_record, check_summary] }
no_setup_fallback: { mission: m-perf-experiment-scenario }   # labelled as different evidence
review: { reviewer: reviewer-a, ran_end_to_end: true, clean_machine: true, minutes: 50 }
```

### 6.4 Roadmap version manifest (`roadmaps/crud-to-reliable/versions/v1.yaml`)

```yaml
roadmap: crud-to-reliable
version: 1
change_class: initial                 # initial | additive | structural
modules:
  - id: mod-understand-system
    topics:
      - id: t-request-path
        kind: required
        missions: [{ id: m-request-path, major: 1 }]
        completion: { rule: attempt_feedback_topic_check }   # rule IDs: 05
      - id: t-requirements-constraints
        kind: required
        missions: [{ id: m-requirements-constraints, major: 1 }]
        prerequisites: [t-request-path]
        completion: { rule: attempt_feedback_topic_check }
        challenge_out: allowed        # R03; composition in 05
      - id: t-lab-architecture-notes
        kind: optional                # never in the progress denominator
        lab: { id: lab-annotated-architecture, major: 1 }
        prerequisites: [t-request-path]
        completion: { rule: lab_evidence_submitted }
  - id: mod-investigate-performance
    topics: [ … ]
transfer_assessments: { baseline: { id: ta-baseline, major: 1 }, final: { id: ta-final, major: 1 } }
roadmap_completion: { rule: all_required_topics_complete }   # §8A
display:                              # editable in place (§10.2)
  titles: { mod-understand-system: "Understand a system" }
  estimated_effort: "About six weeks at the default pace"
```

## 7. Workflow and roles

### 7.1 States (content status enum)

```mermaid
stateDiagram-v2
    [*] --> draft : author starts a unit or new version
    draft --> in_review : PR marked ready and CI green
    in_review --> draft : changes requested or CI fails
    in_review --> published : reviewer approves and publish job succeeds
    published --> retired : operator retires with a reason
    draft --> [*] : abandoned, never published
    retired --> [*]
```

- `draft` and `in_review` exist only in the repository; `catalogue` receives
  only `published` and `retired`. Drafts may sit on main; publish ignores them.
- Editing published content starts a new `draft` version. Once that publishes,
  the old one is *superseded*: still `published` and referenced, but not served
  to new sessions (`04-data-model.md` decides on a "current" pointer). Only
  never-published drafts may be deleted.

### 7.2 Roles

| Activity | Author | Reviewer | Operator |
| --- | --- | --- | --- |
| Draft and edit, run local check and preview, open the PR | Does | Suggests | — |
| Try every new or changed exercise; run labs end to end | — | **Does (mandatory)** | — |
| Approve publication | Never their own work | Does | — |
| Publish and import; monitor | May merge after approval | — | Owns pipeline |
| Retire a unit | Proposes | Reviews (after the fact in an emergency, §11.3) | Executes |
| Problem triage, release notes, scheduled review | Fixes and updates | Reviews and re-tries | Triages, schedules, publishes notes |

The founder starts as author and operator; the reviewer must be someone else.
With no reviewer, nothing publishes, and that is intended (PRD §8, §14).

### 7.3 PR review checklist (`pull_request_template.md` content)

| # | Check | Who | Basis |
| --- | --- | --- | --- |
| 1 | Change class declared; version bumped to match (§10) | Author | Proposal |
| 2 | Local check passes; preview viewed at phone width | Author | PRD §10 |
| 3 | I opened every source myself; each supports its stated claim | Author | PRD §8, §14 |
| 4 | AI assistance declared, or "none"; learner-facing note written, or "none" | Author | §11.4, §13 |
| 5 | **I attempted every new or changed item before reading its answer or feedback. Minutes: __** | Reviewer | PRD §8 |
| 6 | Labs: from a clean checkout at the pinned tag the setup check passes; local checks pass a correct solution and fail a plausible wrong one | Reviewer | PRD F07 |
| 7 | The objective is measurable and the items test it | Reviewer | PRD §8 |
| 8 | Held-back items change the conditions and cannot be answered from primary feedback, hints or reveal | Reviewer | PRD §9 |
| 9 | Each wrong option's feedback explains why and links a misconception; hints are graduated | Reviewer | PRD §8 |
| 10 | Version-specific claims match `stack_scope` | Reviewer | PRD §8 |
| 11 | Readable at phone width: alt text, no colour-only meaning, readable code | Reviewer | PRD §10 |
| 12 | No fear framing or reward for needless complexity; synthetic data only; no secrets or employer code | Reviewer | PRD §1, §11 |
| 13 | Breaking or structural change: roadmap-version impact noted | Reviewer | PRD R06 |

**Enforcement (Proposal).** The reviewer commits the `review:` block. Before
import, the publish job checks: a listed reviewer approved the merged PR; that
reviewer is not the author and matches `review:`; checks 5–13 are ticked.
Otherwise nothing publishes. This needs no paid branch protection (unverified).
Reviewer minutes over 1.5 times the estimate flag it for recalibration.

## 8. Publish pipeline

```mermaid
flowchart TD
  A["Author edits files locally"] --> B["Local check and preview"]
  B --> C["Push branch and open PR"]
  C --> D{"CI validation V01-V22 passes?"}
  D -->|no| A
  D -->|yes| E["Preview artifact and import<br/>rehearsal on an empty CI database"]
  E --> F["Reviewer tries exercises<br/>and completes checklist"]
  F --> G{"Approved?"}
  G -->|changes requested| A
  G -->|yes| H["Merge to main"]
  H --> I["Publish job: re-validate, check review gate,<br/>build bundle with a hash per unit"]
  I --> K["Import into catalogue tables<br/>(one transaction, insert-only)"]
  K --> L{"Import and smoke check OK?"}
  L -->|no| M["Roll back, open issue, nothing published"]
  L -->|yes| N["Tag release, append draft release notes"]
```

### 8.1 What publish writes (catalogue tables owned by `04-data-model.md`)

| Source | Catalogue tables |
| --- | --- |
| `skills/skills.yaml` | `skills`, `skill_prerequisites` |
| `roadmaps/<slug>/roadmap.yaml` | `roadmaps` |
| `roadmaps/<slug>/versions/vN.yaml` | `roadmap_versions`, `roadmap_modules`, `roadmap_topics`, `topic_dependencies`, `topic_completion_rules` (structure written once) |
| `missions/<id>/mission.md` and `items/*.yaml` | `content_versions` (one row per published version), `missions`, `assessment_items` (item IDs stable) |
| `labs/<id>/` | `content_versions`, `labs` (the kit stays in the lab-kit repository) |
| `assessments/` | `content_versions`, `assessment_items` (roles `baseline` and `final_transfer`; `04-data-model.md` decides whether a grouping record is needed) |

Each published version carries (columns: `04-data-model.md`) kind, ID, version,
change class, learner-facing hash (excluding review metadata), commit SHA,
author, reviewer, last-reviewed date, tried flag, published-at, status and any
retirement reason. Audit chain: attempt → content version → commit → PR → review.

### 8.2 Publish rules (Proposal)

- **Idempotent:** same ID, version and hash means no change. **Immutable:** an
  existing ID and version with a different hash aborts the import (V17).
- **Atomic:** one transaction per bundle. **Rehearsed:** every PR imports into
  a throwaway CI database, at no hosting cost.
- **Runner:** an import command in the deploy pipeline, with no admin endpoint
  (Open question 2). **Smoke check:** read `GET /v1/roadmaps/{slug}` and one
  changed mission. Drafts never reach production.

### 8.3 Retirement without erasing evidence (PRD §12)

- A retiring PR sets `status: retired`, `retired_reason` and an optional
  `replaced_by`. The import changes only status fields and never deletes rows.
- Attempts, `session_drafts`, `skill_evidence` and `artifacts` keep their
  references; the Evidence view labels retired content (`08-ux-and-screens.md`).
- Retired units get no new sessions or reviews; queued reviews are replaced by
  `05-learning-engine.md` rules.
- A unit used by a non-retired roadmap version needs a compatible
  `replaced_by` (same objective) or a new roadmap version (V22). A retired
  roadmap version takes no new enrolments; existing ones migrate (R06).

## 9. Automated validation

`block` fails the PR and the publish job. `warn` adds a note for the reviewer to
judge. All thresholds are **hypotheses**, to be tuned after module 1.

| ID | Rule | How | Level |
| --- | --- | --- | --- |
| V01 | Schema validity | Every file validates against its JSON Schema; unknown fields are rejected | block |
| V02 | Unique, stable IDs | Unique across the repo, retired units included; pattern matches §4; directory name equals ID | block |
| V03 | References resolve | Missions, items, skills, labs, topics, misconceptions, assets and step headings all exist | block |
| V04 | Prerequisite cycles (§8A) | Topological sort of `topic_dependencies` per roadmap version and of `skill_prerequisites`; the error prints the cycle | block |
| V05 | Reachability | A required topic may not depend on an optional one; every required topic can be reached | block |
| V06 | Item minimums (§8) | Each mission has at least 1 primary, at least 2 alternates and at least 1 topic check; no step references a held-back item | block |
| V07 | Feedback on every item | Choice items: feedback for the correct answer and each wrong option. Self-check items: rubric, exemplar and post-check feedback | block |
| V08 | Hints | Primary and topic-check items have at least 1 hint; no hint contains the correct option's text | block |
| V09 | Sources present | Each mission and lab has at least 1 HTTPS source with `url`, `title`, `supports`, `accessed` and `verified_by` | block |
| V10 | Links reachable | Changed files on each PR and all files weekly; 404/410 blocks publication; timeouts are retried, then warn | block / warn |
| V11 | Freshness | At publish, `last_reviewed` is within 90 days for `volatile` units and 365 days for `stable` ones; a weekly report lists units due within 30 days | block |
| V12 | Accessibility lint | Images need alt text (not the filename); data figures need a text equivalent; colour words in instructions ("in red") are flagged; heading order, table headers and link text are checked | block / warn |
| V13 | Mobile code blocks | Lines over 60 characters or blocks over 25 lines are flagged for a phone preview check (rendering is decided in `08-ux-and-screens.md`) | warn |
| V14 | Time estimates | Present for every supported mode and every item. Bounds: `small` ≤ 4 min, `practise` ≤ 12 min, `build` 30–45 min | block / warn |
| V15 | No answer leakage | Held-back items share no correct-answer text with primary items, hints, worked example or reveal; word 5-gram overlap between items in a mission warns above 30% and blocks above 60% | block / warn |
| V16 | Required metadata | `published` units need author, a reviewer who is not the author, `last_reviewed`, `tried_exercise: true`, accessibility review, stack scope and version. Objectives without an observable verb ("understand", "know") warn | block / warn |
| V17 | Version discipline | A diff that touches objective, answer key, rubric, scoring, prerequisites or skills needs a major bump unless `defect_fix`; a published ID and version cannot change hash | block |
| V18 | Manifest discipline | A published roadmap version may change only `status` and `display`; a new version's `change_class` must match its diff (§10.2) | block |
| V19 | Lab completeness | Kit pinned by tag and checksum; synthetic data declared with no personal data; setup check, at least 1 local check, rubric and no-setup fallback present | block |
| V20 | Transfer assessments | Baseline and final are distinct and share one rubric; neither reuses items or text from missions | block |
| V21 | Hygiene | Secret-pattern scan, no real emails or personal data, images ≤ 200 KB (supports the PRD §12 load budget) | block / warn |
| V22 | Legal transitions | Only §7.1 transitions; `retired` needs a reason and follows §8.3 | block |

V15 is a heuristic. Checklist item 8 remains the real control.

## 10. Versioning semantics

### 10.1 Unit versions (missions, labs, transfer assessments)

**Proposal:** a session and its drafts pin the content version it started on
(mechanics: `05-learning-engine.md`, `04-data-model.md`). PRD §8A's
"compatible" evidence means the same unit ID and the same **major** version.

| Class | Examples | Bump | Learner mid-mission | Existing evidence | New roadmap version? |
| --- | --- | --- | --- | --- | --- |
| Patch | Typo, clearer wording with the same meaning, equivalent replacement link, better alt text, re-certification | `x.y.Z` | Open session stays on its version; the next session gets the new one | Fully valid | No |
| Minor | New hint, new held-back item, new worked example or misconception note, better feedback, new time estimate | `x.Y.0` | As for patch; new items become eligible for future reviews | Fully valid | No |
| Defect fix | Answer key, feedback or lab check was wrong *against the unchanged objective* | Patch or minor + `defect_fix: true` | Neutral notice at the next step boundary, then continue on the fixed version with the draft kept | Kept and annotated; a fresh alternate check is scheduled (Open question 7) | No |
| Breaking | Objective changes; what an item assesses changes (rubric, scoring, scenario logic); prerequisites or skills change; a new stack version changes correct answers | `X.0.0` | Open sessions finish on the old major | Stays attached to the old major; reuse needs compatibility or challenge-out (§8A) | **Yes**, if any non-retired roadmap version uses the unit |

The principle: a fix that brings an item back in line with its stated objective
is not breaking. A change to *what we intend to assess* is breaking.

### 10.2 Roadmap versions: which change triggers what

Manifests pin missions by **major** version, so patch, minor and defect-fix
updates reach enrolled learners without a new roadmap version.

| Change | Result | `change_class` | Migration (rules in `05-learning-engine.md`) |
| --- | --- | --- | --- |
| Titles, descriptions or effort text in `display` | Edited in place | — | None |
| Mission or lab patch, minor or defect fix | No new version | — | None |
| Add an optional topic, lab, or mission in an optional topic | New `vN` | `additive` | Simple offer; denominator unchanged |
| Add or remove a required topic; switch a topic between required and optional | New `vN` | `structural` | Migration summary showing changes and retained credit (R06) |
| Change `topic_dependencies` or a completion rule | New `vN` | `structural` | As above |
| Breaking change to a mission used by any topic | New `vN` | `structural` | As above |
| Change of transfer assessment form | New `vN` | `structural` | Also affects pilot comparability (`10-measurement-and-validation.md`) |

**PRD R06:** new content never silently reduces progress. Topic IDs stay stable
so that credit can be mapped, and a topic whose meaning changes gets a new ID.

## 11. Content operations

### 11.1 Volatility tagging and review cadence

| Tag | Meaning | Review cadence (Proposal, PRD §8) |
| --- | --- | --- |
| `volatile` | Depends on specific versions, CLI output, defaults, vendor features or security advice (for example query-plan output, framework queue APIs, and every lab) | Quarterly, and when a `volatility_watch` item has a known breaking change |
| `stable` | Concept-level and version-independent (for example what idempotency means) | Yearly |

| Activity | Cadence | Owner | Output |
| --- | --- | --- | --- |
| Link check and freshness report | Every PR and weekly | Operator | One rolling issue listing failures and units due within 30 days |
| Volatile review | Quarterly | Author and reviewer | Re-try and fix, then a patch bump or re-certification |
| Breaking-change watch | When a watched technology releases | Operator opens an issue; author assesses | Units found through `stack_scope` and `volatility_watch` |
| Kit dependency advisories | When the Git host alerts (availability unverified) | Operator | Treated as a breaking-change trigger for that lab |

### 11.2 "Report a problem" loop

```mermaid
flowchart LR
  A["Learner taps Report a problem"] --> B["Stored with content version and step"]
  B --> C["Operator triage"]
  C --> D["Issue from content-problem template<br/>(personal data removed)"]
  D --> E{"Severity S1?"}
  E -->|yes| F["Contain, then hotfix PR"]
  E -->|no| G["PR in next batch"]
  F --> H["Publish, release note, close issue"]
  G --> H
```

**Proposal:** an in-app form auto-attaches content version and step; the learner
picks a category (factual, wrong answer, link, lab setup, unclear,
accessibility, outdated) and may add text. Stored as `content_reports` (added;
columns `04`, retention `09`), never in analytics (F12), no Git account needed;
email link in the concierge trial (Open question 8). Issue template fields:
unit ID and version, step, category, severity, evidence, reproduction steps.

| Severity | Examples | Target (Proposal; pilot hypothesis) |
| --- | --- | --- |
| S1 | Wrong answer key; insecure advice; a lab check that passes a wrong solution or harms a machine; accessibility blocker | Triage within 1 working day; contain within 2 |
| S2 | Broken link or lab setup; misleading wording; outdated for the pinned version | Next weekly batch |
| S3 | Typo, style, suggestion | Monthly batch |

### 11.3 Hotfix path

1. **Contain (operator):** if a reviewed fix cannot ship within the target,
   retire the faulty item in a retire-only PR, reviewed afterwards because it
   adds nothing unreviewed. If the mission falls below V06, retire the mission;
   the topic shows as temporarily unavailable and progress never drops (R06).
2. **Fix:** a `hotfix/<issue>` branch with `change_class: defect_fix`. Every
   check runs. **Review:** the reviewer re-tries only the changed items.
3. **Publish:** add a release note and notify learners who attempted the faulty
   version (§10.1). **Learn:** add a rule or checklist item where possible.

### 11.4 Release notes for learners

The publish job collects PR learner notes into `release-notes/YYYY-MM.md` for
the operator to edit: learner-visible changes only, by roadmap, neutral tone, no
typos. Structural versions add the R06 migration summary (display: `08`).

## 12. Local authoring experience (described, not built)

| Need | Minimal approach (Proposal) |
| --- | --- |
| Editing | Any text editor. The JSON Schemas give autocompletion and inline errors in editors that support YAML schemas. |
| Checks | `content check` runs the same validator as CI. Offline mode skips the link check; `--changed` limits it to changed units. An optional pre-commit hook can run it. |
| Preview | `content preview` renders missions as static HTML in a phone-width frame, one step at a time. Hints, feedback and reveal stay hidden until clicked, so a reviewer can attempt honestly. CI attaches the same preview to each PR as a downloadable artifact, with no hosting cost. |
| Real player | Once the app exists, a local import loads working-tree content, drafts included, into the developer's own database. Drafts never reach production. A staging environment is optional, and only if it costs nothing extra (Open question 11). |
| Labs | Clone the kit at the referenced tag and run the setup check and local checks. Reviewers do the same on a clean machine or container. |
| Stats | `content stats` reports item counts per mission, time totals per module, units due for review, and estimated versus reviewer minutes. |

## 13. AI-assisted drafting policy

PRD §14: "do not treat lesson generation as a free by-product of AI." PRD §11
and §12: no employer repositories, no secrets, no learner data.

| Allowed as a drafting aid | Not allowed |
| --- | --- |
| Brainstorming scenario variations and changed conditions for alternates | Auto-publishing, or any path to `published` without human review |
| Suggesting distractors linked to known misconceptions | AI output as a source of facts; every claim needs a human-verified source |
| Rewording for clarity and reading level | AI-generated citations that no human has opened (`verified_by` names a person) |
| Draft alt text that the author checks | Answer keys accepted without the author working the problem |
| Ideas for synthetic-data generators | AI as the reviewer, or ticking the checklist; pasting learner data, report text, employer code or secrets into AI tools |

AI use is declared in `ai_assisted` and on the PR; the review bar is unchanged.
Authors check generated text does not reproduce third-party material. §14
assumes **no** AI time saving until one is measured.

## 14. Content effort model (all figures are hypotheses)

| Work unit | Qty | Author h each (range) | Reviewer h each (range) | Author total | Reviewer total |
| --- | --- | --- | --- | --- | --- |
| Mission core: scenario, explanation, worked example, misconception notes, 1–3 primary items with hints and feedback, small variant, sources, accessibility pass | 18 | 6 (4–9) | — | 108 | — |
| Held-back item (alternate or topic check) with feedback and hints | 54 (18 × 3) | 1 (0.5–1.5) | — | 54 | — |
| Mission review: try every item before reading answers; check sources and accessibility | 18 | — | 1.5 (1–2.5) | — | 27 |
| Rework after review, and re-check | 18 | 1.5 (0.5–3) | 0.25 | 27 | 4.5 |
| Shared lab starter project (fictional work-order app, seed generator, setup check) | 1 | 32 (24–48) | 4 (3–6) | 32 | 4 |
| Lab: tasks, checkpoints, synthetic data, local checks, rubric, no-setup fallback, clean-machine test | 6 | 18 (12–28) | 3.5 (2.5–5) | 108 | 21 |
| Shared transfer rubric | 1 | 6 (4–8) | 1.5 (1–2) | 6 | 1.5 |
| Transfer assessment form (baseline, final), including scoring calibration | 2 | 8 (6–12) | 2.5 (2–4) | 16 | 5 |
| Roadmap manifest, skills list, module introductions, completion rules | 1 | 8 (6–12) | 2 (1–3) | 8 | 2 |
| **Total** | | | | **≈ 360 (226–557)** | **≈ 65 (47–99)** |

**Total ≈ 425 hours (range ≈ 270–655).** Tooling is engineering work
(`12-delivery-plan.md`). Pilot upkeep: 2–4 author hours and about 1 reviewer
hour per week, plus about 15 hours per quarter of volatile review. Reviewer cost
is these hours times an unverified rate; get quotes first (PRD §14).

| Founder content hours per week | 10 | 15 | 20 |
| --- | --- | --- | --- |
| Weeks for ≈ 360 author hours | 36 | 24 | 18 |

**Content is the likely critical path.** The PRD's pre-pilot phases add up to
8–11 weeks, pilot readiness requires "six modules reviewed" (PRD §14), and the
same part-time person also builds the app. Levers that stay within the PRD:

1. Start module 1 and lab 1 during discovery; the concierge trial needs them.
2. **Calibrate after module 1:** if actual hours exceed the model by more than
   1.3 times, re-plan before module 2.
3. Author only the minimum held-back pool; one starter project for all labs.
4. Book the reviewer early so review never queues behind authoring.
5. If still short: a second author for labs, or a rolling module release,
   which changes a PRD exit condition (Open question 5).

## Open questions for discussion

1. **Where does `content/` live?** *Default:* a folder in the app's private
   repo with path-filtered CI, so schema, importer and content change together.
2. **How does publish reach production?** *Default:* an import command in the
   deploy pipeline after merge; no admin endpoint, no extra secret.
3. **How is "reviewer ≠ author" enforced on a private repo?** *Default:* the
   publish job's own gate (§7.3) on the free plan (plan features unverified).
4. **Reviewer recruitment.** *Default:* one paid part-time reviewer booked
   before discovery ends: about 65 h plus 1 h per pilot week (rate unverified).
5. **What if content is late?** *Default:* PRD §14, all six modules reviewed
   before the pilot. If module 1 overruns by more than 1.3 times, the founder
   picks a later pilot or a rolling release kept at least 2 modules ahead.
6. **Lab kits in a separate public repo?** *Default:* yes. Kits contain no
   answer keys, and lab evidence is learner-submitted, not a credential.
7. **Evidence earned on a defective item.** *Default:* keep evidence and
   credit, annotate, and schedule a fresh alternate check (05 confirms).
8. **Problem reports.** *Default:* a prefilled email link in the concierge
   trial; an in-app form storing `content_reports` (added) for the pilot.
9. **Review cadence.** *Default:* volatile 90 days, stable 365 days, plus a
   review when a watched technology has a breaking release.
10. **Held-back pool size.** *Default:* 2 alternates and 1 topic check; add a
    third where retention or challenge-out uses up the pool.
11. **Preview fidelity.** *Default:* the static CI-artifact preview; add a
    staging environment only if it costs nothing extra.

## PRD traceability

| PRD reference | Covered in |
| --- | --- |
| F11 Content operations (workflow, sources, version, reviewer, last-reviewed) | §2, §5, §7, §8, §9, §11 |
| §8 Mission requirements, item budget, reviewer tries exercises, quarterly review | §5.1, §7.3, §9 (V06, V11), §11.1, §14 |
| §8A Structure, required/optional topics, completion rules, cycle rejection, evidence reuse | §3, §6.4, §9 (V04, V05), §10 |
| R01, R03, R04, R06 Pinned versions, challenge-out, optional labs, version stability | §6.4, §8.3, §10.2 |
| F03, F05 Player steps, hints, feedback, alternate prompts | §5, §6.1–6.2 |
| F04, §9 Task version recorded, self-assessed labelling, reveal never demonstrates | §5.2, §6.2, §8.1 |
| F07, §16 Labs: starter files, synthetic data, rubrics, local checks, no-setup fallback | §5.2, §6.3, §9 (V19) |
| §10 Accessibility (mobile code, non-colour cues, no timed answers) | §5.2, §7.3, §9 (V12, V13) |
| §11 Attempts reference exact content versions; no employer code; local labs | §8.1, §10.1, §13 |
| §12 Retired content keeps evidence; obsolete lesson versions | §8.3, §10.1 |
| §13 Transfer assessments share one rubric; no free text in analytics | §5.2, §9 (V20), §11.2 |
| §14 Content as critical path; recruit reviewer early; AI not free | §13, §14, Open questions 4–5 |
