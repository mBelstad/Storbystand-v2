# Risks, Limitations, and Mitigations

## Plugin & Platform Constraints
1. **JetEngine Calendar Scaling**
   - Risk: performance drops with >1k shift records per view.
   - Mitigation: use CCT queries with server-side date filters; paginate via week chunks.
2. **JetFormBuilder Complex Logic**
   - Risk: validation (capacity, availability conflicts) may need PHP hooks beyond UI-level conditions.
   - Mitigation: build shared helper functions inside a small custom plugin; include automated tests where possible.
3. **Automated Suggestions**
   - Risk: JetEngine alone cannot compute weighted recommendations.
   - Mitigation: custom REST endpoint executing WP_Query + bespoke scoring; cache results to avoid repeated heavy calculations.
4. **Audit Log Volume**
   - Risk: rapid growth of Audit CCT impacts query speed.
   - Mitigation: monthly archiving to external table/CSV; index timestamp + entity fields.
5. **FluentCRM Throughput**
   - Risk: FluentCRM (SMTP + push add-ons) may rate-limit bulk notifications or lack SMS support when needed.
   - Mitigation: abstract notification layer via queue, batch sends, and evaluate alternate providers before SMS launch.
6. **Notification Channels**
   - Risk: Push delivery (especially Safari/iOS) lags behind email rollout and browser opt-in friction reduces adoption.
   - Mitigation: keep email as primary channel, provide clear opt-in UX, queue push via `stb_notify_event`, and document unsupported devices.
7. **Elementor Responsiveness**
   - Risk: dense admin UI may be cumbersome on mobile.
   - Mitigation: dedicate responsive breakpoints, rely on drawers/modals, and test with real devices.
7. **Recurring Templates Accuracy**
   - Risk: daylight saving changes may offset time slots.
   - Mitigation: store times in UTC with timezone conversions at render, or run generator with PHP `DateTimeZone('Europe/Oslo')`.

## Custom Code Requirements
- Utility plugin `stb-core` housing:
  - Availability + capacity validators (`stb_can_join_shift`).
  - Audit logging helper + WP CLI commands for copy-day and template generation.
  - REST routes (`/suggestions`, `/copy-day`, `/audit`, `/stats`).
- `stb-pwa` plugin for manifest/service worker and push subscription endpoints.
- Theme snippets to expose dynamic token colors + slot definitions to Elementor via Global Variables.

## Operational Considerations
- Automated backups must cover both repository code and WordPress database before each deploy (GitHub CI/CD + DB snapshot); document restore drills.
- Cron reliability: prefer OS-level cron hitting `wp cron event run` to avoid missed template generation.
- Access control review to ensure contact list visibility complies with privacy rules; add opt-out options if required.

## Open Questions
1. When should audit/statistics retention policies be formalized?
2. Which SMS provider (if any) should integrate with the notification layer once the channel goes live, and is two-way messaging required?
3. Do stakeholders need additional notification intervals/escalations beyond the 24h/1h reminders?
