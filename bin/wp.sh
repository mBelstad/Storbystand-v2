#!/usr/bin/env bash
set -euo pipefail

cd "$(dirname "$0")/.."

if [ ! -f .env ]; then
  echo "Missing .env file. Copy .env.example first." >&2
  exit 1
fi

DC="docker compose --env-file ./.env -f docker/docker-compose.yml"

$DC run --rm -T wpcli php -d memory_limit=512M /usr/local/bin/wp "$@"
