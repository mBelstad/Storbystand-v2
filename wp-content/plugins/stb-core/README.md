# Storbystand Core Plugin

This plugin bootstraps the foundational data layer required by the Storbystand shift-management project. It lives inside the repository (mounted into the WordPress container) so that all custom functionality ships with the codebase.

## Features

- **Custom Post Types**
  - `stb_location`: locations with geo/contact metadata.
  - `stb_shift_template`: recurring shift blueprints (slot, capacity, lead times).
  - `stb_stats_snapshot`: aggregated KPI snapshots for reporting.
- **Custom Taxonomy**
  - `stb_skill`: reusable skill tags for publishers/locations/templates.
- **JetEngine CCT bootstrap**
  - PHP definitions sync JetEngine CCTs for shifts, assignments, availability, admin notes, audit log, and notification queue.
- **Elementor Templates**
  - Publisher and Admin dashboard templates are provisioned automatically with shortcode-based JetEngine listings.
- **WP-CLI Support**
  - `wp stb jetengine sync` replays the JetEngine definition installer.
  - `wp stb seed mock` truncates and populates the JetEngine tables with deterministic demo data for local testing.
  - `wp stb notifications queue --event=<slug> --recipient=<id>` enqueues ad-hoc notification events for smoke-testing.
  - `wp stb notifications run [--limit=<n>]` processes the pending notification queue immediately (otherwise a 5-minute cron takes care of it).
  - `wp stb stats run [--period=day|week|month] [--date=YYYY-MM-DD]` forces the stats snapshot generator for the requested window.
- **Notification Service**
  - Centralized dispatcher writes to the JetEngine-backed queue, respects per-user channel preferences, and uses FluentCRM’s mailer by default.
  - Automatic cron schedule (`stb_five_minutes`) drains queued messages and reports failures back onto each queue row.
  - Emails are simulated locally by default (`STB_SIMULATE_EMAILS=true`). Define the constant as `false` in `wp-config.php` when you are ready to hit real transports.

## Development Notes

1. The plugin is automatically mounted inside the Docker containers via `docker/docker-compose.yml`.
2. Activation runs the JetEngine sync routine so CCTs/tables exist without manual clicking.
3. Future phases will extend this plugin with JetFormBuilder hooks, notification dispatchers, and REST endpoints.
