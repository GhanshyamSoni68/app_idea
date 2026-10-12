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
