# Changelog

All notable changes to GPRO Pitwall are documented here.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and the project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).
Each entry mirrors its annotated release tag.

## [1.7.1] - 2026-06-23
- Push/no-push advisor gains **relative-performance signals**: your car level and your driver's OA each ranked against the rest of your group (e.g. "#6 of 40 by driver OA"), with above-the-group-average counting as a reason to push. Car ranking comes from `MoneyLevels`, driver OA from the group `ViewStaff` feed; both are matched to your own manager by IDM and hidden cleanly when the group data isn't available.
- Push/no-push advisor now **hides the tyre-performance and ideal-temperature signals in Rookie and Amateur**, where there's no tyre supplier to choose. The "signals met" tally is now out of however many signals apply to your division.

## [1.7.0] - 2026-06-22
- **Push/no-push advisor.** New collapsible "Push or hold?" card on the Race Strategy tab, below the Race Engineer, that turns four binary signals into a single read for your Clear Track Risk dial: car P/H/A matches the track (strict), it's a driver favourite track, the tyre supplier's dry/wet rating for the race conditions is 4/8 or better, and the track temperature is within ±3 °C of the tyre's ideal. The more signals met, the more the weekend is set up in your favour — carry a higher Clear Track Risk; all four met points to a very likely win. The header shows how many of the four are met; expand/collapse state persists like the Race Engineer.
- Cockpit P/H/A Match no longer shows a PUSH / ALL IN verdict — that call now lives in the Strategy advisor. The card highlights a match only on the strict rule: **top** (car's #1 attribute equals the track's #1) or **perfect** (all three ranks align). No other shape — including a tied top — counts as a match.

## [1.6.3] - 2026-06-20
- Race Strategy header now shows the contracted tyre supplier's dry/wet performance (out of 8) and ideal temperature beside the track name, read straight from the GPRO suppliers feed (no extra API call). The redundant "Supplier" label was dropped so the name sits level with the track. Hidden cleanly when no supplier is contracted (e.g. after a season reset) or the feed is unavailable.

## [1.6.2] - 2026-06-18
- Removed the orphaned `rector.php` config. `rector/rector` was dropped as a dependency earlier (one-shot tool, not in the check pipeline); the leftover config referenced classes no longer installed and would only mislead.

## [1.6.1] - 2026-06-18
- Added `tomasvotruba/type-coverage` as a PHPStan extension. Type-declaration coverage is now enforced in `composer analyse`: 100% return/property/constant types and `declare(strict_types=1)`, 99.5% param types (the only gap is two untypeable `resource` handles in `GproApiThrottle`). Introduced a `phpstan.neon` config and backfilled the missing typed constants and closure param types.
- Raised PHPStan from level 7 to level 8 (null-safety), fixing the one surfaced gap: `Database::getConnection()` could narrow to `PDO|null`.

## [1.6.0] - 2026-06-18
- **Security:** Registration reworked so an unverified sign-up can no longer squat a username/email. In-flight registrations live in a new `pending_registrations` table and only become a real `users` row once the emailed code is verified; the account namespace is now "verified accounts only". A bounced or abandoned verification email leaves no residue. Legacy unverified `users` rows are purged by the migration, and the `username`/`email_hash` UNIQUE constraints are hardened with explicit indexes.
- **Security:** Closed the email-existence oracle on the registration form — registering an email that already has an account is now indistinguishable from a new registration (no row created, no email sent). Username availability is still surfaced (standard UX). The concurrent-registration race is resolved at the authoritative promotion INSERT: when two people race for the same username, whoever verifies email control first wins, instead of a 500 from an unhandled UNIQUE violation.
- Admin → Users page revamped: summary cards for registered / active / new-signup / API-linked counts, with period-over-period activity and signup **trends** (▲/▼ with %, selectable 7/30/90-day window) so you can see at a glance whether the app is growing. In-flight (awaiting-verification) registrations are surfaced too.
- Admin → Users table columns are now click-to-sort (client-side, current page), and the email-hash column was removed.

## [1.5.7] - 2026-06-17
- Server-wide outbound API throttle: all GPRO calls leave from one host IP, so a shared token bucket (flock'd state file in `var/cache/`, shared across PHP workers) now caps the aggregate outbound rate. Cache hits are unaffected; under a sync burst, calls are paced by a bounded sleep rather than failing. Configurable via `GPRO_API_RATE` / `GPRO_API_BURST` / `GPRO_API_MAX_BLOCK_MS` (rate 0 disables).
- FOBY note added to the README and landing page (section + FAQ entry): GPRO's "Find Out By Yourself" culture is acknowledged, framing Pitwall as a transparent second opinion rather than a replacement for the manager's own analysis.

## [1.5.6] - 2026-06-14
- Race Weekend Pitwall: the Car Wear cockpit card gains a collapsible "PHA contribution per part" reference table. It lists each car part's Power/Handling/Acceleration contribution per level so managers can manually calculate the PHA shift of a part swap when forcing a track-car match.

## [1.5.5] - 2026-06-13
- Race Strategy: the Race Engineer's race-distance note now only appears for short and long races. Normal-length races (the bulk of the field) show nothing — the note added no signal there. Short and long advice is unchanged.

## [1.5.4] - 2026-06-13
- Race Strategy: reworded the Race Engineer's race-distance note to read narratively instead of analytically. It no longer quotes the race length or the field average — it just tells the manager whether this is a short, normal or long race and what that means for driver energy (carry more clear-track risk and boost freely on a short one; trim both on a long one, more so when stamina is thin). The short/normal/long tiers are unchanged under the hood.

## [1.5.3] - 2026-06-13
- Race Strategy: the Race Engineer now always shows a race-distance note. It places the race against the field average (~301 km across all 64 tracks) and sorts it into short / normal / long — bands set at the mean ± half a standard deviation (under 293 km, 293–310 km, over 310 km). A shorter race drains less driver energy, so it advises carrying higher clear-track risk and placing boost laps freely; a longer race bleeds energy, so it advises trimming both, more so when stamina is thin. Replaces the previous long-race-only energy reminder.

## [1.5.2] - 2026-06-12
- Race Strategy: the Race Engineer accordion no longer re-opens when a slider or select re-runs the calculation — the collapsed/expanded choice is remembered for the session (sessionStorage) and reapplied after every fragment swap.

## [1.5.1] - 2026-06-12
- Pitwall AI is now **"Advice from the Race Engineer"** — the AI branding is dropped across the app, landing page and README, and the sparkles icon is replaced by a race-engineer headset.
- Race Engineer: now covers the rest of the race-setup form. Suggests the three **boost-lap** start laps placed where pace converts into something (early while the field is packed on easy-passing tracks, the in-laps before each stop to overcut through the pit cycle, the final laps to bring it home), pit-window aware via the best strategy's stint plan, with a rain caution and a reminder that boosts burn extra fuel.
- Race Engineer: suggests the **race start approach** (one of GPRO's four options) from driver control, track overtaking and a wet-start step-down, and the **technical-problem pit threshold** ("Yes, if > N laps remain") derived from the track's pit-lane time against the 3–6 s/lap a limping car loses.
- Race Engineer: long races (≥300 km) get an **energy reminder** — clear-track risks drive energy drain, and a driver at 0% finishes at a slow, no-risk pace. On Very Easy overtaking tracks the prose now notes the traffic dials matter less and the lap time lives in clear-track risk.
- Race Strategy: the Fuel Required card is now a slim billboard strip (consumption, dry/wet fuel, overtaking rating) above the results. The Driver and Team cards are now compact, informative read-only panels (values come from the last sync) and sit above Race Settings, alongside the setup and strategy output.

## [1.5.0] - 2026-06-11
- Race Strategy: the track's overtaking rating (Very Easy → Very Hard) now shows as a colour-coded badge next to the fuel figures. Sourced from the Tracks data (new `overtaking` + `grip` columns, seeded from `data/tracks.csv`; schema version 2).
- Race Strategy: new **Pitwall AI** box suggesting overtake/defend risk dials (0–100, snapped to fives, capped at 70) phrased as advisor prose. Heuristic inputs: track overtaking rating (hard passing → push overtake, easy passing → invest in defence), driver skills weighted on GPRO's 0–250 scale (concentration/experience/talent/motivation; talent takes the largest weight in the wet), aggressiveness both ways (backed by experience it buys attacking pace on the overtake dial; beyond experience it's the mistake trap and trims margin), wet or rain-threatened forecasts trimming both numbers, low track grip and very high tyre wear shaving both dials, and stamina on long races (≥300 km). Track downforce and suspension are deliberately excluded (collinear with the overtaking rating / no mistake mechanism).
- Race Strategy: the Pitwall AI box adds a pit-count tie-breaker tip (fewer stops when passing is hard, the extra stop is affordable when passing is easy) — suppressed whenever the forecast says rain is likely, since a wet race rewrites the stop plan.

## [1.4.0] - 2026-06-10
- In-app contact form (`/contact`, "Send feedback" in the footer) for logged-in users: whitelisted subject dropdown + message, delivered by email with Reply-To set to the sender's account address. Guarded by authentication, CSRF and a per-user rate limit (5/hour, security-logged) — no CAPTCHA, every sender is a verified account. Inline privacy note (address used only to reply, never marketing/newsletters) with an anonymous `mailto:` alternative, plus a subtle Buy Me a Coffee link. Anonymous visitors keep the plain `mailto:` footer link. Anti-double-submit via the shared `data-disable-on-submit` guard.
- Debug page: new **Active Users** telemetry card — users with at least one successful GPRO data sync in the last 30 days (tooltip carries the definition). Counted next to (not instead of) total registrations.
- Footer: the two GitHub links are grouped under one icon; "Report issue" renamed to "Open GitHub Issue".
- New `.form-textarea` component class — the shared input look without the pinned `h-9`, which collapsed textareas to a single line.
- Dependencies: PHP `>=8.5`, PHPMailer `^7.1`, PHPUnit 13.2, PHPStan 2.2.2, PHP_CodeSniffer 4.0, symfony/var-dumper `^8.1`. Removed `rector/rector` (one-shot tool, not part of the check pipeline) and the abandoned `sserbin/twig-linter` (its `symfony/console ^5.4||^6.1` pin blocked var-dumper 8.x) — replaced by a native `bin/twig_lint.php` built on Twig's own tokenizer/parser.
- Tests: PHPUnit 13 migration (`createStub()` for expectation-less mocks) and new ContactService + active-user-count suites — 263 tests, 656 assertions.

## [1.3.1] - 2026-06-10
- Add `.env.example` (every key the app reads, with safe defaults; sensitive values intentionally blank) and `.deploy.env.example` (SFTP deploy template) — both were referenced by the docs and deploy tooling but missing from the repo.
- `bin/probe_security.sh` is now WAF-friendly: every request is paced by a base delay plus random jitter (`PROBE_DELAY`/`PROBE_JITTER`, default 3s + 0–4s), the probe order is shuffled per run, each request carries a rotating real-browser User-Agent, and the four header checks share a single request — so shared-host firewalls no longer fingerprint the probe as a scanner and ban the source IP. Also probes `/.deploy.env`, `/robots.txt` and `/sitemap.xml`.
- Docs: README no longer references the non-existent `bin/dev_mail_tail.php` and the unused `EMAIL_ENCRYPTION_KEY` (both AES keys derive from `APP_SECRET`).

## [1.3.0] - 2026-06-10
- SEO pass benchmarked against comparable GPRO tooling sites (none has structured data, social cards or substantial indexable copy):
  - Per-page `<title>` / meta description / canonical URL via Twig blocks; private pages (verify, reauth, control panel, admin, debug, errors) now `noindex`.
  - Open Graph + Twitter cards with a generated 1200×630 `assets/og-image.png` (SVG og:images are ignored by most crawlers); `WebApplication` JSON-LD with free-offer markup on the landing page.
  - `robots.txt` + `sitemap.xml` in the docroot (shipped automatically by `build_release.sh`'s `public/` copy).
  - Canonical origin configurable via `APP_PUBLIC_URL` (defaults to `https://gpro-pitwall.com`).
- Landing page promoted-up: keyword-front-loaded hero, "Up and running in two minutes" steps, section headings over the feature grid, a five-question FAQ (real long-tail copy none of the competitor sites has), and a closing CTA band.
- New brand mark: original logo in the GPRO palette (steel-navy gradient, yellow speed swoosh, white P) across `logo.svg`, `favicon.svg`, regenerated multi-size `favicon.ico`, and the OG card.
- Performance: static assets now ship `Cache-Control: public, max-age=31536000, immutable`; the stylesheet is cache-busted per release via `?v={version}`.
- Brand palette: the Tailwind `blue-*` scale is re-anchored to a GPRO steel-blue ramp (logo navy at `blue-900`), so every button, link, active tab, focus ring and info banner adopts the GPRO-flavoured blue with no template changes. Semantic colours (amber caution, red error, emerald success) are untouched.

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
