# Hosting Guide — Enterprise CTI Portal (Direct Native Server Deployment, No Docker)

Copy-paste-ready deployment for standard Linux servers. Covers **Ubuntu/Debian** and
**RHEL/Rocky/AlmaLinux**. Fedora notes included where packaging differs (this project was
built on Fedora 44).

> **Security change vs. the original spec:** the superadmin password is **generated
> randomly at seed time and printed once** to the operator. It is never hardcoded in the
> repository, and the account is flagged `must_change_password` so the first login forces a
> new password plus MFA enrollment. Do not reintroduce shared default credentials.

---

## 1. System Requirements & Package Installation

**Target:** 2+ vCPU, 4 GB RAM, 20 GB disk, ports 22/80/443 open (8080 stays local behind Nginx).

### Ubuntu 22.04/24.04 / Debian 12

```bash
sudo apt update && sudo apt upgrade -y
sudo apt install -y php8.3-fpm php8.3-cli php8.3-pgsql php8.3-redis php8.3-xml \
    php8.3-mbstring php8.3-curl php8.3-zip php8.3-bcmath php8.3-gd php8.3-intl \
    postgresql postgresql-contrib redis-server nginx certbot python3-certbot-nginx \
    supervisor git unzip
# Composer 2
curl -sS https://getcomposer.org/installer | sudo php -- --install-dir=/usr/local/bin --filename=composer
# Node.js 20 LTS
curl -fsSL https://deb.nodesource.com/setup_20.x | sudo -E bash -
sudo apt install -y nodejs
```

### RHEL / Rocky / AlmaLinux 9

```bash
sudo dnf install -y epel-release
sudo dnf install -y php-fpm php-cli php-pgsql php-xml php-mbstring php-curl \
    php-zip php-bcmath php-gd php-intl php-process \
    postgresql-server postgresql-contrib redis nginx certbot python3-certbot-nginx \
    supervisor git unzip
# Composer 2 + Node 20
curl -sS https://getcomposer.org/installer | sudo php -- --install-dir=/usr/local/bin --filename=composer
sudo dnf module install nodejs:20
```

> **Fedora note (dev host parity):** PHP may be installed as Remi SCL `php83-*` packages
> (e.g. `php83-php-pgsql`, `php83-php-process` for posix/pcntl). Do not mix plain `php-*`
> packages with `php83-*` — install extensions only from the same SCL set. Fedora ships
> **Valkey**, a drop-in Redis replacement (`systemctl enable --now valkey`), and requires
> `postgresql-contrib` for the `uuid-ossp`/`pg_trgm` extensions.

## 2. PostgreSQL & Redis Native Configuration

### Ubuntu/Debian

```bash
sudo systemctl enable --now postgresql redis-server
```

### RHEL/Rocky/Alma

```bash
sudo postgresql-setup --initdb
sudo systemctl enable --now postgresql redis-server
```

### Create the database, user, and required extensions

```bash
sudo -u postgres psql <<'SQL'
CREATE ROLE ctiadmin LOGIN PASSWORD 'CHANGE_ME_strong_password';
CREATE DATABASE cti_portal OWNER ctiadmin;
\c cti_portal
CREATE EXTENSION IF NOT EXISTS "uuid-ossp";
CREATE EXTENSION IF NOT EXISTS pg_trgm;
GRANT ALL PRIVILEGES ON DATABASE cti_portal TO ctiadmin;
SQL
```

> `CREATE EXTENSION` requires superuser — run it as the `postgres` user **before** running
> `php artisan migrate` (the migrations create `pg_trgm` indexes and a `tsvector` column).

**Password auth for localhost TCP** (needed by Laravel). Edit `pg_hba.conf`
(Ubuntu: `/etc/postgresql/16/main/pg_hba.conf`, RHEL: `/var/lib/pgsql/data/pg_hba.conf`)
and set the host lines to `scram-sha-256`:

```
host    all             all             127.0.0.1/32            scram-sha-256
host    all             all             ::1/128                 scram-sha-256
```

```bash
sudo systemctl reload postgresql
```

**Redis:** require a password in `/etc/redis/redis.conf` (`requirepass YourRedisPassword`)
for production, then `sudo systemctl restart redis-server` (RHEL service name: `redis`).

## 3. Application Deployment, `.env` & Frontend Build

```bash
sudo mkdir -p /var/www/cti-portal
sudo git clone <your-repository-url> /var/www/cti-portal
cd /var/www/cti-portal

cp .env.example .env
```

Edit `.env` (production values):

```ini
APP_ENV=production
APP_DEBUG=false
APP_URL=https://cti.example.com

DB_CONNECTION=pgsql
DB_HOST=127.0.0.1
DB_PORT=5432
DB_DATABASE=cti_portal
DB_USERNAME=ctiadmin
DB_PASSWORD=CHANGE_ME_strong_password

QUEUE_CONNECTION=redis
CACHE_STORE=redis
SESSION_DRIVER=redis
REDIS_CLIENT=predis
REDIS_HOST=127.0.0.1
REDIS_PASSWORD=YourRedisPassword
REDIS_PORT=6379

BROADCAST_CONNECTION=reverb

REVERB_APP_ID=cti-portal
REVERB_APP_KEY=<random hex>
REVERB_APP_SECRET=<random hex>
REVERB_HOST=cti.example.com
REVERB_PORT=443
REVERB_SCHEME=https
```

Build and boot:

```bash
composer install --optimize-autoloader --no-dev
npm ci && npm run build
php artisan key:generate
php artisan storage:link
php artisan migrate --force --seed   # seeds roles/permissions + superadmin (password printed ONCE)
php artisan optimize
```

**Record the printed superadmin password**, log in at `https://cti.example.com`, set a new
password, and enroll MFA. Then update API keys (AI providers, OTX, Abuse.ch) in the
Configuration Center.

## 4. Background Workers & WebSocket Daemons (Supervisor)

`/etc/supervisor/conf.d/cti-portal.conf`:

```ini
[program:cti-horizon]
command=php /var/www/cti-portal/artisan horizon
numprocs=1
autostart=true
autorestart=true
user=www-data
redirect_stderr=true
stdout_logfile=/var/log/supervisor/cti-horizon.log
stopwaitsecs=3600

[program:cti-reverb]
command=php /var/www/cti-portal/artisan reverb:start --host=127.0.0.1 --port=8080
numprocs=1
autostart=true
autorestart=true
user=www-data
redirect_stderr=true
stdout_logfile=/var/log/supervisor/cti-reverb.log
```

(RHEL with Remi SCL PHP: use the SCL binary path, e.g.
`/opt/remi/php83/root/usr/bin/php` or `/usr/bin/php83`.)

```bash
sudo supervisorctl reread && sudo supervisorctl update && sudo supervisorctl status
```

**Scheduler** — root crontab (`sudo crontab -e`):

```
* * * * * cd /var/www/cti-portal && /usr/bin/php artisan schedule:run >> /dev/null 2>&1
```

This drives `cti:poll-feeds` (runs every 5 minutes) for scheduled feed polling.

**After every deploy:** `php artisan queue:restart` — queue workers hold code in memory.

## 5. Nginx, Reverse Proxy & SSL

`/etc/nginx/sites-available/cti-portal` (Ubuntu) or
`/etc/nginx/conf.d/cti-portal.conf` (RHEL):

```nginx
map $http_upgrade $connection_upgrade {
    default upgrade;
    ''      close;
}

server {
    listen 80;
    server_name cti.example.com;
    root /var/www/cti-portal/public;
    index index.php;

    # Security headers
    add_header X-Frame-Options "SAMEORIGIN" always;
    add_header X-Content-Type-Options "nosniff" always;
    add_header Referrer-Policy "strict-origin-when-cross-origin" always;

    charset utf-8;
    client_max_body_size 20M;

    # Laravel Reverb websockets (spec: reverse proxy /app upgrade headers)
    location /app {
        proxy_pass http://127.0.0.1:8080;
        proxy_http_version 1.1;
        proxy_set_header Upgrade $http_upgrade;
        proxy_set_header Connection $connection_upgrade;
        proxy_set_header Host $host;
        proxy_set_header X-Real-IP $remote_addr;
    }

    location / {
        try_files $uri $uri/ /index.php?$query_string;
    }

    location ~ \.php$ {
        fastcgi_pass unix:/run/php/php8.3-fpm.sock;   # RHEL: include fastcgi config + socket per distro
        include snippets/fastcgi-php.conf;            # Ubuntu only
        fastcgi_param SCRIPT_FILENAME $realpath_root$fastcgi_script_name;
    }

    location ~* \.(css|js|jpg|png|svg|woff2)$ {
        expires 30d;
        access_log off;
    }

    location ~ /\.(?!well-known) { deny all; }
}
```

```bash
sudo ln -s /etc/nginx/sites-available/cti-portal /etc/nginx/sites-enabled/   # Ubuntu only
sudo nginx -t && sudo systemctl reload nginx
sudo certbot --nginx -d cti.example.com
```

## 6. File Permissions, Firewall & Post-Deployment Verification

```bash
# Ownership & writable dirs
sudo chown -R www-data:www-data /var/www/cti-portal/storage /var/www/cti-portal/bootstrap/cache
sudo chmod -R 775 /var/www/cti-portal/storage /var/www/cti-portal/bootstrap/cache
```

```bash
# UFW (Ubuntu/Debian)
sudo ufw allow 22 && sudo ufw allow 80 && sudo ufw allow 443 && sudo ufw enable
# firewalld (RHEL/Rocky/Alma)
sudo firewall-cmd --permanent --add-service={http,https,ssh} && sudo firewall-cmd --reload
```

**Verification checklist:**

1. `sudo supervisorctl status` → both `cti-horizon` and `cti-reverb` RUNNING
2. `curl -I https://cti.example.com/up` → 200 (built-in health route)
3. Visit `https://cti.example.com` → login page loads over HTTPS
4. Log in with the seeded superadmin password (printed once during `migrate --seed`),
   set a new password, enroll MFA
5. Configuration Center → paste AI provider + OTX/Abuse.ch API keys → "Test" buttons pass
6. Query Hub → run a `1 day ago` query → watch the live panel reach 100% and CTIs appear
7. CTI Repository → search, open a record, generate a PDF report, download it
8. `wscat -c wss://cti.example.com/app/<REVERB_APP_KEY>` (optional) → websocket handshake OK

**Operational notes:**

- SIEM integrations pull `GET /api/iocs?format=csv|stix&verdict=Malicious&since=YYYY-MM-DD`
  with a Sanctum bearer token
  (`php artisan tinker --execute='echo $u = App\Models\User::where("email","admin@example.com")->first()->createToken("siem")->plainTextToken;'`).
- Database backups: `pg_dump cti_portal | gzip > cti_portal_$(date +%F).sql.gz` on a daily cron.
- Upgrades: `git pull && composer install --no-dev && npm ci && npm run build && php artisan migrate --force && php artisan optimize && php artisan queue:restart`.
