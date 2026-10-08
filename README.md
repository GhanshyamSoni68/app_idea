# DevStep — planning workspace

**Status: planning only. No application code exists, and none should be written
until the planning gate in [`docs/12-delivery-plan.md`](docs/12-delivery-plan.md) §1 passes.**

DevStep is a guided practice companion for working developers. It turns one
career goal into a small next action, ties those actions to one continuing
engineering scenario, and brings concepts back until the learner can apply
them unaided. The source requirements are in
[`docs/prd/devstep-prd-v0.1.md`](docs/prd/devstep-prd-v0.1.md).

This folder holds the system design and the plan, so we can decide what to
pursue before spending build time. Every document is a **proposal for
discussion**. Claims are labelled PRD, Proposal, Hypothesis or Open question,
and vendor prices are marked `unverified` until someone checks the live page.

## Start here

1. [`docs/13-decisions-and-open-questions.md`](docs/13-decisions-and-open-questions.md):
   the decisions that need the founder, each with a recommended default.
2. [`docs/12-delivery-plan.md`](docs/12-delivery-plan.md): what happens in what order, and the gate before any code.
3. [`docs/01-tech-stack-and-hosting.md`](docs/01-tech-stack-and-hosting.md): what to build with, where it runs and what it costs.
4. [`docs/02-system-architecture.md`](docs/02-system-architecture.md): the system on one page, then in detail.

## Document map

| # | Document | Answers |
| --- | --- | --- |
| 00 | [Conventions](docs/00-conventions.md) | Shared vocabulary: modules, tables, states, API paths, diagram rules |
| 01 | [Tech stack and hosting](docs/01-tech-stack-and-hosting.md) | Which stack and host? What does it cost at 50 / 500 / 5,000 learners? How do we leave? |
| 02 | [System architecture](docs/02-system-architecture.md) | C4 context, container and component views; module boundaries; the `/v1` API catalogue; background jobs |
| 03 | [Key flows](docs/03-key-flows.md) | Sequence diagrams for the daily loop, guest claim, drafts and offline, attempts, reminders, publishing, migration, labs, export and deletion |
| 04 | [Data model](docs/04-data-model.md) | ERDs, every table, constraints that make completion exactly-once, ownership, deletion, sizing |
| 05 | [Learning engine](docs/05-learning-engine.md) | How Today chooses, review intervals, evidence and topic state machines, completion, recovery after absence, acceptance scenarios |
| 06 | [Content system](docs/06-content-system.md) | Content as files in Git, review and publish workflow, validation rules, versioning, authoring effort |
| 07 | [Curriculum plan](docs/07-curriculum-plan.md) | The "From CRUD to Reliable Systems" path: scenario, skills graph, 18 missions, 6 labs, lab-kit stack, assessments |
| 08 | [UX and screens](docs/08-ux-and-screens.md) | Sitemap, journeys, phone and desktop wireframes, screen states, copy rules, accessibility checklist |
| 09 | [Security, privacy and operations](docs/09-security-privacy-ops.md) | Data inventory, threat model, authorisation tests, retention and deletion, runbooks, pilot readiness gate |
| 10 | [Measurement and validation](docs/10-measurement-and-validation.md) | Events and metrics; interview kit; concierge trial; pilot design and decision rules |
| 11 | [Market and positioning](docs/11-market-and-positioning.md) | Competitive refresh, the wedge, the free substitute, what to pursue |
| 12 | [Delivery plan](docs/12-delivery-plan.md) | Planning gate, phases, timeline, epics, critical path, risks |
| 13 | [Decisions and open questions](docs/13-decisions-and-open-questions.md) | Consolidated decision log for discussion |

## Reading by question

| If you want to know… | Read |
| --- | --- |
| Is this worth building at all? | 11, then 10 §§ interviews and concierge trial, then 12 §1 |
| What do we do next week? | 12 §1–2 and the interview kit in 10 |
| What will it cost to run? | 01 §12 |
| How does a learner's day work end to end? | 03 overview, then 05 §1 and 08 journeys |
| What has to be true before real people use it? | 09 pilot readiness gate, 12 §1 |
| What content must be written, and how long will it take? | 07 inventory, 06 effort model |

## Diagrams

Diagrams are Mermaid blocks and render directly on GitHub. All of them were
rendered with `mermaid-cli` before commit.
