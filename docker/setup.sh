#!/bin/sh
# One-time (idempotent) backend setup, run by the "setup" service in docker-compose.yml.
set -e
cd /var/www/backend

if [ ! -f .env ]; then
    echo "Creating backend/.env from docker/backend.env"
    cp /docker/backend.env .env
fi

# Password-reset SMTP credentials are encrypted with this key
if ! grep -q "^encryption.key" .env; then
    php spark key:generate --force
fi

echo "Installing PHP dependencies..."
composer install --no-interaction --optimize-autoloader

echo "Waiting for the database..."
until php -r '$m = @new mysqli("db", "inventory_user", "inventory_pass", "inventory_system"); exit($m->connect_errno ? 1 : 0);'; do
    sleep 2
done

php spark migrate

# Seed reference data and the first admin account only into an empty database
USERS=$(php -r '$m = new mysqli("db", "inventory_user", "inventory_pass", "inventory_system"); echo $m->query("SELECT COUNT(*) FROM user_table")->fetch_row()[0];')
if [ "$USERS" = "0" ]; then
    echo "Seeding the database..."
    php spark db:seed DatabaseSeeder
fi

# PHP-FPM runs as www-data; it writes sessions, logs, backups and barcodes here.
# The group is left alone so the host user can still manage these files.
mkdir -p writable/cache writable/logs writable/session writable/uploads writable/backups public/barcodes
chown -R www-data writable public/barcodes
chmod -R ug+rwX writable public/barcodes

echo "Backend setup complete."
