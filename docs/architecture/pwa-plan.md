# PWA Readiness Plan

## Goals
- Deliver installable experience on modern browsers without disrupting current WP theme.
- Enable push notifications (future phase) while providing offline-friendly caches for critical data.

## Technical Stack
- Use `SuperPWA` or lightweight custom plugin to register manifest + service worker; extend via custom code for Crocoblock data caching.
- Manifest generated dynamically to include location-specific icons and brand colors.
- Service worker built with Workbox (in custom plugin) to control caching strategies.

## Caching Strategy
| Asset Type | Strategy | Notes |
| --- | --- | --- |
| Static assets (CSS/JS/images) | `StaleWhileRevalidate` | Works with Elementor compiled assets; version via file hash. |
| API responses (JetEngine REST endpoints) | `NetworkFirst` with fallback cache | Cache key includes endpoint + query args; short TTL (5m) to avoid stale schedules. |
| HTML shell (dashboard pages) | `NetworkFirst` with offline fallback page | Provide basic offline notice + last synced data snapshot list. |
| Contacts directory | `CacheFirst` once downloaded due to low churn. |

## Offline Data Snapshot
- During online session, service worker stores recent `my_upcoming_shifts`, `open_shifts_available`, `availability_matrix`, full-day unavailability entries, and contact list payloads in IndexedDB.
- When offline, dashboard reads from IndexedDB to display last-known schedule with clear “Offline – data from HH:MM” banner and notifies the user if admin overrides are pending review.

## Push Notification Roadmap
1. Implement WP REST endpoint to register push subscription tokens tied to user ID (stored in user meta) alongside email preference tracking; prompt users for browser-level opt-in (per device) via service worker registration flow.
2. Use Web Push (VAPID) via server-side PHP or Node microservice to send:
   - Assignment confirmations/changes/cancellations (mirrors email notifications).
   - Reminder 24h before shift.
   - Reminder 1h before shift.
   - Late cancellation alerts, overload warnings, and unassigned-shift alerts for admins.
   - Unavailability confirmation receipts to publishers.
3. Integrate with existing notification hook `stb_notify_event` so future channels stay decoupled and payloads list the channel mix (email + push).
4. Maintain deep-link paths in push payloads so installed PWA opens to specific shift detail or availability pages.

## Authentication & Security
- Service worker scopes limited to `/app/` namespace where dashboards live.
- Use nonce-aware REST calls; tokens refreshed upon login to avoid cache poisoning.
- Subscription endpoint validates capability and CSRF tokens.
- Respect per-channel notification preferences before enqueuing push payloads; opt-out state cached client-side to avoid redundant prompts.

## Deployment Steps
1. Build custom plugin `stb-pwa` registering manifest + Workbox build and hook it into GitFlow CI/CD so staging validates before production.
2. Add Elementor HTML widget injecting `navigator.serviceWorker.register('/app-sw.js')` with feature detection.
3. Provide settings page for icon uploads, theme color, offline message copy.
4. Document cache-busting process (bump service worker version constant) and ensure automated backups (code + DB) run prior to deployment rollouts.

## Testing Checklist
- Lighthouse PWA audit (score ≥ 90) on dashboard page.
- Offline test: load cached data, ensure join/leave buttons disabled with explanatory tooltip.
- Push test: send manual payload via WP-CLI command to confirm device receives event.

## Future Enhancements
- Background sync for queued actions (e.g., offline availability submissions) using Workbox Background Sync plugin.
- Add install banners tailored per role, with analytics tracking (Google Analytics event) for adoption.
