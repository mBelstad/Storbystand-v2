#!/usr/bin/env bash
set -euo pipefail

ROOT_DIR="$(cd "$(dirname "$0")/.." && pwd)"
cd "$ROOT_DIR"

if [ ! -f .env ]; then
  echo "✖️  Missing .env file. Copy .env.example to .env and adjust credentials first." >&2
  exit 1
fi

set -a
source .env
set +a

DC="docker compose --env-file ./.env -f docker/docker-compose.yml"

wpcli() {
  $DC run --rm -T wpcli php -d memory_limit=512M /usr/local/bin/wp "$@"
}

mkdir -p wordpress

# Download core if it does not exist yet
if [ ! -f wordpress/wp-settings.php ]; then
  echo "⬇️  Downloading WordPress core..."
  wpcli core download --path=/var/www/html --force
fi

echo "🚀 Starting infrastructure containers..."
$DC up -d db redis mailhog

# Wait for DB
printf "⏳ Waiting for database"
for i in {1..30}; do
  if $DC exec -T db mysqladmin ping -h "$DB_HOST" -P "$DB_PORT" -u"$DB_USER" -p"$DB_PASSWORD" --silent >/dev/null 2>&1; then
    echo " done"
    break
  fi
  printf '.'
  sleep 2
  if [ "$i" -eq 30 ]; then
    echo "\nDatabase is not responding. Check docker logs."
    exit 1
  fi
done

$DC up -d wp nginx

if ! wpcli core is-installed; then
  echo "⚙️  Installing WordPress..."
  wpcli core install \
    --url="${WP_PROTOCOL}://${DOMAIN}" \
    --title="$WP_TITLE" \
    --admin_user="$WP_ADMIN_USER" \
    --admin_password="$WP_ADMIN_PASSWORD" \
    --admin_email="$WP_ADMIN_EMAIL"
else
  echo "ℹ️  WordPress already installed, skipping core install."
fi

echo "✅ Local staging environment ready. Visit ${WP_PROTOCOL}://${DOMAIN}:8080"
