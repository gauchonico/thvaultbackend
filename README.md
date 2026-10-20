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


## Configuration

Every setting is listed in **`.env.example`** with empty values. Real values live only in your local `.env` (git-ignored) and in **Laravel Cloud → Environment**. Never commit them, and never paste them into issues or pull requests.

Read env values in **`config/*.php` only** and use `config()` everywhere else: `env()` returns nothing once the config is cached in production.


### Payments (summary)


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



## Contributing

Branch from `main` (`feature/…`, `fix/…`), open a pull request, and get at least one approval. Run `php artisan test` before pushing.

GitHub Actions (`.github/workflows/ci.yml`) runs on every pull request: it fails if a `.env` file is committed, validates `composer.json`, and runs the test suite on an in-memory SQLite database. It only checks; deploying still happens in Laravel Cloud. If an API change affects the clients, update the web app and the mobile brief in the same piece of work.
