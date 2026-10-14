# DevStep application

Laravel 13 (PHP 8.3+) modular monolith with a React + TypeScript single-page app, PostgreSQL, and no Redis.
Design lives in [`../docs`](../docs); authored learning content lives in [`../content`](../content).

## Run it locally

```sh
cp .env.example .env && php artisan key:generate
# create a Postgres database and user matching .env (devstep / devstep)
composer install && npm install
php artisan migrate --force
php artisan content:publish --path=../content --dev   # --dev: missions are not reviewer-signed yet
npm run build
php artisan serve
```

Create a pilot invite (sign-up is invite-only): `php artisan invite:create someone@example.com`.
Emails (verification, reset) go to the log in development: `storage/logs/laravel.log`. Scheduled work: `php artisan schedule:work`.
After restoring a backup, re-apply deletions that already happened: `php artisan accounts:replay-ledger`.
Move the app clock outside production: `php artisan clock:set 2026-10-13T09:00:00Z` (`--reset` to undo).

## Checks

| Command | What it checks |
| --- | --- |
| `php artisan test` | Feature tests on Postgres: the thin-slice demo script, the daily loop, evidence rules, drafts, authorisation matrix |
| `npm run typecheck && npm run test:unit` | TypeScript and the guest-store and Markdown safety tests |
| `npm run test:e2e` | The demo script in a real browser, see [`e2e/README.md`](e2e/README.md) |
| `npm run test:e2e:mascot` | Step on, still, hidden and under reduced motion; image weight; axe-core audit |
| `npm run test:e2e:app` | Installable app, offline behaviour, cache privacy and an axe-core accessibility audit of every screen |
| `npm run test:e2e:account` | Email verification, password reset, export download, and deletion with cancel and a real purge |
| `npm run test:e2e:sound` | The sound pack: off by default, the right cue at the right moment, one at a time, instant mute |
| `npm run test:e2e:explore` | "Learn something else": refusal, a made path, start, the AI-made labels, capped evidence, switching focus (starts its own server) |
| `npm run test:e2e:loop` | Challenge-out, defer, pause and resume, and the return refresher ending in `Retained` evidence |
| `vendor/bin/pint --test` | Formatting |
| `php artisan content:validate --path=../content --dev` | Content rules (prerequisite cycles, item minimums, leakage, sources) |

## Layout

`app/Modules/*` follows the nine modules in `docs/00-conventions.md`; `config/devstep.php` holds every learning-engine parameter
(`docs/05` §13); `routes/v1.php` is the `/v1` API from `docs/02`.

## Content engine (optional, off by default)

`docs/16-content-engine.md`. Writes practice on a topic a learner names, through any model (Anthropic, OpenAI-compatible and local servers, your own endpoint). Nothing is needed to run DevStep without it.

| Command | What it does |
| --- | --- |
| `php artisan generation:providers [--ping]` | Providers, routes, readiness; `--ping` proves each one answers |
| `php artisan generation:draft "topic" --enable --missions=2` | Plan a path and draft missions, printing what was kept and why; publishes nothing |
| `php artisan generation:runs [--calls]` | Recent runs, spend, and every model call |
| `php artisan generation:review [--list] {id} --approve --reviewer="Name" --tried-exercise` | A person reviews AI-made content (or `--reject` to retire it) |
| `php artisan generation:backfill` | Retries topics that could not be made (scheduled hourly) |

Switch on with `GENERATION_ENABLED=true` and a real provider route (see `.env.example`); run a queue worker (`php artisan queue:work`) and the scheduler.

