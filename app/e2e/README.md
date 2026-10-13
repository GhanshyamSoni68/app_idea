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
