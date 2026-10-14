# End-to-end demo check

Runs the `docs/12` §5 thin-slice demo script in a real Chromium against a running server.

```sh
php artisan migrate:fresh --force
php artisan content:publish --path=../content --dev
npm run build
php artisan serve --port=8000 &           # APP_ENV=local so `clock:set` works
CHROMIUM_PATH=/path/to/chromium npm run test:e2e
```

It creates a fresh invite and learner each run, moves the app clock forward a day with `php artisan clock:set`
(refused in production) and resets it afterwards. Screenshots land in `e2e/out/` (ignored by Git).

## App phase and accessibility audit

`npm run test:e2e:app` (same prerequisites, and the server must serve a production `npm run build`) checks the installable
web app from `docs/14-app-phase.md`: manifest and icons, service-worker activation, offline behaviour for guests and
signed-in learners, that no authenticated API response is ever cached, and an axe-core WCAG 2.2 AA audit of every screen in
light and dark themes. It fails on any serious or critical violation. This supplements, and never replaces, the manual
release check in `docs/08` A-21 (keyboard, screen reader, 200% zoom, 320 px, reduced motion).

`npm run icons` re-renders `public/icons/*.png` from `resources/icons/*.svg`.

## Learning-loop journey

`npm run test:e2e:loop` enrols a fresh learner, defers and brings back a topic, passes a challenge-out, pauses and resumes, jumps the clock ten days, takes the return refresher and finishes with `Retained` evidence earned on the delayed alternate. It also audits those screens with axe-core.

## Account lifecycle

`npm run test:e2e:account` signs up, verifies the email from the emailed link (read from the dev mail log), resets the password, downloads the export, cancels a deletion, then lets a deletion run to a real purge by moving the clock past the 7-day grace.

## Mascot

`npm run test:e2e:mascot` checks the learner's control over Step (movement, still, hidden), that reduced motion always wins, that the mascot images stay under a weight budget, and audits the screens with axe-core with Step on and off. See `docs/15-mascot-and-brand.md`.

`npm run test:e2e:sound` checks the sound pack (`docs/15` §8a) with a recording stand-in for `Audio`: off by default, silent for choosing and typing, the right cue after authoritative feedback and at a finished session, one cue at a time, instant mute, files fetched only once sound is on (with the real `Audio`), and axe-core on Settings, the player and the finish screen.

`npm run test:e2e:explore` starts its own server (port 8011) with generation switched on, the queue inline and the offline provider, then walks "Learn something else": the form's disclosure and acknowledgement, a refused topic, a made path, starting it, the AI-made labels on Roadmap, the player and Evidence (capped at Practised), and switching focus to the main roadmap and back. It audits each screen with axe-core. See `docs/16-content-engine.md`.
