# JetEngine Data Model Blueprint

## Entity Overview
| Entity | Type | Description |
| --- | --- | --- |
| Locations | CPT | Physical ministry spots in Oslo; dynamic list managed by admins. |
| Shift Templates | CPT | Recurring patterns that generate daily shifts per location/time slot. |
| Shifts | CCT | Concrete shift occurrences with references to template, location, and date. Stored as CCT for faster grid queries. |
| Assignments | CCT | Link between a shift and the publisher(s) serving it. Tracks status + audit metadata. |
| Availability Windows | CCT | Publisher availability blocks (recurring) and full-day unavailability periods. |
| Publishers | User meta | WP users with `publisher` role, enriched via JetEngine Profile Builder fields. |
| Admin Notes | CCT | Internal notes per publisher/location for contextual info. |
| Audit Log | CCT | Append-only entries for join/leave/assignment actions. |
| Notification Queue | CCT | Pending or historical notification deliveries per channel/event. |
| Stats Snapshots | CPT | Periodic aggregates (per user, per location) for reporting and charts. |

## Field Definitions

### Locations (CPT)
- `slug` – system key used in queries.
- Meta:
  - `display_name` (text).
  - `address` (text).
  - `geo_lat`, `geo_lng` (numbers, optional for map views).
  - `contact_phone`, `contact_email`.
  - `color_token` (select from theme tokens for Elementor calendars).
  - `is_active` (switch).

### Shift Templates (CPT)
- `title` = Location + Slot label.
- Meta:
  - `location_ref` (relation to Locations).
  - `slot_id` (select: Morning/Afternoon/Evening/etc. – stored from global time-slot taxonomy or options page).
  - `weekday_mask` (checkbox list for recurring days).
  - `default_capacity` (number of publishers needed, defines fixed capacity for every downstream shift unless admin edits).
  - `start_time`, `end_time` (time fields matching slot definition for overrides).
  - `notification_lead_time` (hours before shift to ping publishers).
  - `auto_generate_horizon` (days ahead to spawn shifts).

### Shifts (CCT)
- Fields:
  - `shift_date` (date; indexed for calendar queries).
  - `location_id` (relation to Locations).
  - `template_id` (relation to Shift Templates).
  - `slot_id` (redundant for quick filtering).
  - `capacity` (number; defaults to template’s `default_capacity`, edits logged).
  - `status` (enum: draft, published, locked, cancelled).
  - `notes_public`, `notes_internal` (wysiwyg/text).
  - `generated_via` (enum: template, copy-day, manual) for audit context.
  - `copy_batch_id` (text) ties shifts generated together for rollback.

### Assignments (CCT)
- `shift_id` (relation to Shifts).
- `publisher_id` (relation to Users via JetEngine Relationship to WP Users).
- `state` (enum: requested, confirmed, completed, dropped, cancelled_by_admin).
- `joined_via` (enum: self-join, admin-assign, automation).
- `admin_override_flag` (switch) – set when admin overrides availability/unavailability to force assignment.
- `check_in_time`, `check_out_time` (time; optional future proofing).
- `points_weight` (number for load balancing algorithm).
- Audit meta: `created_by`, `created_at`, `last_actor`, `last_action` for quick history reference.

### Availability Windows (CCT)
- `publisher_id` (relation to Users).
- `mode` (enum: available, unavailable_full_day).
- `start_datetime`, `end_datetime`.
- `recurrence_rule` (text iCal-like for recurring availability; unavailability entries are assumed full-day and rendered as all-day events).
- `preferred_locations` (repeater/select referencing Locations) for weighting suggestions.
- `editable_after_end` (computed flag) – indicates entries remain editable even after the period passes.

### Publishers (User Meta via Profile Builder)
- Fields added to user profile:
  - `phone`, `alt_phone`.
  - `home_location` (relation to Locations).
  - `language_pref` (drives multilingual UI + notifications), `notes_public`, `notes_private`.
  - `max_shifts_per_week`, `max_shifts_per_month`.
  - `skills_tags` (taxonomy) for future suggestion logic.
  - Notification preferences (switches):
    - `notify_email_assignments`, `notify_email_reminders`.
    - `notify_push_assignments`, `notify_push_reminders`.
    - `notify_sms_assignments` (placeholder for future channel).
  - `preferred_notification_language` (defaults to `language_pref` but allows manual override).

### Admin Notes (CCT)
- `subject_type` (enum: publisher, location, shift).
- `subject_id` (dynamic field storing related entity ID).
- `note_body` (wysiwyg).
- `visibility` (enum: admin-only, shift-leads).
- `pinned` (boolean).

### Audit Log (CCT)
- `entity_type` + `entity_id` (shift, assignment, template, availability).
- `action` (join_request, join_approved, leave, reassigned, template_run, copy_day, status_change, notification_sent).
- `actor_user_id`.
- `actor_role`.
- `metadata_json` (textarea) for structured payload (before/after values, IP, device).
- `timestamp` (datetime, indexed).
- `channel` (optional) when action references notification events.

### Notification Queue (CCT)
- `entity_type` + `entity_id` for shift or assignment context.
- `recipient_id` (relation to Users).
- `channel` (enum: email, push, sms_future).
- `event_type` (assigned, removed, reminder_24h, reminder_1h, override_notice, bulk_broadcast, etc.).
- `deep_link` (text) for PWA target.
- `payload_json` storing localized content.
- `scheduled_at`, `processed_at`.
- `status` (queued, sending, sent, failed, cancelled) with `last_error`.
- `provider_message_id` (FluentCRM log reference).

### Stats Snapshots (CPT)
- `title` = period + scope (e.g., “2025-02 Publisher Totals”).
- Meta:
  - `scope_type` (enum: user, location, global).
  - `scope_ref` (ID when user/location scope).
  - `period_start`, `period_end`.
  - `total_shifts`, `total_hours`, `cancellations`, `late_cancels`, `substitutions`.
  - `generated_at` + `generator_version` to track calculation logic.

- Locations ← Shift Templates: one-to-many (JetEngine relation for template listing per location).
- Shift Templates → Shifts: template reference stored; generation cron writes relation for traceability.
- Locations ← Shifts: explicit relation enabling location filters and map displays.
- Shifts ← Assignments: parent-child relation powering repeater of assigned publishers.
- Users ← Assignments: relation for “My Shifts” listings and load statistics.
- Users ← Availability Windows: relation for runtime availability checks plus calendar overlays (availability + unavailability feeds).
- Shifts ← Audit Log + Assignments ← Audit Log: use dynamic field storing entity_type/id to keep single log table while referencing via custom Query Builder filters.
- Locations/Users ← Admin Notes: dynamic relation using two meta fields (type + ID) to minimize extra tables.
- Assignments/Users ← Notification Queue: enables per-entity notification history, retry workflows, and admin dashboards.

## Storage & Performance Notes
- Use CCTs for high-churn objects (Shifts, Assignments, Availability, Audit) to leverage JetEngine’s custom tables and REST endpoint performance.
- CPTs suffice for lower-volume, editor-friendly items (Locations, Templates, Stats Snapshots).
- Ensure indexes on `shift_date`, `slot_id`, `publisher_id`, and `timestamp` via JetEngine CCT settings for faster calendar queries and reporting.
- Default meta values set through JetEngine to keep Elementor templates simple (e.g., status defaults to `draft`, capacity from template).

## Visibility & Access Rules
- Publishers can see only their Assignments plus open Shifts with capacity remaining; JetEngine dynamic visibility conditions tied to user role and assignment state.
- Admins gain full CRUD through JetEngine admin pages + Elementor front-end forms guarded by role checks.
- Locations marked inactive remain in historical data but hidden from public selectors via query filters.
