# Local Staging Environment Setup

## Prerequisites
- Docker Desktop 4.30+ (Compose v2).
- Access to `/Users/mariusbelstad/Dev_folder/Wordpress Assets/` containing Crocoblock, Elementor Pro, JetFormBuilder, Fluent suite ZIPs.
- (Optional) If you change the domain away from the default `localhost`, create a matching entry in `/etc/hosts` (e.g., `127.0.0.1 storbystand.local`).

## 1. Configure Environment
```bash
cp .env.example .env
# Edit .env to tweak passwords, domain, ports, asset path, etc.
```
Key variables:
- `DOMAIN` – hostname served by nginx (default `localhost`).
- `APP_PORT` – external port exposed on your machine (default `80`).
- `ASSETS_HOST_PATH` – absolute path containing plugin ZIPs (mounted read-only).
- `WP_ADMIN_*` – default admin credentials created during setup.

## 2. Bootstrap WordPress
```bash
make setup
```
What it does:
1. Downloads WP core into `./wordpress/` (ignored by git).
2. Spins up MariaDB, Redis, Mailhog, PHP-FPM, and nginx containers via `docker/docker-compose.yml`.
3. Runs WP-CLI install with values from `.env`.
4. Prints the local URL (default `http://localhost`).

**Mailhog** is available at `http://localhost:8025` for testing outbound email.

## 3. Install Plugins from Asset Library
1. Update `config/plugins-manifest.csv` so each plugin points to the correct ZIP relative to `ASSETS_HOST_PATH`.
2. Run:
```bash
bin/install-plugins.sh
```
The script copies/installs each ZIP through WP-CLI, activating those marked `yes` in the manifest. Missing ZIPs are skipped with a warning.

## 4. Common Commands
| Task | Command |
| --- | --- |
| Start/stop stack | `make up` / `make stop` |
| Tail logs | `make logs` |
| Run WP-CLI | `make wp option get siteurl` (after `make wp`) |
| Open shell inside PHP container | `make sh` |
| Shutdown & remove containers | `docker compose -f docker/docker-compose.yml down` |

## 5. Database Backups
Use WP-CLI helper before deployments:
```bash
bin/wp.sh db export backups/$(date +%Y%m%d_%H%M)_local.sql
```
Make sure `backups/` exists (git-ignored). These dumps, along with git commits, satisfy the “code + DB backup” requirement before promoting changes.

## 6. Seeding & CLI Utilities
| Purpose | Command | Notes |
| --- | --- | --- |
| Rebuild JetEngine CCT/queries/templates | `bin/wp.sh stb jetengine sync` | Safe to run after pulling schema changes. |
| Reset mock data | `bin/wp.sh stb seed mock` | Truncates JetEngine tables, reseeds shifts/assignments, ensures demo users + notification prefs exist. |
| Queue a notification | `bin/wp.sh stb notifications queue --event=reminder_24h --recipient=USER_ID --shift=SHIFT_ID` | Useful for verifying FluentCRM + queue processing. |
| Process notification queue | `bin/wp.sh stb notifications run [--limit=25]` | Normally handled by cron; run manually during QA. |
| Generate stats snapshots | `bin/wp.sh stb stats run --period=day` | Produces `stb_stats_snapshot` posts and feeds future charts. |

All commands run inside the `wpcli` container, so Docker must be up.

## 7. PWA & Manifest Notes
- The `stb-pwa` plugin ships with this repo and automatically registers a manifest (`/stb-manifest.json`) plus a service worker (`/app-sw.js`). No additional nginx tweaks are required.
- To customize icons or offline copy, edit files under `[wp-content/plugins/stb-pwa/assets/](../wp-content/plugins/stb-pwa/assets/)`.
- Browsers cache the service worker aggressively; after replacing assets, bump the version constant in `stb-pwa.php` and run `bin/wp.sh eval 'Stb_PWA::maybe_write_service_worker( true );'`.

## 8. Troubleshooting
- **DB connection errors**: run `docker compose -f docker/docker-compose.yml logs db` to inspect.
- **File permission issues**: ensure `wordpress/` folder is owned by your user; the PHP container runs as `www-data` but uses bind mount.
- **WP-CLI network issues**: remember `make wp`/`bin/wp.sh` automatically uses the `wpcli` container, so Docker must be running.

## 9. Playwright Smoke Tests
- Install dependencies once: `npm install` and `npx playwright install --with-deps`.
- Ensure the stack is running (`make up` or `bin/setup-wordpress.sh`).
- Run tests with `BASE_URL=http://localhost npm run test:e2e`.
- The suite now verifies Elementor dashboards render their key sections. If the templates were just updated, re-run `bin/wp.sh eval 'Stb_Elementor_Templates::force_sync( true );'` before executing Playwright.

## 10. Next Steps
- Add automated seeding scripts and sample JetEngine configs.
- Wire the staging stack into CI (GitHub Actions) for pull-request previews.
