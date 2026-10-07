# MandalHisab Backend

Laravel 11 API behind the MandalHisab (मंडळ हिशोब) mobile app: festival vargani (donation) collection, receipt books, expenses, cash handovers, bank/UPI funds, reports and the signed final hisab for Ganesh/festival mandals.

## Requirements

- PHP 8.2+ (8.3 recommended) with `ext-sodium`, `ext-zip`
- Composer 2
- MySQL 8 in production (SQLite in-memory is used for tests)

## Local setup

```bash
composer install
cp .env.example .env
php artisan key:generate
php artisan jwt:secret
php artisan migrate
php artisan serve
```

The API is served under `/api/v1`. Interactive docs are at `/doc`; the raw spec is at `/openapi.json` / `/openapi.yaml`.

## Configuration

Key `.env` settings (see `.env.example` for the full list):

| Setting | Purpose |
| --- | --- |
| `JWT_SECRET`, `JWT_TTL`, `JWT_REFRESH_TTL` | Access/refresh token signing and lifetimes |
| `RAZORPAY_KEY_ID`, `RAZORPAY_KEY_SECRET`, `RAZORPAY_REGISTRATION_AMOUNT_PAISE` | Mandal registration fee checkout |
| `FIREBASE_ENABLED`, `FIREBASE_PROJECT_ID`, `FIREBASE_CREDENTIALS` | FCM push notifications (service-account JSON path) |
| `QUEUE_CONNECTION` | Use `database` in production so pushes are delivered by the queue worker |
| `APP_LATEST_VERSION`, `APP_MIN_SUPPORTED_VERSION`, `APP_FORCE_UPDATE` | In-app update prompts |
| `MAIL_*` | Password reset emails |

Never commit `.env`, the Firebase service-account JSON, or signing keys.

## Architecture notes

- **Tenancy:** every protected route runs through `TenantScope`, which resolves the mandal/festival from the path, from the resource in the path (`{book}`, `{handover}`, `{account}`), or from the `X-Mandal-Id` / `X-Festival-Id` headers, and verifies active membership. Role checks (`role:` middleware) use that membership.
- **Final hisab lock:** once a festival's final hisab is signed and locked, `hisab.locked` blocks all financial writes for it.
- **Offline sync:** writes accept an `Idempotency-Key`; `sync/batch` and `sync/pull` let the app work offline and replay safely.
- **Public receipts:** donors open `/r/{id}` (web) or `GET /api/v1/public/receipts/{id}`, where `{id}` is the vargani entry id or the client UUID. Sequential receipt numbers are deliberately not accepted.

## Tests

```bash
php artisan test
```

`tests/Feature/ApiCoverage/RouteCoverageTest.php` fails if any API route has no test, so add a manifest entry when you add a route.

## Deployment

See [DEPLOY.md](DEPLOY.md) for shared-hosting deployment, cron/queue setup and Firebase configuration. The Android build served at `/download` lives at `public/mandalhishob.apk`.
