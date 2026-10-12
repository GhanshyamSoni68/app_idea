---
id: perf.query-plans
version: 1.0.0
change_class: minor
status: published
title: Read a query plan before adding servers
purpose: Find what slows the overdue-jobs query before adding servers.
objective_key: perf.query-plans
objective_major: 1
objective: >-
  Given an EXPLAIN (ANALYZE) plan for a slow query, locate the most expensive node,
  compare estimated and actual rows, and choose one experiment that tests a stated hypothesis.
primary_skill: perf.query-plans
secondary_skills: [perf.experiments]
scenario_key: perf.sc.overdue-urgent
prerequisites: { missions: [perf.latency-evidence] }
time_estimate_minutes: { practise: 10, small: 4 }
steps:
  - { key: scenario, kind: scenario }
  - { key: first-move, kind: response, item: perf.query-plans.primary-1, modes: [practise, small], stop_after: true }
  - { key: worked-example, kind: explanation, stop_after: true }
  - { key: read-the-plan, kind: response, item: perf.query-plans.primary-2 }
  - { key: changed-condition, kind: response, item: perf.query-plans.changed-condition }
  - { key: scenario-short, kind: scenario, modes: [small] }
small_variant:
  steps: [scenario-short, first-move]
  purpose: Find the node that dominates the slow overdue-jobs query.
held_back:
  topic_check: [perf.query-plans.check-1]
  alternates: [perf.query-plans.alt-a, perf.query-plans.alt-b]
stack_scope: { portable_concept: true, postgresql: "18" }
volatility: volatile
volatility_watch: ["PostgreSQL major release (EXPLAIN output format)"]
sources:
  - url: https://www.postgresql.org/docs/18/using-explain.html
    title: "PostgreSQL documentation: Using EXPLAIN"
    supports: "Reading plan nodes; estimated versus actual rows; cost units are arbitrary"
    accessed: 2026-10-05
    verified_by: founder
accessibility: { reviewed_by: pending, reviewed_on: null }
author: founder
review: { reviewer: pending, last_reviewed: null, tried_exercise: false, tried_minutes: null }
ai_assisted: { used: true, parts: ["first draft of distractor wording"] }
---

## Scenario
The work-order dashboard has a card called **Overdue urgent jobs per site**. Since a large
client joined, it takes about two seconds to load. A colleague suggests doubling the
application servers. Every figure here is fictional.

The card runs this query:

```sql
SELECT site_id, count(*)
FROM work_orders
WHERE priority = 'urgent' AND status <> 'done' AND due_at < now()
GROUP BY site_id
ORDER BY count(*) DESC;
```

A prepared plan, captured with `EXPLAIN (ANALYZE)` on PostgreSQL 18 against synthetic data:

```text
 1  Sort  (cost=48210.4..48210.5 rows=40 width=12) (actual time=1893.2..1893.3 rows=38 loops=1)
 2    Sort Key: (count(*)) DESC
 3    ->  HashAggregate  (cost=48209.2..48209.6 rows=40 width=12) (actual time=1893.0..1893.1 rows=38 loops=1)
 4          Group Key: site_id
 5          ->  Seq Scan on work_orders  (cost=0.00..48100.0 rows=21840 width=4) (actual time=0.9..1871.4 rows=1480 loops=1)
 6                Filter: ((status <> 'done') AND (priority = 'urgent') AND (due_at < now()))
 7                Rows Removed by Filter: 1198520
 8  Planning Time: 0.3 ms
 9  Execution Time: 1893.6 ms
```

## Scenario (short)
The **Overdue urgent jobs per site** card takes about two seconds. Its plan shows three
nodes: a `Sort`, a `HashAggregate` and a `Seq Scan on work_orders` that reads about
1.2 million rows and keeps 1,480. Total execution time is 1,893 ms.

## First move
Before anyone changes anything, find where the time goes. Look at the `actual time`
of each node, and remember that a parent node includes the time of its children.

## Worked example
A plan is a tree. Read it from the innermost, most indented node outwards.

Here is a different, smaller plan for a different table:

```text
 1  Limit  (cost=0.43..8.9 rows=20 width=40) (actual time=0.05..0.31 rows=20 loops=1)
 2    ->  Index Scan using tickets_created_idx on tickets  (cost=0.43..4210.0 rows=9800 width=40) (actual time=0.04..0.29 rows=20 loops=1)
```

Three things to check, in order:

1. **Where is the time?** Compare each node's `actual time` with its children's. The
   node whose own time is large, after subtracting its children, is the one to study.
2. **What was read versus kept?** `Rows Removed by Filter` tells you how many rows the
   database read and threw away. Reading a lot to keep a little is a clue.
3. **Was the estimate right?** Compare `rows=` in the estimate with `rows=` in `actual`.
   A large gap means the planner's guess about your data was off, which can lead to a poor plan.

The `cost=` numbers are in arbitrary planner units. They are **not** milliseconds.
A `Seq Scan` is not always wrong: if a query needs most of a table, reading it in order is
often the cheapest way. It is a clue only when the query keeps a small part of what it reads.

To test a guess, change **one thing** and run the same query on the same data again, then
compare the two plans. A single timing is not evidence.

## Read the plan
Use the three checks from the worked example on the plan above.

## Misconceptions
- **perf.mc.scale-out-first**: "Add servers first." More application servers do not reduce
  the rows the database reads for each query. They only run the same slow query more times at once.
- **perf.mc.cost-is-ms**: "Cost is milliseconds." Cost is in arbitrary planner units. Use `actual time` for time.
- **perf.mc.seq-scan-bad**: "A Seq Scan is always bad." It is the right choice when most of the table is needed.
- **perf.mc.cache-hides-cause**: "Cache the whole page." That can hide the symptom, and a shared page cache can show one site's data to another.
