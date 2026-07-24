# Changelog

All notable changes to GPRO Pitwall are documented in this file.

The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/) and the
project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).
Every release is published as an annotated git tag of the same name.

## [1.13.1] - 2026-07-24

### Fixed
- Expired-session sync no longer shows a misleading "⚠ Network error". AJAX calls (`/api/warmup` re-sync, `/api/refresh_budget`) that hit an expired session now receive a small JSON `401`/`403` — from either the CSRF gate or the auth gate — and the frontend redirects to the login page with a "Session expired" notice instead of a dead-end error.

### Security
- **Security:** Dynamic responses now send `Cache-Control: no-store`, so authenticated pages can't be retained in the browser's back-forward/disk cache and redisplayed after logout on a shared machine. Static assets keep their immutable cache header.
- **Security:** Auth and CSRF failure responses to AJAX callers carry only a status string — no internal detail.

## [1.13.0] - 2026-07-10

### Added
- Dark mode with a Light / Dark / System appearance switch in the footer. System (the default) follows the operating-system preference live; an explicit choice is remembered on-device (localStorage) and applied before first paint.
- Design-token theming: every colour in the Tailwind `@theme` block is now a CSS `light-dark()` pair switched by `color-scheme` — one stylesheet, no `dark:` variants, no duplicated rules. Semantic aliases (`surface`, `on-accent`, `on-amber`, `danger`, `board`, `scrim`) cover the spots whose meaning can't flip with the ramps.
- Hand-tuned dark palette (steel-blue tinted, not an inversion) with every real foreground/background pairing validated against WCAG AA (4.5:1 text, 3:1 UI) before shipping; light mode is unchanged.

### Changed
- `bg-white` replaced by the `bg-surface` token tree-wide; modal backdrops use the `scrim` token; the strategy compound-table header band uses stable `board` tokens (dark in both themes); admin debug greens normalized to the emerald scale; scrollbar and focus-ring-offset colours follow the theme.

### Changed
- README rewritten for first-time visitors: banner image, condensed per-tab feature tour, configuration split into required vs optional keys, tightened deployment/security sections.
- CHANGELOG restructured into Keep a Changelog categories (Added / Changed / Fixed / Removed / Security) with tightened entries — content unchanged.

### Fixed
- README claimed a "provisional 55%" CI coverage floor; the enforced floor has been 45% since 1.7.10.

## [1.12.1] - 2026-07-07

### Fixed
- Strategy verdict compares same-type tyres only: on a dry race the best compound and its "beats X" runner-up are both dry (Rain no longer appears as a nonsense runner-up); on a wet race Rain is recommended outright with no runner-up line — it is the only wet compound.
- The best-compound pick follows the effective race weather (forecast default, overridable via the Race weather select), keeping the verdict coherent with the setup column, risk advice and push signals on the same screen.

### Added
- README status badges: CI, release, PHP, PHPStan, coverage floor, PSR-12.

## [1.12.0] - 2026-07-07

### Changed
- App-wide consistency pass (P4 of the 1.x roadmap): every card uses the shared `.card` / `.card-hd` / `.card-bd` component classes — zero raw card-utility strings left; admin, auth, contact and control-panel pages normalized to the same radius/shadow.
- Car Wear tab: driver stats collapsed into a "Driver — from last sync" accordion; the results table matches the cockpit wear idiom and keeps the End Wear verdict on-screen at 375 px.
- Training tab: driver stats and schedule grouped behind two accordions with an always-visible totals row (sessions + cost, server-rendered).
- Info banners on Testing, Car Wear and Recruitment slimmed to one line.
- Mobile table diet: the strategy Minimum-fuel, Testing decay-stage and wear Current columns hide below tablet width — no horizontal scroll at 375 px. Mobile page heights re-measured: Cockpit ~8.3 → ~2.4 screens, Strategy ~5.9 → ~3.8.

### Added
- Accessibility + no-JS hygiene (P5): global keyboard-focus ring on accordion summaries, `prefers-reduced-motion` also disables micro-transitions, and the two slider-only forms gained `<noscript>` submit buttons — previously unsubmittable without JavaScript.

## [1.11.0] - 2026-07-07

### Added
- Sticky tab bar on all viewports (translucent, blurred); the mobile dropdown replaced by a horizontally scrollable pill bar — one tap, siblings visible, plain links so it works without JS (P3 of the 1.x roadmap).
- Weekend-flow stepper on the Cockpit and Race Strategy tabs: a one-line `① Cockpit → ② Strategy` chip row, current step highlighted.

### Changed
- Nav grouped by intent: race-weekend cluster, team-building cluster, and an "Admin" label before the admin-only tabs. Order unchanged.
- Shorter tab labels (Strategy, Training, Recruitment) — display-only; a unit-tested alias map keeps old bookmarked URLs resolving.
- Mobile header diet: smaller logo and title, tagline hidden; the dedicated re-sync band removed — the re-sync button now lives in the signed-in status strip beside "Last sync".

## [1.10.1] - 2026-07-07

### Fixed
- Silverstone track data refreshed for GPRO's layout redesign, verified against the live API: handling 11→12, downforce Low→Medium, lap 5.138→5.89 km, 60→52 laps, 14→18 corners, avg speed 225.46→266.09 km/h, pit in/out 22.5→24.5 s.
- Indianapolis Oval event length corrected (80→200 laps, 321.8→804.4 km) — caught by a full 64-track drift sweep against the API; all other tracks matched.

### Changed
- Seeder schema version bumped (4→5) so existing databases re-run the idempotent track reseed on the first request after deploy.

## [1.10.0] - 2026-07-07

### Added
- Cockpit decision summary board (P2 of the 1.x roadmap): one verdict tile per card plus a "next step" tile; each tile links to its card and opens it on click.

### Changed
- Every cockpit card is now a collapsible accordion with its verdict repeated in the header — closed by default on mobile, open on first desktop visit, choice persisted per session. Two-column desktop layout; mobile stays single-column in decision order.
- Car-wear card diet: swap/risky/watch lists merged into one compact status table (Part · Lvl · now% → end% · verdict chip); swap options collapse to a best-pick one-liner; the verdict chip is a shared partial rendered in both the board tile and the card header.
- Testing projection and sponsors cards tightened: merged columns below small viewports, landing-track detail behind an inner accordion, per-negotiation characteristics compressed to a chip row.

## [1.9.0] - 2026-07-07

### Added
- Strategy verdict strip (P1 of the 1.x roadmap): best compound, stops, recommended fuel per stint, total time lost and the margin over the runner-up — refreshed live by the same fragment swap as the tables.
- One-line `.notice-slim` banner variant, reused by later phases.

### Changed
- Results reordered answer-first: verdict → compact track/supplier/fuel header → compound table → setup table → advisory accordions. The Race Engineer and Push-or-hold advisors are closed on mobile, open on first desktop visit, choice persisted.
- Driver and Team panels merged into one collapsed "Data used — from last sync" accordion; the settings form column sticks alongside results on desktop.
- Compound-table mobile diet: Est. Pit and the Lost breakdown columns hide below `md` — Total Lost, the ranking metric, stays. No horizontal scroll at 375 px.

## [1.8.1] - 2026-07-06

### Changed
- `composer.lock` is now committed: CI and CI-built bundles install the exact locked dependency set, `composer audit` checks a fixed target, and version bumps refresh the lock hash in the same commit.
- Tailwind builds with `source(none)`: utilities come only from the declared `@source` paths, so class-like words in prose files no longer emit dead CSS rules and the compiled stylesheet is reproducible.

### Fixed
- README secret-key docs corrected: `APP_SECRET` is the single root secret from which both AES-256-GCM keys and the email-hash HMAC key derive — there is no `EMAIL_ENCRYPTION_KEY`.

## [1.8.0] - 2026-07-06

### Added
- Shared `.card` / `.card-hd` / `.card-bd` component classes — the white-card idiom with a mobile padding step-down (P0 of the 1.x roadmap).
- Standardised `<details>` accordion partial (`_acc.twig`) with icon/verdict/body blocks and a visible focus ring.
- Accordion open/closed persistence centralised in `layout.twig`, restored after fragment swaps (Strategy results and Cockpit wear wired).

### Changed
- Cockpit cards migrated to the card classes and given stable anchor ids with scroll margin, so future sticky navigation never covers a jump target.

## [1.7.10] - 2026-07-04

### Fixed
- Coverage floor corrected 55% → 45%: the first pcov run measured the real baseline at 46.2% statements, so the provisional floor failed CI on main. Ratchet-up plan unchanged.

## [1.7.9] - 2026-07-04

### Added
- CI measures test coverage (`pcov`) and enforces a minimum statement-coverage floor via the native `bin/check_coverage.php`.
- CI `bundle` job on pushes to `main`: builds the release bundle and uploads it as a 14-day workflow artifact for build verification. Deployment stays a manual copy; the artifact excludes the private runtime inputs.

### Changed
- `bin/build_release.sh` is tracked again so CI can run it, slimmed to assemble + tar only — deploy logic moved to local-only tooling.

## [1.7.8] - 2026-07-04

### Changed
- `App\Support\Env` typed accessor (`get`/`int`/`float`/`bool`/`required`) now fronts every environment read; a missing `APP_SECRET` fails fast with a clear exception instead of a warning followed by a TypeError.
- Per-part wear-base data verified: all 64 tracks × 11 wear columns match the canonical source and the seeded database exactly — verification only, no code change.

## [1.7.7] - 2026-07-03

### Security
- `/healthz`'s per-check failure detail (previously raw exception text, publicly visible) is now shown only to admins or in dev — anonymous callers get `{ok}` only.
- Removed the dead legacy `action=` POST routing shim — an attacker-suppliable field that could still reach the router.

### Changed
- Sync failures are now logged with the exception class and message before being persisted as `failed` — previously the cause was silently discarded.

## [1.7.6] - 2026-07-03

### Security
- Raw exception messages no longer reach users: cockpit, car-wear, strategy, testing and recruitment errors log server-side and show a generic message instead of internal detail.

## [1.7.5] - 2026-07-03

### Security
- Local roadmap files moved into a generically ignored directory: naming a distinctive personal filename in a tracked file (`.gitignore`, the secret scanner) discloses its existence to anyone browsing the repo.

## [1.7.4] - 2026-06-24

### Fixed
- Cockpit no longer permanently shows "Race setup not saved in GPRO yet". Staleness keyed off a flag that stays unset for the entire pre-qualifying window; it is now decided by a RaceSetup vs Office track-id mismatch — the actual "weather still describes the previous race" signal.

## [1.7.3] - 2026-06-23

### Fixed
- Cockpit reads the next-race track, P/H/A demand and lap count from Office + TrackProfile instead of RaceSetup, which carries the *previous* race until a new setup is saved — the cockpit showed the wrong track even after a cache refresh. Favourite-track detection now resolves the track id from the calendar.

### Added
- Amber notice when the race setup isn't saved in GPRO yet; the weather card is withheld in that state rather than showing the previous race's forecast.

### Changed
- GPRO API curl timeouts raised and made env-tunable; outbound throttle eased (steady rate 4→2/s, burst 8→4) to stress the GPRO backend less.

## [1.7.2] - 2026-06-23

### Added
- Hovering the header Cash figure shows your rank against your group (e.g. "#30 of 40 by cash in Pro - 8"), computed from already-cached group data — no extra API call.

## [1.7.1] - 2026-06-23

### Added
- Push advisor wear-headroom signal: end-of-race wear projected at a reference risk of 50 counts as a push signal only when no part finishes above 90%.
- Push advisor relative-performance signals: car level and driver OA each ranked against your group, above-average counting as a reason to push. Hidden cleanly when group data isn't available.

### Changed
- The tyre-performance and ideal-temperature signals are hidden in Rookie/Amateur (no tyre supplier there); the tally counts only the signals that apply to your division.

## [1.7.0] - 2026-06-22

### Added
- "Push or hold?" advisor on Race Strategy: four binary signals (strict car–track PHA match, driver favourite track, tyre rating ≥ 4/8 for the race conditions, track temperature within ±3 °C of the tyre's ideal) condensed into one Clear Track Risk read — the more met, the more the weekend favours you.

### Changed
- Cockpit PHA Match no longer shows a push verdict (that call moved to the advisor) and highlights only strict **top** or **perfect** matches.

## [1.6.3] - 2026-06-20

### Added
- Race Strategy header shows the contracted tyre supplier's dry/wet performance (out of 8) and ideal temperature beside the track name, read from the GPRO suppliers feed — hidden cleanly when no supplier is contracted.

## [1.6.2] - 2026-06-18

### Removed
- Orphaned `rector.php` config left over from a previously dropped dependency.

## [1.6.1] - 2026-06-18

### Added
- Type-declaration coverage enforced in `composer analyse`: 100% return/property/constant types and `strict_types`, 99.5% param types (two untypeable `resource` handles).

### Changed
- PHPStan raised from level 7 to level 8 (null-safety), fixing the one surfaced gap.

## [1.6.0] - 2026-06-18

### Security
- Registration reworked so an unverified sign-up can no longer squat a username or email: in-flight registrations live in a `pending_registrations` table and only become a real account once the emailed code is verified. Legacy unverified rows purged; uniqueness constraints hardened.
- Email-existence oracle closed on the registration form — registering an already-registered email is indistinguishable from a new registration. Concurrent registrations for the same name resolve at the promotion INSERT (first to verify wins) instead of a 500.

### Added
- Admin → Users: summary cards for registered / active / new-signup / API-linked counts with ▲/▼ trends over a selectable 7/30/90-day window; in-flight registrations surfaced.

### Changed
- Admin → Users table is click-to-sort; the email-hash column removed.

## [1.5.7] - 2026-06-17

### Added
- Server-wide outbound API throttle: a shared token bucket (flock'd state file, shared across PHP workers) caps the aggregate call rate from the host IP; under bursts, calls are paced by a bounded sleep rather than failing. Env-tunable; rate `0` disables.
- FOBY note on the README and landing page: GPRO's "Find Out By Yourself" culture acknowledged, framing Pitwall as a transparent second opinion.

## [1.5.6] - 2026-06-14

### Added
- Cockpit car-wear card: collapsible reference table of each part's Power/Handling/Acceleration contribution per level, for manually working out the PHA shift of a swap.

## [1.5.5] - 2026-06-13

### Changed
- The Race Engineer's race-distance note now appears only for short and long races — it added no signal on normal-length ones.

## [1.5.4] - 2026-06-13

### Changed
- Race-distance note reworded to read narratively: no raw kilometres or field averages, just short/normal/long and what that means for driver energy.

## [1.5.3] - 2026-06-13

### Added
- Race Engineer race-distance note: the race is sorted into short / normal / long (field mean ± half a standard deviation across all 64 tracks) with energy advice — carry more clear-track risk on a short race, trim on a long one, more so when stamina is thin.

## [1.5.2] - 2026-06-12

### Fixed
- The Race Engineer accordion no longer re-opens on every recalculation — the collapsed/expanded choice persists for the session and is reapplied after every fragment swap.

## [1.5.1] - 2026-06-12

### Changed
- Pitwall AI is now **"Advice from the Race Engineer"** — AI branding dropped across the app; the sparkles icon replaced by a race-engineer headset.
- The Fuel Required card is a slim billboard strip above the results; Driver and Team are compact read-only panels.

### Added
- Race Engineer covers the rest of the race-setup form: **boost-lap placement** (early in traffic, in-laps to overcut, or the final laps — pit-window aware), the **race start approach** from driver control with a wet-start step-down, the **technical-problem pit threshold** derived from the track's pit-lane time, and a long-race **energy reminder**.

## [1.5.0] - 2026-06-11

### Added
- Track overtaking rating (Very Easy → Very Hard) as a colour-coded badge beside the fuel figures, from new `overtaking` + `grip` track columns.
- **Pitwall AI** advisor suggesting overtake/defend risk dials (0–100) as advisor prose, weighing the track's overtaking rating, driver skills on GPRO's 0–250 scale (talent weighted highest in the wet), aggression both ways, the forecast, track grip, tyre wear and stamina on long races.
- Pit-count tie-breaker tip (fewer stops where passing is hard; the extra stop is affordable where it's easy) — suppressed when rain is likely.

## [1.4.0] - 2026-06-10

### Added
- In-app contact form for logged-in users: whitelisted subject dropdown, delivery with Reply-To set to the sender's account address, guarded by auth + CSRF + a security-logged per-user rate limit (5/hour) — no CAPTCHA needed, every sender is verified. Anonymous visitors keep the `mailto:` footer link.
- Debug page **Active Users** telemetry: users with a successful GPRO sync in the last 30 days.
- `.form-textarea` component class (the shared input look without the pinned height).

### Changed
- Dependency refresh: PHP ≥ 8.5, PHPMailer 7, PHPUnit 13 (test suite migrated), PHPStan 2.2, PHP_CodeSniffer 4. `rector/rector` and the abandoned `sserbin/twig-linter` removed — Twig linting is now a native `bin/twig_lint.php` on Twig's own parser.
- Footer GitHub links grouped under one icon.

## [1.3.1] - 2026-06-10

### Added
- `.env.example` (every key the app reads) and `.deploy.env.example` — both referenced by docs and tooling but missing from the repo.

### Changed
- `bin/probe_security.sh` made WAF-friendly: paced + jittered requests, shuffled order, rotating browser User-Agents, one shared request for the header checks. Also probes `/.deploy.env`, `/robots.txt` and `/sitemap.xml`.

### Fixed
- README no longer references a non-existent mail-tail script or the unused `EMAIL_ENCRYPTION_KEY`.

## [1.3.0] - 2026-06-10

### Added
- SEO pass: per-page titles, meta descriptions and canonical URLs; `noindex` on private pages; Open Graph + Twitter cards with a generated 1200×630 OG image; `WebApplication` JSON-LD; `robots.txt` + `sitemap.xml`.
- Landing page rework: keyword-front-loaded hero, "up and running in two minutes" steps, a five-question FAQ, and a closing CTA band.
- New brand mark in the GPRO palette (steel-navy gradient, yellow swoosh) across logo, favicons and the OG card.

### Changed
- Static assets ship immutable year-long cache headers; the stylesheet is cache-busted per release.
- The Tailwind `blue-*` scale re-anchored to a GPRO steel-blue ramp, so every button, link and focus ring adopts the brand blue with no template changes.

## [1.2.8] - 2026-06-10

### Security
- Login no longer leaks whether a username exists: an unknown username produces a decoy pending state that routes to `/verify` identically to a real account, closing the redirect-based enumeration oracle.
- The Control Panel never sends the decrypted GPRO API token to the browser — masked last-4 hint, blank submit = unchanged; the token is also stripped from the shared Twig `user` global.
- Filesystem cache deserializes with `allowed_classes => false`, so a tampered cache file degrades to a miss instead of an object-injection gadget.
- Outbound GPRO API calls set connect + total curl timeouts so a hung upstream can't pin a PHP worker.

### Changed
- Per-request DB migration gated on SQLite's `user_version` — a warm database does a single PRAGMA read instead of the full DDL scan.

### Fixed
- `StrategyService` no longer raises an "Undefined array key" warning when fed a TrackProfile without an id; it falls back to a name match.

## [1.2.7] - 2026-06-10

### Changed
- UX consistency pass across every template: shared `.notice-*` banner classes replace ad-hoc styles, raw-utility buttons converted to `.btn` components, headings aligned to the `t-*` type tokens, badge palettes unified to amber/emerald.
- Race Strategy sidebar compacted (aligned label-left rows, narrower numeric boxes, native spinners removed app-wide); Testing tab restructured into two flowing columns.

### Added
- Recruitment pagination: windowed page list (1 … current±2 … last) and visible Previous/Next controls on mobile.

### Fixed
- The landing page now renders flash messages — the "account deleted" confirmation was silently dropped.
- The `fade-in` animation referenced by the tab container is now defined, with `prefers-reduced-motion` respected.

## [1.2.6] - 2026-06-09

### Changed
- CI actions bumped off the deprecated Node.js 20 runtime (`actions/checkout@v6`, `actions/cache@v5`) ahead of GitHub's forced migration.

## [1.2.5] - 2026-06-09

### Added
- Recruitment Analyzer value-range filters: inclusive min/max bounds per driver attribute (either side optional), validated server-side, persisting across sorting and pagination. (#42, thanks @HelderfV)

## [1.2.4] - 2026-06-09

### Fixed
- Testing-tab wear factor refined 0.5 → 0.53: a second real session independently best-fit 0.533, and the previous value ran ~6% low — the risky direction for wear.

## [1.2.3] - 2026-06-09

### Changed
- The Testing tab's Expected Car Wear card is marked **Experimental** while the estimates are refined.

## [1.2.2] - 2026-06-09

### Fixed
- Testing-tab car-wear projection read ~2× too high: testing laps wear the car at roughly half the per-lap rate of a race, so the model now applies a factor calibrated against a real 30-lap session (uniform ~0.53× across all 11 parts).

## [1.2.1] - 2026-06-09

### Fixed
- The Testing tab now refreshes on re-sync: the sync force-warms the `GetTesting` feed instead of serving a stale cache entry until TTL.

## [1.2.0] - 2026-06-09

### Added
- **Testing** tab: the current testing track and its demands, the points distribution across Test / R&D / Engineering / **Car Character**, points gained per 5 laps for each testing priority, the ideal setup for the testing track, and a slider-driven (5–100 laps) car-wear projection.

## [1.1.31] - 2026-06-09

### Fixed
- The track selector defaults to your actual next-race track from the cached Office data instead of the first track in the config list.

## [1.1.30] - 2026-06-09

### Added
- Styled 403/404/500 error pages via a typed `HttpException` rendered through the normal layout; the 500 handler hides internals behind a reference id.

## [1.1.29] - 2026-06-09

### Security
- The admin restore form reads the username from a `data-` attribute instead of interpolating it into an inline `confirm()`, so a legacy username containing quotes can't execute in an admin session.

## [1.1.28] - 2026-06-09

### Fixed
- Debug → Database size: the SQLite path is resolved absolutely, so `filesize()` no longer depends on the process working directory.

## [1.1.27] - 2026-06-09

### Security
- Server-side username whitelist (`[A-Za-z0-9_]`) at registration, mirroring the form's client pattern.

## [1.1.26] - 2026-06-09

### Added
- The login form remembers the last username on that browser (client-side, no server state); "Hello, username" greeting in the header.

## [1.1.25] - 2026-06-09

### Added
- This CHANGELOG, backfilled from the release tags.

## [1.1.24] - 2026-06-09

### Added
- Race-window cache keys for race-critical GPRO data.

## [1.1.23] - 2026-06-09

### Changed
- Timestamps render in the visitor's local timezone.

## [1.1.22] - 2026-06-09

### Security
- Verification emails capped per account, captcha added to login, and a resend link for missed deliveries.

## [1.1.21] - 2026-06-08

### Changed
- Tailwind CSS recompiled from source.

## [1.1.20] - 2026-06-08

### Changed
- Race messaging fixes, no-pilot recruitment prompt, full division display, README refresh.

## [1.1.18] - 2026-06-08

### Security
- OWASP 2025 hardening: error-leak fix, HSTS behind proxy, CSP, security logging.

## [1.1.17] - 2026-06-08

### Added
- "Keep me signed in" persistent login with step-up re-auth for sensitive actions.

## [1.1.16] - 2026-06-07

### Fixed
- Don't show the end-of-season error when the tyre supplier is just missing.

## [1.1.15] - 2026-06-07

### Added
- Glance billboard beside Last Sync (cash, division, next race).

## [1.1.14] - 2026-06-07

### Changed
- SQLite WAL + busy_timeout enabled for better concurrency.

## [1.1.13] - 2026-06-07

### Fixed
- End-of-season and no-supplier guards; last-sync API counter copy.

## [1.1.12] - 2026-06-05

### Changed
- Driver Risk renamed to Clear Track Risk on Cockpit/Car Wear; Live Data pills dropped.

## [1.1.11] - 2026-06-05

### Added
- Footer disclaimer + Contact me; mobile-friendly wrapping.

## [1.1.10] - 2026-06-05

### Changed
- Logged-in/admin identity exposed as Twig globals.

## [1.1.9] - 2026-06-04

### Added
- Self-service account deletion + Control Panel orientation.

## [1.1.8] - 2026-06-04

### Security
- Per-user GPRO cache namespaced to stop a cross-user leak.

## [1.1.7] - 2026-06-04

### Changed
- Clearer recruitment/training copy for non-admins.

## [1.1.6] - 2026-06-04

### Changed
- Numeric inputs right-sized and selects polished.

## [1.1.5] - 2026-06-04

### Changed
- Tyre supplier characteristics read from the API instead of a hardcoded snapshot.

## [1.1.4] - 2026-06-04

### Added
- Favourite-track fit column in the Recruitment Analyzer.

## [1.1.3] - 2026-06-03

### Removed
- The `confirm()` popup on "Set your race strategy".

## [1.1.2] - 2026-06-03

### Changed
- Single source of truth for the app version.

## [1.1.1] - 2026-06-03

### Fixed
- Version string synced across footer, User-Agent and composer.json.

## [1.1.0] - 2026-06-03

### Added
- Re-sync reminder before strategy + FYI notices on the wear/strategy tabs.

## [1.0.5] - 2026-06-03

### Added
- Manual API refresh, fuel-column split, boost stints in strategy.

## [0.2.0] - 2025-12-22

### Changed
- Clean-up; user data synced on login.

## [0.1.0] - 2025-12-20

### Added
- Initial release.
