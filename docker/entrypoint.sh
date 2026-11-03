#!/usr/bin/env bash
set -e

cd /var/www/html

echo "==> [CTI Portal] Ensuring storage directory structure exists..."
mkdir -p storage/framework/{sessions,views,cache/data} \
         storage/logs \
         storage/app/public \
         bootstrap/cache

# 1. Create .env automatically if it does not exist
if [ ! -f .env ]; then
    if [ -f .env.example ]; then
        echo "==> [CTI Portal] Creating .env from .env.example..."
        cp .env.example .env
    else
        echo "==> [CTI Portal] Creating default .env..."
        touch .env
    fi
fi

# 2. Ensure APP_KEY exists in .env
if ! grep -q "^APP_KEY=base64:" .env; then
    echo "==> [CTI Portal] Generating Laravel APP_KEY..."
    # Ensure APP_KEY line exists so key:generate can replace it
    grep -q "^APP_KEY=" .env || echo "APP_KEY=" >> .env
    php artisan key:generate --force
fi

# 3. Wait for PostgreSQL to be ready
echo "==> [CTI Portal] Waiting for PostgreSQL at ${DB_HOST:-postgres}:${DB_PORT:-5432}..."
until PGPASSWORD="${DB_PASSWORD}" pg_isready -h "${DB_HOST:-postgres}" -p "${DB_PORT:-5432}" -U "${DB_USERNAME:-cti_user}" -d "${DB_DATABASE:-cti_portal}" >/dev/null 2>&1; do
    sleep 2
done
echo "==> [CTI Portal] PostgreSQL is ready."

# 4. Run database migrations
echo "==> [CTI Portal] Running database migrations..."
php artisan migrate --force

# 5. Seed initial roles and admin user only if the users table is empty
USER_COUNT=$(php artisan tinker --execute="echo \App\Models\User::count();" 2>/dev/null | tr -dc '0-9' || echo "0")
if [ "${USER_COUNT:-0}" -eq "0" ]; then
    echo "==> [CTI Portal] Empty database detected. Running database seeders..."
    php artisan db:seed --force
else
    echo "==> [CTI Portal] Database already seeded (${USER_COUNT} users found). Skipping seed."
fi

# 6. Link storage & clear stale caches
php artisan storage:link --force >/dev/null 2>&1 || true
php artisan optimize:clear

# 7. Fix permissions for runtime processes
chown -R www-data:www-data /var/www/html/storage /var/www/html/bootstrap/cache

echo "==> [CTI Portal] Starting Nginx, PHP-FPM, Horizon/Queue, Reverb, and Scheduler..."
exec /usr/bin/supervisord -c /etc/supervisor/conf.d/supervisord.conf
