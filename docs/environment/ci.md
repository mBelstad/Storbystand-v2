# Continuous Integration Workflow

## Overview
The `CI` GitHub Actions workflow (`.github/workflows/ci.yml`) now runs two jobs on every push/PR targeting `main` or `develop`.

### 1. Lint & Build
1. **Shell lint** – `shellcheck bin/*.sh`.
2. **YAML lint** – validates workflow files and `docker/docker-compose.yml` with `yamllint`.
3. **Docker Compose validation** – renders the compose config with the CI env file.
4. **PHP-FPM image build** – ensures the custom `wp` image (Dockerfile) still compiles.
5. **WP-CLI smoke check** – invokes `php -v` inside the `wpcli` container.

### 2. End-to-End Smoke Tests
- Installs Node.js 20 + Playwright browsers.
- Copies `.github/env/ci.env` to `.env`, injects `ASSETS_HOST_PATH`, and runs `bin/setup-wordpress.sh` to provision a fresh Docker stack.
- Executes `npm run test:e2e` (Playwright) pointing at `http://localhost:8080`, logging into WP with the CI admin credentials.
- Tears down Docker regardless of success/failure to keep runners clean.

## CI Environment Variables
The workflow loads `.github/env/ci.env`, which mirrors the local `.env` structure but uses safe defaults suitable for ephemeral runners:

- `DOMAIN=localhost`, `APP_PORT=8080` – ensures WP URLs point to the runner itself.
- `WP_ADMIN_*` – placeholder admin credentials for initial install steps (not used yet but ready for future automated tests).
- `ASSETS_HOST_PATH` – injected at runtime as `${{ github.workspace }}/tests/assets` so Docker volume mounts succeed even though premium ZIPs are not stored in the repo.

If additional secrets (e.g., license keys) are needed later, define them as repository secrets and reference them via `${{ secrets.NAME }}` inside the workflow.

## Extending the Pipeline
- **Backend/Plugin Unit Tests**: add PHPUnit/Codeception jobs that reuse the Docker stack once custom PHP code exists.
- **Container Publishing**: add a gated job on `main` pushes to build/push the `wp` image to GHCR or another registry.
- **Deployment Hooks**: integrate with GitHub Environments to trigger staging/production deploys after tests succeed, ensuring backups run per policy.

## Local Parity
To reproduce CI checks locally:
```bash
# Lint scripts
shellcheck bin/*.sh
yamllint .github/workflows docker/docker-compose.yml

# Validate Compose + build image using CI env
ASSETS_HOST_PATH="$PWD/tests/assets" docker compose --env-file .github/env/ci.env -f docker/docker-compose.yml config >/dev/null
ASSETS_HOST_PATH="$PWD/tests/assets" docker compose --env-file .github/env/ci.env -f docker/docker-compose.yml build wp

# E2E smoke test
cp .github/env/ci.env .env
echo "ASSETS_HOST_PATH=$PWD/tests/assets" >> .env
./bin/setup-wordpress.sh
BASE_URL=http://localhost:8080 npm run test:e2e
docker compose --env-file ./.env -f docker/docker-compose.yml down
```

Keep this document updated as new jobs (e.g., automated migrations, visual tests) are introduced.
