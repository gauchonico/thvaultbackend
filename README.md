# NTV The Vault API (backend)

The Laravel API behind **NTV The Vault**, Nation Media Group Uganda's streaming platform: shows and episodes, live channels, accounts, plans, payments, rent/buy titles, the Freemium preview, the admin console, and the **Researcher Portal** (AI search over NMG's news archive).

- **Production:** https://thvaultbackend-production-rema6a.laravel.cloud, hosted on **Laravel Cloud**. All endpoints are under `/api`.
- **Clients:** the web app (separate repo, `stream-central`, live at https://nv.kfm.co.ug) and the mobile app. 

---

## Getting started (local)

**Requirements:** PHP 8.2+ (developed on 8.4), Composer, MySQL 8. On macOS we use **Laravel Herd** (serves the app at `http://vodapi.test`) and **DBngin** for MySQL.

```bash
composer install
cp .env.example .env
php artisan key:generate
# set DB_* in .env (DBngin's MySQL often runs on port 3307, not 3306)
php artisan migrate
php artisan db:seed          # plans, sample shows, and local test/admin logins
```

Locally, the seeder creates `test@example.com` / `password` and an admin `admin@ntvvault.test` / `password`, or the admin from `ADMIN_EMAIL` / `ADMIN_PASSWORD` if set. **On a server, no test user and no default admin are ever created.**

Point the web app at it with `VITE_API_URL=http://vodapi.test` in its `.env.local`.

## Configuration

Every setting is listed in **`.env.example`** with empty values. Real values live only in your local `.env` (git-ignored) and in **Laravel Cloud → Environment**. Never commit them, and never paste them into issues or pull requests.

| Area | Variables |
|---|---|
| Admin account (seeder) | `ADMIN_EMAIL`, `ADMIN_PASSWORD` |
| Researcher Portal | `EMBEDDINGS_PROVIDER` (`gemini` / `openai` / `ollama`), `GOOGLE_API_KEY`, `RESEARCHER_SYNTHESIS_ENABLED`, `GEMINI_*`, `OPENAI_*`, `OLLAMA_*` |
| YouTube auto-fill | `YOUTUBE_API_KEY` (`YOUTUB_API_KEY` also accepted) |
| Payments | `PAYMENTS_DRIVER` (`none` / `fake` / `pesapal`), `PAYMENTS_DEMO`, `PAYMENTS_RETURN_URL`, `PAYMENTS_RENTAL_HOURS`, `PAYMENTS_PERIOD_DAYS`, `PAYMENTS_PENDING_TIMEOUT` |
| Freemium preview defaults | `PREVIEW_SECONDS`, `PREVIEW_RESET`. Admins override these in the console (Admin → Plans), stored in the `settings` table |

Read env values in **`config/*.php` only** and use `config()` everywhere else: `env()` returns nothing once the config is cached in production.

---

## How it's organised

| Where | What |
|---|---|
| `routes/api.php` | All routes: public, signed-in (`auth:sanctum`), admin (`auth:sanctum` + `admin`) |
| `app/Http/Controllers/Api/` | Controllers. `V1/ResearcherSearchController` is the Researcher Portal |
| `app/Models/Show.php` → `applyAccess()` | **The single place that decides who can watch what.** Attaches `access` to every show and strips episode `video_url`s when the viewer may not watch |
| `app/Payments/` | `PaymentGateway` interface, `FakeGateway` (testing and demo), `PaymentService` (start, refresh, settle: grants plans and titles) |
| `app/Services/AiClient.php` | Embeddings and chat for the Researcher Portal (Gemini, OpenAI or Ollama) |
| `app/Services/WordPressIngestionService.php` | Pulls NMG articles from WordPress sites into `searchable_chunks` |
| `app/Models/Setting.php` | Admin-editable settings (Freemium preview) |

**Auth:** Laravel Sanctum bearer tokens (`POST /api/login` returns a token). Public show endpoints also read the token when one is sent, because access depends on the viewer.

### Content access (summary)
- Shows are `free` (any signed-in account) or `subscribers` (paid plans; Freemium gets a monthly preview allowance, counted by `POST /api/preview/heartbeat`).
- A show with `rent_price_ugx` / `buy_price_ugx` is sold on its own; `purchases` records who may watch.
- Signed-out users get no `video_url`s. Admins can watch everything.

### Payments (summary)
`POST /api/payments` starts a mobile money or card payment for a plan or a title. Clients poll `GET /api/payments/{reference}`. Provider webhooks hit `/api/payments/notify/{provider}`, which **re-checks the status with the provider** rather than trusting the request. Settling is idempotent. The `fake` driver refuses to run in production unless `PAYMENTS_DEMO=true`.

---

## Useful commands

```bash
# Researcher Portal: index articles (resumable; skips posts already indexed)
php artisan ingest:blogs KFM https://kfm.co.ug --max-posts=200
php artisan ingest:blogs KFM https://kfm.co.ug --since=2026-01-01

# Fill episodes' aired dates from their video links
php artisan episodes:backfill-aired-dates

php artisan test             # test suite
php artisan route:list --path=api
```

When switching `EMBEDDINGS_PROVIDER`, empty `searchable_chunks` and re-ingest: vectors from different providers can't be compared.

---

## Deploying (Laravel Cloud)

1. Merge to `main`, then deploy in Laravel Cloud. Migrations run on deploy.
2. New settings go in **Laravel Cloud → Environment**, followed by a redeploy.
3. **Deploy the backend before the web app** when the web app needs new endpoints.
4. After changing env values, run `php artisan config:clear` locally or redeploy on the server.

---

## Security rules for contributors

- **No secrets in the repo**: not in code, `.env.example`, seeders, tests, comments or commit messages. Use config and environment variables.
- **No default passwords** that work outside local development.
- **No personal data** (real users' emails or phone numbers) in seeders, tests or fixtures.
- Access decisions belong in `Show::applyAccess()`. Payment success comes only from the provider via `PaymentService::refresh()`.
- Admin actions on subscriptions and plans are logged with the admin's id. Keep that for new admin features.

## Contributing

Branch from `main` (`feature/…`, `fix/…`), open a pull request, and get at least one approval. Run `php artisan test` before pushing.

GitHub Actions (`.github/workflows/ci.yml`) runs on every pull request: it fails if a `.env` file is committed, validates `composer.json`, and runs the test suite on an in-memory SQLite database. It only checks; deploying still happens in Laravel Cloud. If an API change affects the clients, update the web app and the mobile brief in the same piece of work.
