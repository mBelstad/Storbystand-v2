#!/usr/bin/env bash
set -euo pipefail

ROOT_DIR="$(cd "$(dirname "$0")/.." && pwd)"
MANIFEST="$ROOT_DIR/config/plugins-manifest.csv"

if [ ! -f .env ]; then
  echo "✖️  Missing .env file. Copy .env.example to .env first." >&2
  exit 1
fi

if [ ! -f "$MANIFEST" ]; then
  echo "✖️  Missing manifest at $MANIFEST" >&2
  exit 1
fi

set -a
source .env
set +a

DC="docker compose --env-file ./.env -f docker/docker-compose.yml"

if [ -z "${ASSETS_HOST_PATH:-}" ]; then
  echo "✖️  ASSETS_HOST_PATH not defined in .env" >&2
  exit 1
fi

if [ ! -d "$ASSETS_HOST_PATH" ]; then
  echo "✖️  Asset directory $ASSETS_HOST_PATH not found" >&2
  exit 1
fi

install_plugin() {
  local slug="$1"
  local rel_path="$2"
  local activate="$3"
  local host_path="$ASSETS_HOST_PATH/$rel_path"

  if [ ! -f "$host_path" ]; then
    echo "⚠️  Missing ZIP for $slug at $host_path" >&2
    return 0
  fi

  local container_path="/mnt/assets/$rel_path"
  if $DC run --rm -T wpcli php -d memory_limit=512M /usr/local/bin/wp plugin is-installed "$slug" >/dev/null 2>&1 </dev/null; then
    echo "ℹ️  $slug already installed."
    if [ -n "$activate" ]; then
      $DC run --rm -T wpcli php -d memory_limit=512M /usr/local/bin/wp plugin activate "$slug" </dev/null || true
    fi
    return 0
  fi

  echo "➡️  Installing $slug from $rel_path"
  $DC run --rm -T wpcli php -d memory_limit=512M /usr/local/bin/wp plugin install "$container_path" ${activate:+--activate} </dev/null
}

while IFS='|' read -r slug rel_path activate; do
  [[ "$slug" =~ ^#.*$ || -z "$slug" ]] && continue
  activate_flag=$(echo "$activate" | tr '[:upper:]' '[:lower:]')
  if [ "$activate_flag" = "yes" ]; then
    install_plugin "$slug" "$rel_path" "yes"
  else
    install_plugin "$slug" "$rel_path" ""
  fi
done < "$MANIFEST"

echo "✅ Plugin installation completed (missing ZIPs were skipped)."
