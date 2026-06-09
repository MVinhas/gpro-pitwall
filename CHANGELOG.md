# Changelog

All notable changes to GPRO Pitwall are documented here.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and the project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).
Each entry mirrors its annotated release tag.

## [1.1.27] - 2026-06-09
- **Security:** server-side username whitelist (`[A-Za-z0-9_]`) at registration, mirroring the form's client pattern — attacker-controlled markup can't reach storage.

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
