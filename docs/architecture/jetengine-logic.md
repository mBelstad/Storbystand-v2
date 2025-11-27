# JetEngine Logic, Queries, and Automation

## Query Builder Blueprints

### Publisher Dashboard Queries
1. **`my_upcoming_shifts`**
   - Source: Assignments CCT.
   - Filters: `publisher_id = current_user`, `state in (confirmed, requested)`, `shift.shift_date >= today`.
   - Join: include related Shift + Location fields for cards.
   - Ordering: `shift_date` ASC, `slot_id` ASC.
   - Usage: Elementor listing for “Next shifts” panel.
2. **`open_shifts_available`**
   - Source: Shifts CCT with capacity > confirmed assignments.
   - Dynamic filter to exclude shifts where Assignment exists for current user OR availability conflict.
   - Meta query hooking into custom callback checking `Availability Windows` overlaps.
   - Output: used in join modal and calendar overlays.
3. **`calendar_feed`**
   - Source: Shifts CCT.
   - Fields: `shift_date`, `slot_id`, `location.color_token`, `assignment_count`, `capacity`, `status`.
   - Format via Listing Grid to power JetEngine Calendar and ICS export, with overlay query pulling Availability Windows (availability + full-day unavailability) for contextual rendering.

### Admin Queries
1. **`shift_roster_manage`**
   - Source: Shifts CCT.
   - Filtering UI: date range, location, slot, status.
   - Aggregations: assignment counts, open slots, conflict flags.
   - Provides repeater for inline assignment widgets.
2. **`publisher_load_history`**
   - Source: Assignments CCT aggregated by week/month.
   - Calculated columns: total hours, cancellations, substitution count, override count (from `admin_override_flag`).
   - Used in admin sidebar when selecting a publisher.
3. **`availability_matrix`**
   - Source: Availability Windows CCT.
   - Derived dataset: converts recurring availability rules into discrete slots for next 8 weeks via custom PHP callback tied to Query results filter; full-day unavailability entries surface as all-day blocks with edit links.
4. **`audit_log_recent`**
   - Source: Audit Log CCT.
   - Filters: `timestamp` range, entity references.
   - Output: timeline repeater with actor avatars.
5. **`alerts_unassigned_shifts`**
   - Source: Shifts CCT.
   - Filters: `status = published`, `assignment_count < capacity`, optionally `shift_date <= today + 3`.
   - Drives dashboard alert badges and notifications.
6. **`notification_queue_admin`**
   - Source: Notification Queue CCT.
   - Filters: channel, status, event type, date range.
   - Powers admin log view and retry controls.

### Automation Helpers
- **Copy-day pipeline**: Query previous day’s Shifts filtered by location/time slot, duplicate into new date via WP CLI command (or JetEngine `Forms on Submit > Insert CCT` hook).
- **Suggestion engine dataset**: Use Custom Query that joins Assignments + Availability to compute score = `availability match - recent load + preference boost`. Expose via REST endpoint for admin UI.

## JetFormBuilder Flows

| Form | Purpose | Key Fields | Actions |
| --- | --- | --- | --- |
| `publisher_join_shift` | Let publishers request an open shift. | Hidden `shift_id`, user ID, optional note. | Validate capacity, insert Assignment in `requested` state, log audit entry, notify admins. |
| `publisher_leave_shift` | Allow leave/cancel. | Hidden `assignment_id`, reason select. | Update Assignment state, free slot, log audit, trigger notification workflow. |
| `availability_submit` | Capture availability/unavailability. | Mode, date range, recurrence (availability only), preferred locations. | Inserts/updates CCT row, recalculates suggestion cache. |
| `unavailability_full_day` | Record vacations/off limits. | Date range (full-day), reason text. | Inserts CCT row flagged as full-day, immediately blocks assignments, shows calendar entry. |
| `admin_assign_shift` | Admin directly assigns publisher(s). | Shift pick, multi-user selector, priority weight. | Creates/updates Assignments as `confirmed`, triggers confirmation emails + audit log entries. |
| `shift_template_builder` | Manage recurring templates. | Location, slot, weekdays, capacity, horizon. | Creates CPT entry, optionally run generator now. |
| `copy_day_action` | Copy previous day schedule. | Source date, target date, include status toggles. | Calls custom function to clone Shifts, link `copy_batch_id`, produce audit log, show summary. |
| `audit_log_note` | Manual admin note entry. | Subject type/id, note body, visibility. | Inserts Admin Note CCT + audit record. |
| `notification_preferences` | Allow users to set per-channel opt-ins. | Email/push reminder toggles, language override. | Updates user meta, recalculates reminder queue. |
| `bulk_notification_broadcast` | Admin blast to selected audience (location/slot/date). | Filters + rich text body + channel selection. | Creates Notification Queue entries respecting preferences, logs summary. |

All forms leverage JetFormBuilder’s Actions:
- Insert/Update Post/Term/CCT.
- Call custom PHP hook (`jet-form-builder/form-handler/after-send`) for audit writes, notifications (email + push queue), suggestion cache refresh.
- Dynamic visibility ensures publishers only see join/leave buttons when eligible.

## Automation & Hooks
- **Cron: Template Generator**
  - Runs nightly via WP Cron or server cron.
  - Reads `Shift Templates` with `auto_generate_horizon`, ensures future `Shifts` exist up to horizon, skipping locked days.
  - Logs `template_run` in Audit CCT.
- **Cron: Suggestion Cache Builder**
  - Aggregates per-shift recommendation lists (top 5 publishers) stored in transient/CCT for quick admin UI rendering.
  - Scoring inputs: availability overlap, preferred locations, recent load (Assignments past 30/90 days), max weekly limit, skill tags.
- **Copy Batch Rollback**
  - Custom action on audit entry: if admin rolls back a copy, hook discovers all Shifts matching `copy_batch_id` and deletes/downgrades assignments accordingly.
- **Availability Conflict Guard**
  - `jet-engine/listings/dynamic-visibility` filter ensures join button disabled when publisher has overlapping Assignment or unavailable block (full-day). Admin overrides toggle `admin_override_flag` and annotate audit log.
- **Audit Logging Helper**
  - Reusable function triggered from JetFormBuilder hooks and custom REST endpoints; accepts entity, action, metadata payload, writes to Audit CCT.
- **Notification Service Layer**
  - Wrapper around FluentCRM API for email + push dispatch; exposes `stb_notify( $event_type, $entity_id, $payload )`.
  - Builds Notification Queue rows, enforces per-user preferences, localizes content (NO/EN), and queues Workbox push payloads.
  - Abstracted channel registry so SMS provider can be wired later without refactoring call sites.
- **Reminder Scheduler**
  - Cron job runs hourly to enqueue reminders 24h and 1h before shift start, skipping users who opted out of reminder channel.
- **Bulk Broadcast Processor**
  - Processes JetFormBuilder submissions, resolves audience filters, and batches queue inserts to avoid rate limits (with chunked WP CLI fallback).

## Access Control & REST Endpoints
- Use JetEngine Profile Builder restrictions + Elementor visibility.
- Custom REST routes (namespaced `storbystand/v1`) for:
  - `GET /suggestions/{shift_id}` – returns ranked publishers.
  - `POST /copy-day` – invoked by JetFormBuilder action to run server-side logic.
  - `GET /audit/{entity}` – paginated results for admin timeline.
  - All routes gated by nonce + capability checks.

## Data Integrity Rules
- Assignment creation enforces capacity by counting `state in (confirmed, requested)` before insert.
- Leaving shift moves assignment to `dropped`; if within lock window (e.g., <24h), flag for admin review + optional penalty.
- Recurring availability updates replace existing rule set for that user to avoid duplicates.
- Shifts marked `locked` prevent further self-joins; only admins can override via dedicated form.

## Copy-Day Routine Detail
1. Form collects source date, target date, included locations, status behavior.
2. Custom handler queries Shifts on source date grouped by slot.
3. For each shift:
   - Duplicate row with new date, maintain template + slot references.
   - Copy confirmed assignments optionally (config toggle) and set state to `confirmed` or `pending`.
   - Write shared `copy_batch_id` for rollback trace.
4. Summaries displayed to admin and logged.

## Availability Overlap Check
- Utilize JetEngine dynamic callbacks: when rendering join button, run PHP helper `stb_can_join_shift( $user_id, $shift_id )`.
- Helper checks:
  - Existing Assignment same time.
  - Availability Windows flagged `unavailable` overlapping shift range.
  - Max shifts per week/month thresholds.
  - If pass, return true; else supply reason string for tooltip.

## Notification Hooks (Current + Future)
- On assignment changes (create/update/cancel/override), trigger FluentCRM email immediately, enqueue push payload (via service worker), and store Notification Queue row with status tracking.
- Reminder scheduler enqueues messages 24h and 1h before start time; queue processor respects user opt-outs.
- Bulk broadcasts use the same queue, tagging entries as `bulk_broadcast` for reporting.
- Deep-links included in every payload via helper generating route-safe URLs for the PWA shell.
- Provide action hooks `do_action( 'stb_notify_event', $type, $payload )` so future SMS service can subscribe; payload includes channels + localization metadata.

## Data Export & Sync
- JetEngine REST listing endpoints feed ICS export + optional Google Calendar sync.
- Admin can export CSV of assignments filtered by date/location using Query Builder + JetEngine Export module.
- Future external API not required; REST routes remain internal/authenticated.
