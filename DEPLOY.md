# MandalHisab Backend — Deployment Guide

## Shared Hosting (Apache + PHP 8.3/8.4 + MySQL 8)

### 1. Build Vendor Locally (or on host)

Since the shared host may not have Composer, build the `vendor/` folder locally or on a machine with:
- PHP 8.3+
- Composer 2.x
- `ext-zip`, `ext-sodium` enabled

```bash
cd backend/
composer install --no-dev --optimize-autoloader
```

### 2. Upload Files

Upload the entire `backend/` folder (including `vendor/`) to your shared host.

Typical path: `/home/username/public_html/` or `/home/username/mandalhisab/`

Ensure the `public/` directory is the web root.

### 3. Environment Setup

```bash
cp .env.example .env
php artisan key:generate
php artisan jwt:secret
```

Edit `.env`:
```ini
APP_ENV=production
APP_DEBUG=false
APP_URL=https://api.mandalhisab.in

DB_DATABASE=mandalhisab
DB_USERNAME=your_db_user
DB_PASSWORD=your_db_password

FIREBASE_ENABLED=true
FIREBASE_PROJECT_ID=your-project-id
```

Upload the Firebase Service Account JSON to `storage/app/firebase-service-account.json`.

### 4. Database

```bash
php artisan migrate
```

### 5. Storage Link

```bash
php artisan storage:link
```

### 6. Cron Jobs (cPanel)

Add these cron jobs in cPanel:

```
# Process default queue every minute
* * * * * cd /home/username/backend && php artisan queue:work --queue=default --stop-when-empty --max-time=55 >> /dev/null 2>&1

# Process notifications queue every minute
* * * * * cd /home/username/backend && php artisan queue:work --queue=notifications --stop-when-empty --max-time=55 >> /dev/null 2>&1

# Clean old streamed events (hourly)
0 * * * * cd /home/username/backend && php artisan model:prune --model=App\Models\StreamedEvent >> /dev/null 2>&1

# Clean old idempotency records (hourly)
0 * * * * cd /home/username/backend && php artisan model:prune --model=App\Models\IdempotencyRecord >> /dev/null 2>&1
```

### 7. PHP Settings

The `public/.user.ini` file already contains:
```ini
max_execution_time = 0
memory_limit = 512M
output_buffering = Off
zlib.output_compression = Off
```

If your host restricts `max_execution_time`, use the maximum allowed value.

### 8. SSL

Ensure HTTPS is enabled. JWT auth requires SSL in production.

---

## Post-Deployment Verification

```bash
# Check application health
curl https://api.mandalhisab.in/up

# Check app config (public endpoint)
curl https://api.mandalhisab.in/api/v1/config/app

# Test auth
curl -X POST https://api.mandalhisab.in/api/v1/auth/register \
  -H "Content-Type: application/json" \
  -d '{"fullName":"Test","usernameOrPhone":"9876543210","password":"SecurePass@123"}'
```

## Troubleshooting

| Issue | Fix |
|-------|-----|
| 500 Internal Server Error | Check `storage/logs/laravel.log` |
| JWT secret missing | Run `php artisan jwt:secret` |
| File uploads fail | Ensure `storage/app/` is writable (755) |
| Queue not processing | Verify cron job is running; check `jobs` table |
| SSE disconnects quickly | Normal on shared hosting — client auto-reconnects |
| FCM not sending | Check `FIREBASE_ENABLED=true` and service account JSON path |
