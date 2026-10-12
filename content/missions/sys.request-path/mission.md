---
id: sys.request-path
version: 1.0.0
change_class: minor
status: published
title: Trace a request end to end
purpose: Follow one work-order request from the browser to the database, the queue and the email.
objective_key: sys.request-path
objective_major: 1
objective: >-
  Sequence the hops of a request from browser to database, queue, worker and mail, mark each hop as
  synchronous or background, and identify the most likely failure hop from a log excerpt.
primary_skill: sys.request-path
secondary_skills: []
scenario_key: sys.sc.submit-work-order
prerequisites: { missions: [] }
time_estimate_minutes: { practise: 8, small: 3 }
steps:
  - { key: scenario, kind: scenario }
  - { key: order-the-hops, kind: response, item: sys.request-path.primary-1, modes: [practise, small], stop_after: true }
  - { key: worked-example, kind: explanation, stop_after: true }
  - { key: find-the-failure, kind: response, item: sys.request-path.primary-2 }
  - { key: changed-condition, kind: response, item: sys.request-path.changed-condition }
  - { key: scenario-short, kind: scenario, modes: [small] }
small_variant:
  steps: [scenario-short, order-the-hops]
  purpose: Put the hops of a work-order submission in order.
held_back:
  topic_check: [sys.request-path.check-1]
  alternates: [sys.request-path.alt-a, sys.request-path.alt-b]
stack_scope: { portable_concept: true }
volatility: stable
volatility_watch: []
sources:
  - url: https://developer.mozilla.org/en-US/docs/Web/HTTP/Overview
    title: "MDN: Overview of HTTP"
    supports: "A request/response exchange; the response returns before any background work"
    accessed: 2026-10-05
    verified_by: founder
accessibility: { reviewed_by: pending, reviewed_on: null }
author: founder
review: { reviewer: pending, last_reviewed: null, tried_exercise: false, tried_minutes: null }
ai_assisted: { used: true, parts: ["first draft of distractor wording"] }
---

## Scenario
On your first week at a facilities company, a requester says: "I submitted a job but never got the
confirmation email." The app is a fictional work-order system. When someone submits a job, these things happen:

- The browser sends `POST /work-orders`.
- The web server checks the login and the form fields.
- The application saves the work order in the database.
- The application puts a "send confirmation" job on a queue.
- The web server sends the response, `201 Created`, to the browser.
- A worker takes the job from the queue.
- The worker asks the mail service to send the email.
- The mail service delivers the message.

## Scenario (short)
A requester submitted a work order and got no confirmation email. The request returned `201 Created`.
The email is sent later by a background worker. You need to put the hops in order.

## Order the hops
Put the eight hops in the order they happen. The response returns before any background work.

## Worked example
A request is not finished when the response returns. Some work continues in the background.

For a different request, a library's "reserve a book" button:

1. The browser sends the request.
2. The server checks the login and saves the reservation. This is **synchronous**: the user waits.
3. The server puts a "notify the member" job on a queue and returns `201 Created`. The response returns here.
4. A worker takes the job later and sends the notification. This is **background**: no one is waiting.

When something goes missing, ask two questions: **did the request return successfully?** If yes, the
synchronous hops worked. Then look at the **background hops** and the log of the worker, in order.

The browser does not talk to the database directly. The application sits between them, and state
lives in the database, the queue and the mail service, not in the browser.

## Find the failure
You know the response was `201`. Use that to rule hops in or out, then read the worker log.

## Misconceptions
- **sys.mc.done-at-response**: "The request is finished when the response returns." Background work continues after it.
- **sys.mc.browser-to-db**: "The browser talks to the database." The application is always in between.
