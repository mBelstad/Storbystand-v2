# Elementor Dashboard Wireframes

## Global System UI Decisions
- Theme: clean, high-contrast palette with location-specific accent colors pulled from Location meta.
- Header: app logo, role-aware navigation (Dashboard, Calendar, Contacts, Availability, Admin tools).
- Persistent PWA prompt (install banner) presented via custom Elementor HTML widget when supported.
- All key sections powered by JetEngine Listings with dynamic visibility conditions.

## Publisher Dashboard
1. **Hero Strip**
   - Left: greeting, next shift summary card (location badge, countdown).
   - Right: quick actions (Submit availability, View calendar, Contact team) as icon buttons.
2. **Upcoming Shifts Carousel**
   - Listing grid fed by `my_upcoming_shifts` query.
   - Card contents: date pill, slot label, location chip, status badge, leave button (conditional), iCal add, override icon if admin forced assignment.
3. **Calendar + Availability/Unavailability Overlay**
   - JetEngine Calendar widget showing shifts; user assignments highlighted, open shifts muted.
   - Layered overlay for recurring availability blocks plus full-day unavailability bars (vacations) sourced from Availability CCT.
   - Sidebar toggles to show/hide each layer; clicking day opens modal with joinable shifts + leave actions.
4. **Availability & Unavailability Panel**
   - Two tabs: “Availability preferences” (recurring form) and “Unavailability” (full-day range form with history table).
   - JetFormBuilder forms embedded inline; sticky CTA “Save changes” and “Save vacation”.
   - History lists allow editing/removing past entries with timestamp badges.
5. **Notification Preferences Card**
   - Compact widget with toggles for email/push reminders and informational updates.
   - Language selector (NO/EN) plus SMS placeholder explaining coming soon.
   - Deep-link preview showing how notifications open specific dashboard routes.
6. **Open Shifts List**
   - Filter chips (Location, Slot, Date range) backed by Query Controls.
   - Each row shows capacity bar, location contact quick link, join button.
6. **Contacts Directory**
   - Table view with search by name/location; includes call/email icons.
   - Optional accordion per location for mobile readability.
7. **Notifications & PWA Section**
   - Card describing benefits of enabling push + email status indicator (connected/needs setup); button hooking into future service worker registration.
   - Reminder summary (next 24h/1h reminders scheduled) so publishers know when to expect pings.

### Publisher Mobile Considerations
- Stack hero + carousel vertically.
- Calendar switches to agenda list on <768px.
- Contacts collapse into accordions; join/leave buttons full width.

## Admin Dashboard
1. **Header Summary Bar**
   - KPI cards (Open slots today, Pending joins, Late cancellations, Suggested matches pending, Override count).
   - Alert badges highlight unassigned shifts inside next 72h and overload warnings (publishers over weekly limit).
   - Notification KPI showing queued messages + failures.
   - Quick link buttons: “Create shift”, “Copy previous day”, “Run suggestions”, “Send broadcast”.
2. **Shift Control Center**
   - Two-column layout: left calendar/week view, right contextual panel.
   - Selecting a shift populates details: assignments list with status pills, override badges where availability was bypassed, suggestion list (top 5) with “Assign” button, audit snippet, notification status chips (sent/queued/failed) for that shift.
   - Tabs for “Details”, “Notes”, “History”, “Unavailability”, “Notifications”.
3. **Copy-Day & Template Panel**
   - Elementor toggle to show JetFormBuilder forms for copy action and template creation.
   - Card explains last copy batch with undo CTA when available.
4. **Publisher Inspector Drawer**
   - Slide-out triggered by clicking publisher anywhere.
   - Sections: profile summary, availability heatmap, load stats chart, past 5 shifts, admin notes (editable list).
5. **Statistics Widgets**
   - Grid of charts emphasizing per-user metrics: load ranking table, cancellations heatmap, hours trend, notification responsiveness (opens vs sent).
   - Secondary tabs for per-location utilization and monthly totals.
   - Filters for date range + location.
6. **Notification Console**
   - Table/listing backed by Notification Queue showing recent messages, channel, status, retry buttons.
   - Bulk broadcast form (JetFormBuilder) accessible here with filters (location/date) and preview of localized content.
   - Deep-link tester to copy preview URL.
7. **Contacts & User Management**
   - Table of users with action column (Edit, Disable, Reset password link).
   - Inline badges for max weekly limit, preferred locations, warning icons for repeated cancellations.
8. **Audit Timeline**
   - Vertical timeline widget showing latest actions with actor avatar, entity link, label (e.g., “Anna joined Karl Johan Morning”).
   - Filter chips for action type and location.

### Admin Mobile/Tablet Notes
- Break layout into stacked sections with sticky filters.
- Use accordions for KPI + shift details to avoid scroll fatigue.
- Drawer becomes modal on mobile.

## Reusable Components
- **Location Chip**: dynamic tag showing color + name; used across cards.
- **Status Badge**: JetEngine macro mapping shift/assignment status to consistent token.
- **Capacity Bar**: progress bar widget fed by assignment counts.
- **Action Modals**: JetPopup + JetFormBuilder combos for join/leave, admin assign, copy confirmations.

## Dynamic Visibility & Permissions
- Publisher dashboard hides admin-only cards; admin dashboard hides join/leave CTAs by default.
- JetEngine macros check role/capability before rendering forms.
- Sensitive data (contact info) shown to all logged-in per requirement, but edit actions restricted to admins via conditional logic.

## Wireframe Artifacts (Next Steps)
- Optional: export Figma references or Elementor Template Kits after stakeholder review.
- Document mapping of each section to Elementor templates/listings for future developers.
