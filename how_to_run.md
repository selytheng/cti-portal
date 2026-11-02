# Running the CTI Portal Locally (Development Quickstart)

Step-by-step guide to clone, configure, and run the portal on your own machine —
ending at `php artisan serve`. For **production** deployment on a Linux server,
see [`how_to_host.md`](how_to_host.md) instead.

---

## 1. Prerequisites

| Tool | Version |
|---|---|
| PHP | 8.3+ with `pdo_pgsql`, `mbstring`, `curl`, `zip`, `bcmath`, `gd`, `intl`, `xml`, `posix` |
| Composer | 2.x |
| Node.js + NPM | 20 LTS or newer |
| PostgreSQL | 16+ |
| Redis | 6+ (Valkey works as a drop-in) |
| Git | any recent version |

Check yours:

```bash
php -v && composer -V && node -v && psql --version && redis-server --version
```

## 2. Clone the project

```bash
git clone https://github.com/<your-username>/cti-portal.git
cd cti-portal
```

## 3. Install dependencies

```bash
composer install
npm install
```

## 4. Configure the environment

```bash
cp .env.example .env
php artisan key:generate
```

Edit `.env` — the important settings:

```ini
APP_NAME="CTI Portal"
APP_ENV=local
APP_DEBUG=true
APP_URL=http://127.0.0.1:8000

DB_CONNECTION=pgsql
DB_HOST=127.0.0.1
DB_PORT=5432
DB_DATABASE=cti_portal
DB_USERNAME=ctiadmin
DB_PASSWORD=your_db_password

QUEUE_CONNECTION=redis
CACHE_STORE=redis
SESSION_DRIVER=redis
REDIS_CLIENT=predis
REDIS_HOST=127.0.0.1
REDIS_PORT=6379

# Long-running AI extraction jobs need a long lease —
# retry_after MUST be greater than the longest job runtime.
REDIS_QUEUE_RETRY_AFTER=7200
```

> ⚠️ `REDIS_QUEUE_RETRY_AFTER` matters: the intelligence pipeline can run for
> several minutes per query (feed fetches + AI extraction). If the queue lease
> expires mid-run, a second worker picks up the job and it fails with
> `MaxAttemptsExceededException`. 7200s covers it.

## 5. Create the database

```bash
sudo -u postgres psql <<'SQL'
CREATE ROLE ctiadmin LOGIN PASSWORD 'your_db_password';
CREATE DATABASE cti_portal OWNER ctiadmin;
\c cti_portal
CREATE EXTENSION IF NOT EXISTS "uuid-ossp";
CREATE EXTENSION IF NOT EXISTS pg_trgm;
SQL
```

> ⚠️ The two extensions **must exist before you migrate** — the `cti_records`
> table creates `pg_trgm` indexes and a `tsvector` search column.

## 6. Migrate & seed

```bash
php artisan migrate --seed
```

The seeder creates:

- 3 roles (`superadmin`, `admin`, `monitoring`) with the full permission matrix
- A **superadmin account** with a **random password printed once** to your terminal:

```
Email:    manage@superadmin.admin
Password: <random — copy it now>
```

- Starter configuration: 4 default RSS feeds, OTX/Abuse.ch verifier records
  (inactive until keys are added), keyword-engine toggles, and a default HTML
  report template.

The account is flagged `must_change_password` — your first login forces a new
password and MFA enrollment. No credentials are ever stored in the repository.

## 7. Build the frontend & link storage

```bash
npm run build          # production assets (or: npm run dev for HMR while developing)
php artisan storage:link
```

## 8. Start the background services

Open **two extra terminals** — the pipeline needs both:

```bash
# Terminal 2 — queue worker (REQUIRED for the query pipeline)
php artisan horizon

# Terminal 3 — websocket server (live query progress)
php artisan reverb:start --host=127.0.0.1 --port=8080
```

## 9. Serve

```bash
# Terminal 1
php artisan serve
```

Open **http://127.0.0.1:8000** — you'll land on the login page.

## 10. First login & configuration

1. Log in with `manage@superadmin.admin` + the printed password
2. Set your own password, then enroll MFA with any authenticator app (QR scan)
3. **Configuration Center** → add your API keys:
   - **AI Agents** — Gemini, Claude, OpenAI, OpenRouter, or a custom
     OpenAI-compatible endpoint. *Optional:* without a key the pipeline still
     ingests articles in "developing" mode (no AI extraction)
   - **IOC Verifiers** — AlienVault OTX + Abuse.ch auth key (get both free via
     [auth.abuse.ch](https://auth.abuse.ch/)). *Optional* — IOCs stay
     `Unknown` until keys are active
   - **CVE Verification** — NVD API key ([free](https://nvd.nist.gov/developers/request-an-api-key)).
     *Optional* — without it, CVE enrichment is rate-limited to 5 req/30s
4. **Query Hub** → hit **⚡ Run Intelligence Query** → pick `7 days ago` → watch
   the live pipeline: Fetching RSS → Keyword filtering → AI extraction →
   Story merging → IOC verification → CVE verification → Completed

## 11. Run the tests

```bash
php artisan test
```

## Troubleshooting

| Symptom | Fix |
|---|---|
| `extension "pg_trgm" is not available` | Install `postgresql-contrib`, create the extensions (step 5) |
| `MaxAttemptsExceededException` on queries | `REDIS_QUEUE_RETRY_AFTER` in `.env` must be **greater** than the longest job runtime; restart Horizon after changing it |
| Websocket connection fails | Reverb must be running and `VITE_REVERB_*` values in `.env` must match |
| Blank page after an update | Hard-refresh (Ctrl+Shift+R) — stale built assets |
| `could not resolve host: mbazaar.abuse.ch` | Use current abuse.ch endpoints (the app already targets `mb-api.abuse.ch`) — abuse.ch retired old subdomains |
