# Local Staging Environment Plan

## Goals
- Provide a Dockerized WordPress stack mirroring production plugin/theme setup for development and QA.
- Support GitFlow workflow: feature branches run locally; `develop` branch syncs with staging DB/files.
- Keep Crocoblock/Elementor/FluentCRM assets outside repo (mounted via volumes) so licenses remain private.

## Strategy
1. **Docker Compose Stack**
   - Services: `wp` (PHP-FPM + nginx), `db` (MariaDB), `mailhog` for email inspection, `redis` for object caching.
   - Use bind-mounted `./wordpress` directory for WP core + wp-content under version control where appropriate (child theme, mu-plugins).
   - Provide `.env` file for DB creds, WP salts, and Crocoblock license placeholders.
2. **Bootstrap Script**
   - Makefile or `bin/setup.sh` to copy sample env, download WP core via WP-CLI, install plugins from shared assets, and run initial configuration (site URL, admin user).
3. **Plugin Integration**
   - Mount `/Users/mariusbelstad/Dev_folder/Wordpress Assets/` as read-only volume, then copy required ZIPs during setup.
   - Keep `wp-content/plugins/custom/` tracked for custom glue code; third-party plugins ignored via `.gitignore`.
4. **Database Handling**
   - Data stored in Docker volume `storbystand_db_data` with WP-CLI helpers for export/import.
   - Provide `bin/db-backup.sh` to dump before deployments per workflow requirements.
5. **Staging Parity**
   - Feature toggles (e.g., mock data vs production) controlled via WP options and `.env` flags (`APP_ENV=staging`).
   - Use sanitized mock data script to seed default locations/publishers for QA.

## Next Steps
- Create `.env.example`, `docker-compose.yml`, and `Makefile` scaffolding.
- Author setup scripts for WP core + plugin installation.
- Document start/stop commands and backup procedures in `docs/environment/local-staging.md`.
