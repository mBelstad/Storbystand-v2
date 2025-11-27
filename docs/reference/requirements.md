# Stand Shift Manager – Requirements Digest

## Context
- Project rebuilds the Oslo stand-shift tool using WordPress, Elementor Pro, Crocoblock, and optional FluentCRM.
- UX must stay browser-first yet prep for a future PWA with push notifications.
- Reference implementation: https://storstadsprojektet.se/oslo (credentials supplied separately) – analyze to confirm flow but improve on scalability and automation limits.

## Shared Functional Needs
- Custom front-end authentication (no wp-login.php exposure) with role-tailored dashboards.
- Dynamic location catalog: admins can add, retire, or rename locations beyond the initial five.
- Shifts rely on fixed pattern time slots (e.g., Morning, Afternoon, Evening) with fixed capacity per template/location; individual shifts inherit that number.
- Full contact directory (phone + email) visible to every authenticated user.
- Recurring shift templates pre-generate schedules; admins can copy a day’s setup forward.
- Automated assignment suggestions based on publisher availability, history, and load-balancing rules (no extra attributes like gender/seniority).
- Durable audit log for all shift changes (join/leave, admin assignments, template executions) accessible to admins.
- Per-user statistics (e.g., shifts served, hours, cancellations) highlighted on admin dashboard alongside alert indicators (unassigned shifts, overload warnings).
- Notification provider: FluentCRM handles outbound email (SMTP) and push (via add-ons/hooks) while architecture remains channel-agnostic to plug in SMS with minimal rework.
- Notification rules: trigger email + push for all shift events (assignment, removal, status changes, reminders 24h & 1h before start, admin overrides, cancellations, late changes) with deep-links into the front-end/PWA.

## Publisher Experience
- View assigned shifts in list and calendar views with location color-coding.
- Explore all open shifts, highlighting eligibility (availability match, conflicts) and join/leave actions.
- Submit availability windows with recurring preferences, plus record full-day unavailability periods (vacations, etc.) that apply instantly, appear on calendars, and can be edited/removed even after the date has passed.
- Access global contact list filtered by location/ministry.
- Manage per-channel notification preferences (email/push today, SMS later) to customize reminders while always receiving critical assignment updates.
- Receive multilingual (NO/EN) notifications based on profile language, each containing deep-links to the relevant dashboard section.

## Admin Experience
- All publisher functionality plus:
  - Assign shifts manually or via automated recommendations, with permission to override user availability/unavailability when necessary (action logged for audit).
  - Review each publisher’s historical load, recent availability, conflicts, and alert badges before assignment.
  - Copy previous day’s layout, with options to tweak time slots or locations before publishing.
  - CRUD for locations, shift templates, and users (publishers/admins) with role-specific fields.
  - Statistics console emphasizing per-user totals (load, cancellations, hours) plus configurable alerts (unassigned shifts, overload warnings); per-location/per-period breakdowns available via reports.
  - Audit timeline with filters (location, user, action type) and export.
  - Bulk notification tools (via FluentCRM) to message all publishers for a location/day, respecting each user’s channel preferences and logging delivery status.
  - Notification log view summarizing recent outbound events, success/failure per channel, queued reminders, and exception handling.

## Non-Functional & Technical
- Architecture uses JetEngine CPT/CCT for structured data; Elementor dynamic templates deliver UI.
- Queries must support responsive dashboards, calendars, repeaters, alerts, conditional visibility, and notification preference lookups without excessive PHP customizations.
- Prepare service worker + manifest hooks for eventual PWA release; ensure REST endpoints and data caches can serve offline snapshots, push notifications, and preference checks. Notifications must include deep-links compatible with the PWA routing model.
- Logging and automation should favor Crocoblock hooks/JetEngine API first, then fall back to lightweight custom plugins.
- Development workflow: new GitHub repo using GitFlow (main, develop, feature/release/hotfix branches), semantic commits, PR reviews, and clear versioning.
- Local-first development with full Docker-based staging mirror; all features tested locally before merge.
- Automated CI/CD deploys from GitHub → staging → production, with mock data on staging (no sensitive info).
- Automatic backups (code + WordPress DB) taken before every deployment for time-travel recovery.

## Open Decisions / Follow-Ups
1. Timeline and policy for audit/statistics data retention.
2. SMS provider/flow (once channel is enabled) and whether two-way messaging is required.
3. Additional notification intervals or escalation rules beyond 24h/1h reminders.
