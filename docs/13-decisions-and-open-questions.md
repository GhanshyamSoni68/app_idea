# 13 · Decisions and open questions

Status: Proposal — for discussion

**Purpose.** This is the single place to decide things. Every other document
ends with its own open questions; this one collects the ones that matter most,
records what was settled while making the documents consistent, and lists what
to do next. Each question has a *recommended default* so a decision can be
"yes, take the default".

## Summary

- **Do not build yet.** The next step is discovery: 8–12 interviews and a
  two-week manual concierge trial. No product code is written before the
  planning gate on **30 Nov 2026** (`12-delivery-plan.md` §1).
- **Eight decisions need the founder** (§1). Two of them change the plan
  materially: which language you build in (it picks the stack) and how many
  hours a week you have (it picks the dates).
- **The honest market read is "moderate niche, weak moat"** (`11`). The combination
  of one continuing scenario, easy return after absence, and evidence of retained
  skill is new; each piece alone is not. Reviewed content and measured results are
  the only parts that would be hard to copy.
- **Content, not code, sets the schedule.** About 430 hours of authoring and
  review before the pilot, against roughly 185–355 engineering hours.
- **Indicative dates at 20 h/week:** planning gate 30 Nov 2026, alpha exit
  1 Mar 2027, pilot cohorts from 6 Sep 2027, continue/pivot decision ≈ 10 Dec 2027.
  A paid co-author for Modules 3–6 could bring the pilot forward to Jun–Jul 2027.
- **Running cost is small:** about $30/month of infrastructure at pilot scale
  (`unverified` vendor prices). The largest cash item is the part-time content reviewer.
- **About 100 cross-document contradictions were settled** by the rulings in §3, so
  the documents now describe one system.

## 1. Decisions for the founder

| # | Decision | Options | Recommended default | Why it matters | Read |
| --- | --- | --- | --- | --- | --- |
| D1 | Start discovery now, build later? | (a) Interviews + concierge first; (b) start the alpha now | **(a).** Book 8–12 interviews this month; run the concierge 9–22 Nov 2026 | The biggest risk is demand, not engineering. Gate 1 and the concierge kill test can stop the project for the price of a few weeks | 10 §4–5, 12 §1–2 |
| D2 | What do you build in day to day? | (a) Laravel/PHP; (b) TypeScript; (c) both | **(a) → Laravel on Laravel Cloud** (≈ $10–31/month pilot). If (b): Cloudflare Workers + Postgres (≈ $5–20/month) | Build speed for a solo builder outweighs a $10–25/month difference. The PRD says Laravel is your strength; confirm it | 01 §5–6, §14 |
| D3 | Weekly hours, and a co-author? | 10 / 20 / 30 h per week; co-author for M3–M6 yes/no | **Plan at 20 h/week**; decide on a co-author at alpha exit if content is running more than 4 weeks late | Sets every date after the gate. At 10 h/week the pilot moves to about mid-2028 | 12 §3, §8 |
| D4 | Paid content reviewer | Book now / later / no reviewer | **Book one part-time reviewer before discovery ends** (≈ 90 h quote incl. blind transfer scoring) plus a backup scorer | The PRD requires a competent reviewer to try every exercise; without one the pilot cannot start | 06 §14, 12 §11 |
| D5 | Positioning and recruiting | Laravel-only / stack-neutral | **Stack-neutral pitch ("for backend developers, examples in Laravel/PHP first")**; recruit mainly in Laravel communities; include 2–4 non-Laravel interviewees | Laravel-only is a good first channel but too narrow a market | 11 §9 |
| D6 | Lab-kit stack | Laravel edition / SQL-only / Node / Python | **Portable core (PostgreSQL 18 in Docker + synthetic data + black-box checks) with a Laravel 13 edition first**; SQL-only route for Lab 2; no second edition before the pilot | Labs run on the learner's laptop; setup friction is a known risk | 07 §6 |
| D7 | Pilot comparison and fallback | Static checklist arm / AI study-mode arm / none | **Randomised static-checklist arm** in the pilot; probe AI-study-mode use in interviews. Agree in advance: if the comparison does as well, **sell the path as content (workbook + lab kits)** instead of building more app | Protects against building an app whose value is really the content | 10 §6–7, 11 §6 |
| D8 | Hosting region | EU/UK, US, Singapore | **Choose provisionally at the planning gate**, nearest the expected cohort | Latency target and data-transfer obligations | 01 §16, 09 |

## 2. Open questions by document

The ones most likely to change a design. Each document's own "Open questions"
section has the full list and the reasoning.

| Doc | Question | Recommended default |
| --- | --- | --- |
| 01 | Laravel Cloud (managed) or Forge + a VPS (self-run)? | Laravel Cloud for alpha and pilot; reassess at 500 learners or a bill above $100/month |
| 01 | Keep production always-on during the pilot? | Yes (≈ +$20/month) to protect the 500 ms Today target; previews may sleep |
| 01 | Private repo on GitHub Free cannot require a review before merge | Accept self-discipline for code during alpha (content review is enforced by the publish job); buy GitHub Pro/Team if a second developer joins |
| 02 | Where do analytics live? | Separate schema in the same database, written after commit; move out only if reporting slows the app |
| 04 | How long to keep free-text answers? | Account lifetime during the pilot; before public launch consider deleting text after 24 months and keeping outcomes |
| 05 | Does one hint still count as demonstration? | No: `demonstrated` needs zero hints |
| 05 | Topic check straight after the mission or the next day? | Next learner day, so it doubles as spaced practice |
| 06 | Content running late after Module 1 | Re-plan if Module 1 takes more than 1.3× its estimate; delay the pilot rather than ship unreviewed modules |
| 07 | Learners on employer laptops may not be allowed Docker | Recommend a personal machine; test alternatives on the 3-OS matrix; read Docker licence terms before launch |
| 07 | Second lab-kit edition (e.g. Node) | Decide after the pilot; the SQL-only route for Lab 2 covers non-PHP learners meanwhile |
| 08 | Should "Show solution" be hidden until hints are used? | No gating at pilot (always available behind a confirmation); revisit if reveal rates are high |
| 09 | Where does the deletion ledger live? | A small encrypted file beside the off-site backups, written at request and at purge |
| 10 | Does self-assessed evidence count towards the north star? | No; report it on its own line |
| 10 | Which checklist-comparison design? | Randomised arms within two staggered cohorts; alternate cohorts if fewer than 30 consent |
| 11 | Add an AI-study-mode condition to the pilot? | No third arm at pilot size; probe AI-study use in interviews instead |
| 11 | Content-only fallback form | Try a workbook + lab kits first; a cohort format second |
| 12 | Founder's weekly hours | Plan at 20 h/week; re-baseline at the planning gate using actual hours |

## 3. Settled during cross-review

Five reviewers compared the documents pairwise and found about 100
contradictions. These rulings settled them; every document now follows them.
Reverse any of them by changing this table first, then the owning document.

| Area | Ruling | Owner |
| --- | --- | --- |
| Guest mode | Guest work (onboarding answers and the one sample scenario) stays **only in the browser** until sign-up. The server evaluates sample answers statelessly and stores nothing. On sign-up the bundle is uploaded, re-evaluated, and recorded with evidence capped at `practised`. Unclaimed work is deleted by the browser after 30 days | 02, 03, 04 |
| Sign-up | **Invite-only** during the pilot; the invite carries cohort and arm. No waitlist | 02, 04, 09 |
| Login | Email + password with verification, plus GitHub OAuth. Passkeys later. **No magic links**, so an email outage never blocks sign-in | 01 |
| Sessions | **One open learning session** per learner. Starting another suspends it (draft kept) | 04 |
| Drafts | Local-first; `base_revision` + `save_id`; a stale save returns `409` and the learner resolves the whole draft. No offline answer submission | 02 |
| Reveal | "Show solution" is always available behind a confirmation. Revealing before answering earns nothing above `introduced` and schedules a fresh attempt | 05 |
| Evidence | `demonstrated` and `retained` need zero hints, no worked example, no reveal, and an alternate item. Levels never drop; a failed later check adds "Refresh suggested". Evidence from defective content is annotated, never voided | 05 |
| Topic checks | At least 2 check-eligible items per topic (Alt A counts), one hint allowed, served on a later day, retry with the other item | 05, 06, 07 |
| Small mode | A separate curated variant per mission: one recall or decision task, ≤ 4 minutes | 05, 06 |
| Review load | PRD caps kept. Order: pending topic check → retention check → other. A passed topic check jumps to the 7-day rung. One review item per mission; backlog drains after completion | 05, 07 |
| Skills | One primary skill per mission; secondary skills get lab evidence only | 07 |
| Prerequisites | Soft by default; a `strict` flag marks hard edges | 05, 06, 04 |
| Progress | Completed required topics ÷ required topics, rounded **down**, count shown. Weekly target counts practice days | 05 |
| Migration | Opt-in with preview. Changed-objective topics keep credit with "Refresh suggested". New enrolment row; applies after any open session | 05, 04 |
| Labs | Optional topics; never affect required progress. Public kits contain no reference solutions | 06, 07 |
| Versions and IDs | Content `X.Y.Z` plus an objective version; only breaking or structural changes create a new roadmap version. Dotted lowercase IDs (`perf.query-plans`) | 06 |
| Publishing | A deploy-time `content:publish` command; review is enforced by the publish job. **No admin publish endpoint** | 06 |
| Reminders | At most once per local learning day (unique delivery row); 15-minute tick and time steps; quiet hours default 21:00–08:00 and skip rather than delay; "snooze" skips the next reminder; pausing a roadmap pauses reminders | 04, 09 |
| Analytics | Written after commit, never inside the learning transaction. Column names from `04`; event names from `10` | 04, 10 |
| Account deletion | 7-day cancellable grace, purge on day 7, alert by day 25. That learner's analytics events are deleted too. A deletion ledger outside the main database re-applies purges after any restore | 09, 04 |
| Backups | 14-day point-in-time recovery + weekly encrypted off-site dump kept 28 days, from the pilot. Restore drills use a throwaway cloud database, never a laptop | 01, 09 |
| Retention | Analytics 18 months; idempotency keys 7 days; reminder deliveries 12 months; drafts with their session; answers for the account's lifetime during the pilot | 09 |
| Exports | Generated into Postgres, downloaded via a short-lived authenticated link; no object storage at pilot | 09 |
| Environments | Local, a preview per pull request, production. No staging. Health check `/up` with a database check | 01 |
| Content effort | ≈ 430 h before the pilot (author ≈ 300–450, reviewer ≈ 50–80) + ≈ 20 h during it + blind transfer scoring. Calibrate after Module 1 (re-plan at 1.3×). All six modules reviewed before the pilot | 06, 12 |
| Validation | Gate 1 after discovery. Concierge uses template emails only (no personal chasing); thresholds frozen before it starts. No comparison arm in the concierge; the pilot randomises a static-checklist arm | 10, 12 |
| Dates | The indicative dates in the Summary; owned by `12` and mirrored in `10` | 12 |
| Costs | `01` is infrastructure only; people costs are in `12` | 01, 12 |

## 4. What to do in the next two weeks

None of this needs code.

1. **Answer D1–D3** (§1). They unblock everything else.
2. **Recruit 8–12 interviewees** with the screener and message in `10` §4. Include 2–4 developers who don't use Laravel.
3. **Ask a reviewer for a quote** (≈ 90 h over the next ten months, plus blind scoring during the pilot).
4. **Buy a plain domain** and set up SPF, DKIM and DMARC for a sending subdomain, so email reputation builds before the concierge.
5. **Draft the Module 2 sample scenario and Lab 2** (`07`), so interviewees and concierge participants can try real material.
6. **Freeze the concierge go/no-go thresholds** (`10` §5) before the first participant starts.

## Open questions for discussion

The decisions in §1 are the open questions for this document.

## PRD traceability

| PRD reference | Covered in |
| --- | --- |
| §13 Validation sequence | §1 D1, D7; §4 |
| §14 Delivery plan and cost discipline | §1 D3, D4; Summary |
| §15 Business and distribution hypotheses | §1 D5, D7 |
| §16 Main risks and open discovery questions | §1, §2 |
| §11–§12 Technical and operational boundaries | §3 |
