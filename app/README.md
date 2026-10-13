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
Move the app clock outside production: `php artisan clock:set 2026-10-13T09:00:00Z` (`--reset` to undo).

## Checks

| Command | What it checks |
| --- | --- |
| `php artisan test` | Feature tests on Postgres: the thin-slice demo script, the daily loop, evidence rules, drafts, authorisation matrix |
| `npm run typecheck && npm run test:unit` | TypeScript and the guest-store and Markdown safety tests |
| `npm run test:e2e` | The demo script in a real browser, see [`e2e/README.md`](e2e/README.md) |
| `npm run test:e2e:app` | Installable app, offline behaviour, cache privacy and an axe-core accessibility audit of every screen |
| `npm run test:e2e:loop` | Challenge-out, defer, pause and resume, and the return refresher ending in `Retained` evidence |
| `vendor/bin/pint --test` | Formatting |
| `php artisan content:validate --path=../content --dev` | Content rules (prerequisite cycles, item minimums, leakage, sources) |

## Layout

`app/Modules/*` follows the nine modules in `docs/00-conventions.md`; `config/devstep.php` holds every learning-engine parameter
(`docs/05` §13); `routes/v1.php` is the `/v1` API from `docs/02`.
