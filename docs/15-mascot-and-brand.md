# 15 · Step, the mascot, and the brand

Status: Proposal — built; for discussion (decision recorded in `13` §3a)

**Purpose.** Give DevStep a friendly, consistent personality so it feels like a polished app people want to open, without breaking the principles that make it different from a streak app. This document defines who Step is, where Step appears, what Step says, what Step must never do, and how the artwork is produced and controlled.

## Summary

- **Decision (founder, recorded):** build a mascot, **Step**, and use it throughout the product. This brings forward the P1 "optional companion" (F15), which `12` §4.2 had locked until pilot evidence. **Open question 1** keeps the name as a working name. **Decision**
- **Step is a guide with no state.** It has no mood that depends on the learner, no health, no items, no counters, and no memory of absence. It cannot be neglected, so it cannot be sad. That makes the PRD §6 rules true by construction. **Proposal, built**
- **The polish comes from craft, not pressure.** Rounded type, a palette taken from the artwork, chunky pressable buttons, a path-style roadmap, a weekly practice row, and a celebration at stopping points. The Duolingo mechanics that rely on guilt (streak loss, "we miss you", hearts, leaderboards) are explicitly rejected (§6). **Proposal, built**
- **Supplied poses plus motion.** Nine supplied renders are used as they are: wave, cheer, think, focus (determined), idea (lightbulb), guide (map), rest (relaxed, eyes closed), code (laptop) and the head. Moods map onto them: wave and hello use *wave* (hello adds a pop-in); cheer uses *cheer* with confetti; focus is for something ready to start; idea is on Evidence; guide is for the first-run roadmap preview and the Roadmap page; rest is for a paused roadmap, a paused Today and nothing due; code sits beside each question in the player; think is for onboarding and the offline screen. The head is the avatar in the header, hints and remarks, and the source of the app icons. Motion (sway, pop, hop, a small "ready" nudge, breathing) sits on top of the pose. Nothing is redrawn, so Step stays recognisable. **A sad pose is kept as source only (`resources/mascot/source/step-sad.webp`) and is not wired to any state**: the PRD §6 rules out a companion that shows sadness, and a sad face on an error or after an absence is exactly that. A trophy pose was supplied in chat but never reached disk, so it is not in the repo yet; it is intended only for a completed roadmap and a passed challenge-out. **Proposal, built**
- **The learner is in control.** Settings offers Step with a little movement, perfectly still, or hidden. The OS reduced-motion setting always wins. What Step says is real text beside the figure, so nothing depends on seeing it. **PRD §2, §10; built and tested**
- **Step's words are enforced by a test.** Every line is scanned for guilt, fear, comparison, missed-day counts, mastery claims, exclamation marks and emoji. **Proposal, built**
- **Light:** the mascot images total about 27 KB on the first screen; icons and the favicon use Step's portrait. **Built**
- **Not decided here:** the licence and ownership of the artwork, a trademark check on the name, and whether to commission more poses (§9).

## 1. Why now, and what stays true

| Question | Answer | Label |
| --- | --- | --- |
| Does the PRD allow a companion? | Yes, as optional and P1: "cosmetic progress tied to effort; no punishment or impact on skill ratings" (F15). It must not "become sick, lose possessions, or guilt the user after inactivity" (PRD §6). | PRD |
| Why build it before the pilot gate? | The founder decided to. It makes the product feel finished, and the pilot can then test the question the PRD left open: does a companion help return visits? | Decision |
| What does the evidence say? | The PRD notes the research "does not establish that a mascot improves learning" (§2). Step is therefore framed as a hypothesis to test, not a proven lever, and it can be turned off. | PRD §2 |
| What does Step never touch? | Skill evidence, topic completion, review scheduling, recommendations, reminders, or analytics. Step is presentation only. | Proposal |

## 2. Who Step is

| Trait | Detail |
| --- | --- |
| Role | A friendly guide beside the work. It greets, points out the one next step, reacts calmly to feedback, and recognises effort at stopping points. |
| Personality | Warm, calm, specific, a little playful. Plain sentences, second person, British spelling. Never loud, never needy. |
| Look | The supplied 3D render: a rounded blue figure with a cream face, dark eyes, a smile and a yellow chest button. The palette of the whole UI is taken from it (§7). |
| Name | **Step**, a working name that echoes DevStep. Like the product name, it is unchecked (Open question 1). One constant, `MASCOT_NAME`, changes it everywhere. |
| Does not | Speak as an authority on mastery, give technical answers, chat freely, or use AI. Every line is authored (`resources/js/lib/stepSays.ts`). |

## 3. Where Step appears

| Screen | Mood | What Step says (context key) |
| --- | --- | --- |
| Landing | hello, large | Introduces itself (`landing`) |
| Guest onboarding | think | Reassures that nothing is a test (`onboarding`) |
| Today | by situation: focus (ready, resume), wave, hello, cheer (done), think (nothing due), rest (paused) | One next step (`today_ready`), resume, returning, done, paused, nothing due |
| Player: hints | avatar | Hint panels carry Step's face |
| Player: feedback | avatar remark | Calm reaction by outcome (`feedback_ok`, `feedback_notyet`, `feedback_self`) |
| Stopping point | cheer, large, confetti | One line chosen by what happened: practised, demonstrated, topic complete, roadmap complete, challenge passed or not |
| Sample complete | cheer | Keep your progress (`sample_done`) |
| Roadmap | think or rest; avatar marks the current topic | The path, one topic at a time |
| Evidence | wave, or think when empty | What you can show (`evidence`) |
| Settings | wave | Controls for Step (§5) |
| Offline, loading | think (offline: puzzled, never sad), hello | Offline notice, loading states |
| Icons and favicon | head portrait | App icon, favicon, apple-touch icon |

Not used on: the pending-deletion screen, error summaries and anything legal or serious. Tone matters more than presence there.

## 4. What Step says

Lines live in one file, grouped by context, and are chosen deterministically from the learner's local date and the context, so they do not flicker between renders. The unit test `stepSays.test.ts` fails the build if any line contains:

- guilt, pressure or fear: *missed, behind, overdue, streak, "you lost", hurry, falling, give up, deadline, last chance, obsolete*;
- comparison or flattery: *everyone, other learners, leaderboard, perfect, genius*;
- mastery claims after one exercise: *mastered, master*; or the words *wrong, fail, lazy, must*;
- an exclamation mark, an emoji, a count of days or tasks, or more than 120 characters.

"Nothing is lost" is allowed: reassurance is not guilt. The rule is precise on purpose.

## 5. Learner control and accessibility

| Control | Behaviour |
| --- | --- |
| Settings, "How much of Step do you want to see?" | **Step with a little movement** (default), **perfectly still**, or **Hide Step**. Stored on this device only. |
| OS reduced motion | Always wins: Step is still and there is no confetti, even if set to "on". |
| Hidden | No figure or avatar appears anywhere. Step's lines stay as plain text in the same place, so no information is lost. The DevStep wordmark remains. |
| Screen readers | Step is decorative (`alt=""`, hidden from assistive technology). Anything meaningful is text. |
| Motion | Only transform and opacity animations, all behind `prefers-reduced-motion: no-preference`; no flashing; no auto-advance (`08` IR-13). Sound is separate and off by default (§8a). |
| Verification | `npm run test:e2e:mascot` checks on, still, off and reduced motion, the image weight and an axe-core audit in both themes. The manual release check (`08` A-21) still applies. |

## 6. What Step must never do (and what we deliberately do not copy)

This extends the rule table in `08` §11.

| Never | Because |
| --- | --- |
| Look sad, sleepy-as-punishment, hungry, lonely or hurt; show absence | PRD §6. Step's "rest" mood (calm breathing, the relaxed pose) is only used for a paused roadmap, and "think" for offline or nothing being due; both are calm, not sad. The supplied sad pose is not used anywhere. |
| Streaks, streak freezes, "lose your streak", counters of missed days | PRD §6; `12` §9 "Never". The weekly row is dots for days practised: a missed day is an empty circle and nothing breaks. |
| Hearts, lives, energy or anything that blocks learning | Breaks "no mandatory browsing" and autonomy (PRD §6, F02). |
| "We miss you", guilt notifications, or any message of its own | Reminders are opt-in, one per day, and plain (PRD §6, F10). |
| Leaderboards, rankings, comparison with other learners | PRD §6. |
| Affect evidence, recommendations, completion or scheduling | Effort recognition stays separate from skill evidence (PRD §6, F15). |
| Cosmetic purchases, unlocks that gate content, loot | No pricing claim is validated (PRD §15). |
| Make a mastery claim | "Demonstrated" requires a new task with no help (`05` §6). Step recognises effort or reports evidence in the evidence's own words. |

## 7. Visual system (brand)

| Element | Choice |
| --- | --- |
| Palette | Taken from Step: action blue `#2563ff` (white text, 4.9:1), cream `#fdf5e6` (Step's face; speech bubbles), sun yellow `#fdc535` (Step's button; practice days), navy text. Dark theme has its own tokens, contrast-checked. |
| Type | Nunito Variable, **self-hosted** through the build (no font requests to a third party, which keeps the privacy posture in `09`). Falls back to the system rounded face. |
| Shape | 16–22 px radii. Buttons, cards, choices and bars have a thicker bottom edge and press down 3 px when pressed (hidden under reduced motion). |
| Status | Never colour alone: a word and a marker always accompany it (`08` A-12). |
| Progress | A rounded bar with "N of M" beside it, and a segmented bar in the player with "Step N of M" as text. |
| Roadmap | A path of nodes down each module, still an ordered list for assistive technology. Step's avatar marks the current topic. |
| Icons | A small outline set for navigation (Today, Roadmap, Evidence). |

## 8. Artwork pipeline

| Item | Detail |
| --- | --- |
| Source | `app/resources/mascot/source/`: the supplied renders, already transparent cutouts (wave, cheer, think, focus, and sad, which is unused). The app serves `step-<pose>-360.webp` and `-720.webp`, about 20 and 45 KB each, and `step-head-{128,256}.webp`; only the pose on screen is fetched. |
| Delivered | Two sizes of the full figure (360 and 720 px WebP, chosen by `srcset`), and a circular head portrait (128 and 256 px WebP) for avatars. Files are imported through the build, so they are fingerprinted and safe to cache indefinitely (the host decides the cache lifetime). |
| Icons | `npm run icons` renders the app, maskable and apple-touch icons from the portrait on the brand cream. The maskable icon keeps Step inside the safe zone. |
| Poses | There is one pose. Moods are motion and props. This is the main limit of the current approach (Open question 3). |

## 8a. Sound

The founder supplied a sound pack (`app/resources/sound/`, ten original synthesized cues, 44.1 kHz mono WAV, no voice, no external samples; the generating script and its integration notes are kept in `app/resources/sound/source/`). It is integrated under the PRD's calm-product rules. **Decision (founder asked for it), built**

| Rule | How it is met |
| --- | --- |
| Optional and off by default | A "Sound effects" setting in Settings (stored on this device only), plus a mute button in the player header that works immediately. Nothing is downloaded while sound is off; a cue's file is fetched the first time it plays. |
| Only for feedback the learner can also see | Cues play beside a visible result, never instead of one. `correct` and `try-again` play only for authoritative (auto-scored) feedback. Self-assessed answers make no sound, because they prove nothing. |
| Never a measure of absence or failure | No sound for typing, background saving, scrolling, errors, time away or missed practice. `try-again` is a soft invitation; `rest` is only for pausing the roadmap. |
| One cue at a time | A new cue stops the previous one; the same cue is ignored for 400 ms; a bigger moment is never cut off by a smaller one (milestone over lesson-complete over everything else). |
| Safe failures | A blocked or failed sound is silence. It never interrupts a lesson. |

| Cue | Where it plays |
| --- | --- |
| `correct`, `try-again` | After an auto-scored answer is checked |
| `hint` | When a hint is shown |
| `next` | Moving to the next step of a session |
| `saved` | After "Save and exit" |
| `lesson-complete` | The session or sample finish screen |
| `milestone` | The roadmap-complete screen, or Today's roadmap-complete state (once per visit to the app) |
| `rest` | After pausing the roadmap |
| `welcome` | Once per visit, when the learner starts a session from Today (never on resume) |
| `tap` | Only as the preview when the learner turns sound on. It is not attached to buttons: the pack itself warns against rapid repeated taps |

The rules live in `lib/soundPolicy.ts` (pure, unit-tested) and the browser behaviour is checked by `npm run test:e2e:sound`. The pack's own note stands: it has been checked for valid PCM, length and headroom, but **final listening and loudness balance on phone speakers and headphones is still to be done by a person**. Playback volume is 0.7 until then.

## 9. Open questions for discussion

1. **Name.** *Recommended default:* keep **Step** as a working name, and check availability and trademark before any public launch, together with the product name (PRD header).
2. **Ownership and licence of the artwork.** The render was supplied by the founder; its provenance is not recorded here. *Recommended default:* record who made it and the licence in this file before any public release. If it was machine-generated, confirm the generating tool's terms allow commercial use, and consider commissioning a human-made version of the same design.
3. **More poses.** *Recommended default:* commission 4 to 6 poses from the same artist (thinking, cheering, resting, pointing, celebrating a milestone) only if the pilot suggests learners like Step. Real poses will look better than motion on a single figure.
4. **Does Step help?** *Recommended default:* treat it as a hypothesis. In the pilot, compare return and burden guardrails (PRD §13) between learners who keep Step on and those who turn it off, using an enumerated, non-identifying setting event (not built yet).
5. **Cosmetic progress (the rest of F15).** *Recommended default:* not now. A "workshop" that gains one labelled part per completed module (`08` §11) is the safest form, because it only ever grows and carries a text alternative. Build it only if Step alone is not enough.
6. **Sound.** Answered by §8a: built, off by default. Still open: a listening pass on real devices; whether the provenance and licence of the pack are recorded (same question as 2); and whether the default should stay off after the pilot (the pilot can compare retention and complaints).

## PRD traceability

| PRD reference | Covered in |
| --- | --- |
| §1 No fear marketing | §4, §6 |
| §2 Autonomy; the research does not establish that a mascot helps | §1, §5, Open question 4 |
| §5 Reward identifies evidence, not "you mastered" | §3, §6 |
| §6 Companion must not sicken, punish or guilt; effort kept apart from skill; opt-in reminders | §2, §6 |
| §7 F15 optional companion (P1) | Summary, §1, Open question 5 |
| §10 Reduced motion, non-colour cues, light and dark themes | §5, §7 |
| §12 Privacy: no third-party requests; analytics carry no free text | §7 (self-hosted type), §6 |
| §13 Guardrails (guilt or overwhelm, reminders disabled) | Open question 4 |
