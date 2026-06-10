# Changelog

All notable changes to GPRO Pitwall are documented here.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and the project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).
Each entry mirrors its annotated release tag.

## [1.2.8] - 2026-06-10
- **Security:** Login no longer leaks whether a username exists. An unknown username now produces a decoy pending state that routes to `/verify` identically to a real account (and can never verify), closing the redirect-based enumeration oracle — the response body was already generic.
- **Security:** The Control Panel never sends the decrypted GPRO API token to the browser. It shows a masked last-4 hint with an empty replace field (blank submit = unchanged); the token is also stripped from the shared Twig `user` global as defence-in-depth.
- **Security:** Filesystem cache deserializes with `allowed_classes => false`, so a tampered/poisoned cache file degrades to a miss instead of a potential PHP object-injection gadget.
- **Security:** Outbound GPRO API calls now set connect + total curl timeouts (5s/15s for v2 endpoints, 5s/30s for the market dump) so a hung upstream can't pin a PHP worker indefinitely.
- Performance: per-request DB migration is gated on SQLite's `user_version` — a warm database skips the full DDL + table-info scans + legacy-token re-encryption pass and does a single PRAGMA read instead.
- Fix: `StrategyService` no longer raises an "Undefined array key id" warning when fed the next-race TrackProfile (which carries no id); it falls back to a name match.

## [1.2.7] - 2026-06-10
- UX/UI consistency pass across every template. New shared `.notice`/`.notice-{info,warn,error,success}` left-accent banner classes replace the ad-hoc flash/error styles on the auth pages, admin pages, and all analysis tabs; raw-utility buttons converted to the `.btn` component classes (plus new `.btn-soft-warn`/`.btn-soft-danger` variants for Undo/Clear); headings aligned to the `t-*` type tokens; yellow/green badge palettes unified to amber/emerald.
- Fix: the landing page now renders flash messages — the "account deleted" confirmation was silently dropped before.
- Fix: define the `fade-in` animation referenced by the tab container (was a no-op class), with `prefers-reduced-motion` respected.
- Recruitment Analyzer pagination: windowed page list (1 … current±2 … last) instead of one link per page, and visible Previous/Next controls on mobile (there were none below the `sm` breakpoint).
- Race Strategy: compact sidebar — driver/car/staff/TD inputs are now aligned label-left rows matching Race Settings, native number spinners removed app-wide, narrower numeric boxes; Car Setup table tightened (fixed session-column widths, denser rows) with the "enter 999" note folded into the card footer.
- Testing tab: restructured into two flowing columns so cards stack flush — no more vertical gap between Track and Points Gained per 5 Laps.
- Mobile nav select now uses the shared `.form-select` style; external GPRO links gained `rel="noopener noreferrer"`.

## [1.2.6] - 2026-06-09
- Bump CI actions off the deprecated Node.js 20 runtime: `actions/checkout@v4` → `v6`, `actions/cache@v4` → `v5`. GitHub forces Node 24 on these actions from 2026-06-16; this clears the deprecation warning ahead of that. No app or runtime behaviour change.

## [1.2.5] - 2026-06-09
- Add **value range filters** to the Recruitment Analyzer: set inclusive minimum and/or maximum bounds per driver attribute (leave either blank for a one-sided range). Filters validate server-side, persist across sorting and pagination, and the result header shows the filtered count against the unfiltered total. (#42, thanks @HelderfV)

## [1.2.4] - 2026-06-09
- Refine the Testing-tab car-wear `TESTING_WEAR_FACTOR` from 0.5 to **0.53**. A second real session (100 laps) independently best-fit 0.533, matching the original 30-lap calibration; the previous 0.5 ran ~6% low (the risky direction for wear). Totals now land near-exact.

## [1.2.3] - 2026-06-09
- Mark the Testing tab's Expected Car Wear card as **Experimental** with a light-blue pill and a short note that the estimates are still being refined and will improve.

## [1.2.2] - 2026-06-09
- Fix Testing-tab car-wear projection reading ~2× too high. Testing laps wear the car at roughly half the per-lap rate of a race at the same track; `CarWearService::testingWearRates()` now applies a `TESTING_WEAR_FACTOR` (0.5), calibrated against a real 30-lap session where observed wear came in at a uniform ~0.53× of the unscaled estimate across all 11 parts. (Part level was ruled out: its wear factors span only ~1.6% end-to-end, far too small to explain the gap, and the game's model applies level only as a clear-track-risk modifier.)

## [1.2.1] - 2026-06-09
- Fix the Testing tab not refreshing on re-sync: `GproSyncService` now force-warms the `GetTesting` feed alongside the other race-prep endpoints, so a manual sync updates the testing track, points, and setup instead of serving a stale cache entry until TTL.

## [1.2.0] - 2026-06-09
- New **Testing** tab: shows the current testing track and its demands, the car's points distribution across Test / R&D / Engineering / **Car Character** (highlighted), the points gained per 5 laps for each testing priority, the ideal setup for the testing track (Front/Rear Wing, Engine, Brakes, Gearbox, Suspension), and a slider-driven (5–100 laps) projection of expected car wear. Backed by the GPRO `GetTesting` feed; reuses the Race Strategy setup engine and the car-wear model.

## [1.1.31] - 2026-06-09
- Fix the track selector defaulting to "Buenos Aires" (first in the config list) on every page. With no explicit `track` in the URL it now defaults to the user's actual next-race track from the cached Office data, falling back to the first known track only pre-first-sync.

## [1.1.30] - 2026-06-09
- Styled error pages (403/404/500) replacing the bare-text responses. The auth gate and router now throw a typed `HttpException` that the front controller renders through the normal layout; the 500 handler still hides internals behind a reference id.

## [1.1.29] - 2026-06-09
- **Security:** stop interpolating the username into the admin restore form's inline `confirm()`; read it from a `data-` attribute so a legacy username containing quotes can't break out and execute in an admin session.

## [1.1.28] - 2026-06-09
- Fix Debug → Database "Size: Not Found": resolve the SQLite path to an absolute path (single source of truth in `Database::path()`) so `filesize()` no longer depends on the process CWD.

## [1.1.27] - 2026-06-09
- **Security:** server-side username whitelist (`[A-Za-z0-9_]`) at registration, mirroring the form's client pattern — attacker-controlled markup can't reach storage.

## [1.1.26] - 2026-06-09
- Login form remembers the last username on that browser (client-side, no server state).
- Greet the signed-in user with "Hello, <username>" beside Last sync in the header.

## [1.1.25] - 2026-06-09
- Add this CHANGELOG, backfilled from the release tags.

## [1.1.24] - 2026-06-09
- Race-window cache keys for race-critical GPRO data.

## [1.1.23] - 2026-06-09
- Render timestamps in the visitor's local timezone.

## [1.1.22] - 2026-06-09
- Cap verification emails per account, add captcha to login, and a resend link.

## [1.1.21] - 2026-06-08
- Recompile Tailwind CSS from source.

## [1.1.20] - 2026-06-08
- Race messaging fixes, no-pilot recruitment prompt, full division display, README refresh.

## [1.1.18] - 2026-06-08
- OWASP 2025 hardening: error-leak fix, HSTS behind proxy, CSP, security logging.

## [1.1.17] - 2026-06-08
- Add "Keep me signed in" persistent login with step-up re-auth for sensitive actions.

## [1.1.16] - 2026-06-07
- Don't show the end-of-season error when the tyre supplier is just missing.

## [1.1.15] - 2026-06-07
- Add the glance billboard beside Last Sync (cash, division, next race).

## [1.1.14] - 2026-06-07
- Enable SQLite WAL + busy_timeout for better concurrency.

## [1.1.13] - 2026-06-07
- End-of-season and no-supplier guards; last-sync API counter copy.

## [1.1.12] - 2026-06-05
- Rename Driver Risk to Clear Track Risk on Cockpit/Car Wear; drop the Live Data pills.

## [1.1.11] - 2026-06-05
- Footer disclaimer + Contact me; mobile-friendly wrapping.

## [1.1.10] - 2026-06-05
- Expose logged-in/admin identity as Twig globals.

## [1.1.9] - 2026-06-04
- Self-service account deletion + Control Panel orientation.

## [1.1.8] - 2026-06-04
- **Security:** namespace the per-user GPRO cache to stop a cross-user leak.

## [1.1.7] - 2026-06-04
- Clearer recruitment/training copy for non-admins.

## [1.1.6] - 2026-06-04
- Right-size numeric inputs and polish selects.

## [1.1.5] - 2026-06-04
- Read tyre supplier characteristics from the API instead of a hardcoded snapshot.

## [1.1.4] - 2026-06-04
- Favourite-track fit column in the Recruitment Analyzer.

## [1.1.3] - 2026-06-03
- Drop the confirm() popup on Set your race strategy.

## [1.1.2] - 2026-06-03
- Single source of truth for the app version.

## [1.1.1] - 2026-06-03
- Sync the version string across footer, User-Agent, and composer.json.

## [1.1.0] - 2026-06-03
- Re-sync reminder before strategy + FYI notices on the wear/strategy tabs.

## [1.0.5] - 2026-06-03
- Manual API refresh, fuel-column split, boost stints in strategy.

## Early releases

### [0.2.0] - 2025-12-22
- Clean-up; sync user data on login.

### [0.1.0] - 2025-12-20
- Initial release.
