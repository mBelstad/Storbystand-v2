# Statistics & Reporting Model

## KPIs
- **Per-user totals (dashboard focus)**: shifts served, total hours, cancellations, substitution fills, late cancels, override count, weekly load vs limit.
- **Alert indicators**: unassigned shifts inside alert window, overload warnings, late cancellation spikes.
- **Notification health**: send volume by channel, failure/retry counts, open/click (deep-link) rates, reminder completion.
- **Per-location utilization**: capacity vs filled per day/week/month.
- **Time-period summaries**: total shifts created, completion rate, open slots, copy-day batches.

## Data Sources
1. **Assignments CCT** – canonical record for shift participation.
2. **Shifts CCT** – provides capacity, status, location, slot metadata.
3. **Stats Snapshots CPT** – stores aggregated results for quick retrieval and historical comparison.
4. **Audit Log CCT** – used for tracing late changes and compliance reporting.

## Aggregation Strategy
- Nightly scheduled job calculates per-user and per-location stats for previous day + rolling periods (week, month).
- Job writes/updates Stats Snapshot posts keyed by period + scope.
- Real-time widgets (dashboard KPIs) pull from live Queries when dataset is small (e.g., “today’s open slots”).

### Per-User Metrics
- `total_shifts_period`: count Assignments with `state = completed` between `period_start/end`.
- `total_hours_period`: sum `(shift.end - shift.start)` per completed assignment.
- `cancellations_period`: count Assignments moved to `dropped` or `cancelled_by_admin` within period.
- `late_cancellations`: cancellations occurring inside 24h window (calculated using shift datetime vs audit timestamp).
- `substitutions`: cases where publisher joined via `joined_via = automation` after another dropped.
- `override_count`: number of assignments with `admin_override_flag = true`.
- `load_score`: weighting formula using `points_weight` and max weekly/monthly thresholds to highlight overload warnings.

### Per-Location Metrics
- `shifts_planned`: count Shifts (status != cancelled) per location.
- `shifts_filled`: number with assignment count >= capacity.
- `attendance_rate`: completed assignments / capacity.
- `late_cancellations`: flagged via Assignments + Audit.
- `copy_batch_dependency`: link to copy batch ID for trend of repeated templates.

### Period Summaries
- `total_publishers_active` (distinct user IDs serving shifts).
- `average_capacity_filled` across all locations.
- `automation_usage` (# of assignments created via suggestion or copy-day autopopulate).

### Notification Metrics
- `notifications_sent_channel`: count Notification Queue entries by channel/status.
- `notifications_failed`: subset flagged failed/retrying.
- `reminders_sent_24h/1h`: counts to verify scheduler coverage.
- `deep_link_clicks`: proxied via JetEngine endpoint or Google Analytics events.
- `preference_opt_outs`: number of users disabling reminders per channel.

## Visualization Plan
- JetEngine Charts Module (or JetElements Charts) referencing Stats Snapshots for fast load.
- Dashboard emphasises per-user widgets plus alert badges (unassigned shifts, overload warnings) fed by live queries.
- Notification console uses Listing Grid on Notification Queue with channel/status filters and KPI counters.
- For ad-hoc filtering, use Query Builder with dynamic arguments (date range selectors) feeding Listing grids.
- Provide CSV export per widget via JetEngine Exporter.

## Performance Considerations
- CCT indexes on `shift_date`, `publisher_id` ensure nightly job scans remain efficient.
- Stats Snapshot CPT keeps only aggregates; raw data stays in CCT tables.
- Introduce retention policy (e.g., keep snapshots for 24 months) configurable via WP option.

## API Exposure
- REST endpoint `GET /storbystand/v1/stats?scope=user&scope_id=123&period=2025-02` returning snapshot payload for PWA charts.
- Security: capability `view_stats`. Publishers can request only their own stats.

## Notifications & Thresholds
- When load imbalance detected (user exceeding max weekly limit), create admin notice, highlight dashboard alert, and include in daily email + push queue for admins.
- Late cancellation count triggers follow-up tasks recorded via FluentCRM automation and optional push to coordinators.
