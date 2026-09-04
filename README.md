# GPRO Pitwall

[![CI](https://github.com/MVinhas/gpro-pitwall/actions/workflows/ci.yml/badge.svg)](https://github.com/MVinhas/gpro-pitwall/actions/workflows/ci.yml)
[![Release](https://img.shields.io/github/v/tag/MVinhas/gpro-pitwall?sort=semver&label=release&color=blue)](https://github.com/MVinhas/gpro-pitwall/tags)
[![PHP 8.5](https://img.shields.io/badge/PHP-8.5-777BB4?logo=php&logoColor=white)](composer.json)
[![PHPStan level 8](https://img.shields.io/badge/PHPStan-level%208-4F5B93)](phpstan.neon)
[![Coverage floor](https://img.shields.io/badge/coverage-%E2%89%A559%25%20CI--enforced-yellow)](.github/workflows/ci.yml)
[![PSR-12](https://img.shields.io/badge/code%20style-PSR--12-blue)](https://www.php-fig.org/psr/psr-12/)

![GPRO Pitwall — race strategy, setup calculator and car wear analysis for GPRO managers](public/assets/og-image.png)

Race-weekend cockpit for [Grand Prix Racing Online](https://www.gpro.net) managers. Pitwall reads your own GPRO data through the official public API and turns it into the answers you need before qualifying: what to train, which parts to swap, what setup to run, how hard to push — and what to bet on the weather.

- **Live:** [gpro-pitwall.com](https://gpro-pitwall.com) — free for every registered user; no tiers, no paywall
- **Contact:** admin@gpro-pitwall.com · [open an issue](https://github.com/MVinhas/gpro-pitwall/issues)
- **Support:** voluntary, via [Buy Me a Coffee](https://buymeacoffee.com/mvinhas)

Getting started takes two minutes: register with your email (passwordless — a one-time code, no password ever stored), paste your GPRO API token in the Control Panel (encrypted at rest), and every tab fills in with your driver, car, team and next race.

---

## Features

Every screen reads your own GPRO data and answers one race-weekend question. Full
detail and screenshots live on [gpro-pitwall.com](https://gpro-pitwall.com).

- **Cockpit** — the race-weekend spine. A decision board of verdict tiles over cards for
  PHA match, testing projection, boost-lap fuel, the weather call, sponsor answers,
  training picks and per-part car wear — a risk slider, an optional projection through
  training laps run beforehand, and an inline replacement plan. Also carries the season
  calendar with each track's P/H/A demand.
- **Race Strategy** — fuel, tyres and setup per compound, with the best plan chosen by
  total time cost rather than tyre life alone. Clear Track Risk is priced as a trade:
  added wear against clear-air time gained. Includes the **Race Engineer**, which reads
  the driver, track and forecast and says in plain words how to fill the race form, and a
  **push-or-hold checklist** for the risk dial.
- **Testing** — the testing track's demands vs your car, the points split across Test /
  R&D / Engineering / Car Character, gains per 5 laps per priority, and the ideal setup.
- **Training Planner** — cumulative effect of every program × count combination, with
  attribute bounds respected and projected Overall Ability before and after.
- **Recruitment Analyzer** — scores the full driver market (4–5k) against your division's
  ideal pilot, with per-attribute filters and a favourite-tracks-this-season column.
- **Admin** — division baselines, user management with an append-only audit log, and
  **Race Intelligence**: an anonymous collective race corpus segmented by level, built
  from data managers already sync. Rows carry no user identifier of any kind.
- **Accounts** — passwordless one-time-code login, a verified-only username namespace,
  opt-in persistent login with rotation and theft detection, and step-up
  re-authentication for sensitive actions.

Everything works at 375 px and without JavaScript, with a Light / Dark / System
appearance switch and a WCAG-AA-validated dark theme.

---

## A note on FOBY

GPRO is, by tradition, a **Find Out By Yourself** game — much of the reward is analysing your own data and drawing your own conclusions. Pitwall is built to respect that culture, not erase it: it's a **second opinion, not a substitute**; every screen shows its inputs and reasoning so you learn the *why*, not just the *what*; and the actual game formulas stay private (git-ignored `config/secrets.php`) — nothing here redistributes GPRO's mechanics. If working things out from scratch is the part you enjoy, do that first — then use Pitwall to check your thinking.

---

## Run it locally

Zero-infra by design — no Docker, no Mailpit, no Redis, no APCu required.

```bash
composer install
cp .env.example .env             # then fill in values (see below)
php bin/seed_tracks.php          # bootstrap SQLite schema + seed tracks
bin/build_tailwind.sh            # compile public/assets/app.css
php -S localhost:8000 -t public  # dev server
```

In dev (`IS_DEV=true`) outgoing mail is written to `var/mail/*.eml` instead of SMTP — open the newest file to read your verification code:

```bash
ls -t var/mail/*.eml | head -1 | xargs cat
```

### Configuration

Required keys in `.env`:

| Key | Notes |
|---|---|
| `APP_SECRET` | 64-hex random (`openssl rand -hex 32`). The single root secret: HMAC for email hashes + verification codes, and the derivation root for both AES-256-GCM keys |
| `IS_DEV` | `true` in dev, `false` in prod |
| `MAIL_HOST` / `MAIL_PORT` / `MAIL_USER` / `MAIL_PASS` | SMTP credentials (prod only — dev writes `.eml` files) |
| `RECAPTCHA_SITE_KEY` / `RECAPTCHA_SECRET_KEY` | Required in prod; bypassed when `IS_DEV=true` |

Optional tuning (sensible defaults):

| Key | Notes |
|---|---|
| `CACHE_DRIVER` | `filesystem` (default; zero infra), `apcu`, `redis`, or `none` |
| `MAIL_FROM` / `MAIL_FROM_NAME` | Sender identity (defaults provided) |
| `SYNC_SAFETY_MARGIN` | Sync defers when the user's remaining API budget is below calls + margin (default 20) |
| `GPRO_API_RATE` / `GPRO_API_BURST` / `GPRO_API_MAX_BLOCK_MS` | Host-wide outbound throttle: steady calls/sec, burst size, max wait in ms (defaults 2 / 4 / 4000; rate `0` disables) |
| `GPRO_API_CONNECT_TIMEOUT` / `GPRO_API_TIMEOUT` / `GPRO_API_MARKET_TIMEOUT` | Per-call curl timeouts in seconds (defaults 10 / 30 / 60) |

## Commands

```bash
composer check                         # lint + analyse (PHPStan L8 + type-coverage) + twig-lint + test
composer test                          # PHPUnit only
composer analyse                       # PHPStan only
composer lint                          # PSR-12
composer audit                         # dependency security advisories

bin/build_tailwind.sh                  # compile assets/css/app.css → public/assets/app.css (--watch for dev)
bin/build_release.sh --tar             # assemble dist/ deploy bundle (+ tarball)
php bin/seed_tracks.php                # initialise SQLite + seed tracks (one-shot)
php bin/db_browser.php                 # local SQLite viewer (CLI only — never served)
bin/check_no_secrets.sh                # pre-commit secret scan
bin/probe_security.sh <url>            # post-deploy leak probe (must exit 0)
```

## Deployment

Source of truth is GitHub; deployment is a manual file copy to any PHP 8.5 host. CI also builds the bundle as a workflow artifact on every push to `main` — build verification only, since it excludes the private runtime inputs.

1. `bin/build_release.sh --tar` → `dist/gpro-pitwall.tar.gz`, self-contained: `vendor/` installed without dev deps, compiled CSS, writable `var/` skeleton.
2. Upload `dist/gpro-pitwall/*` to the domain's web root.
3. On the host: **document root = `public/`** (the load-bearing setting — every sensitive file lives outside it), PHP 8.5, HTTPS on.
4. Create `.env` on the server: `IS_DEV=false`, SMTP + reCAPTCHA credentials, and a **fresh** `APP_SECRET` — never reuse the dev key.
5. Permissions:
   ```bash
   chmod 600 .env
   chmod 640 config/secrets.php gpro_pilots.sqlite
   chmod 750 var var/cache var/mail var/log
   ```
6. Visit `/` — the first request initialises the SQLite schema.
7. Probe it:
   ```bash
   bin/probe_security.sh https://your-domain.example
   ```
   Must exit 0: 21 sensitive paths blocked, the public surface serving 200, security headers present on `/`. Requests are paced, jittered and shuffled so shared-host WAFs don't ban the probing IP — a run takes ~2–3 minutes (tune with `PROBE_DELAY` / `PROBE_JITTER`).

## Tech stack

- **PHP 8.5**, no framework — a custom front controller and a flat DI container in `bootstrap.php`; routes in `config/routes.php`.
- **Twig 3** templates; **Tailwind v4** compiled to a static asset (no CDN, no in-browser compile). Light and dark themes ship in one stylesheet: every design token is a CSS `light-dark()` pair switched by `color-scheme`, so System mode tracks the OS with zero JavaScript.
- **SQLite** via PDO — emails and API tokens encrypted at rest (AES-256-GCM).
- **PHPMailer 7** for SMTP; dev writes `.eml` files instead.
- **PHPUnit 13** — 747 tests, 2086 assertions — with **PHPStan level 8** and enforced type-declaration coverage (100% return/property/constant + `strict_types`; 99.5% param). Twig linted by a native `bin/twig_lint.php` built on Twig's own parser. CI measures statement coverage with `pcov` and enforces a floor (currently 59%, ratcheted up as coverage grows).
- **Timestamps stored and served as UTC**, localised per visitor in the browser — no server-side timezone config.

## Architecture

```
Request → public/index.php → Http\Router → Controller → Service → Repository → Twig
```

- Controllers are thin; logic lives in services; repositories own the SQL (prepared statements only).
- `bootstrap.php` wires every dependency into a flat container — adding a service is one line.
- Cache adapters (`filesystem` default, APCu, Redis, none) behind one interface, resolved by `CacheFactory`. Every key is namespaced by app version, so a deploy can never serve a previous release's payload shape out of a cache segment it cannot wipe.
- **Host-wide outbound throttle** — all GPRO API calls leave from one IP, so a token bucket shared across PHP workers (a `flock`'d state file) paces real fetches under burst load; cache hits never touch it. It never throws — worst case is "slightly slower", not a failed page. Complements the per-token budget guard (`SYNC_SAFETY_MARGIN`).
- **Race-window cache keys** — race-critical data is namespaced by the current race window (computed from the clock against GPRO's Tue/Fri schedule, no API call), so caches roll over exactly once per race weekend instead of serving last week's data until TTL. Configurable via `GPRO_RACE_DAYS` / `GPRO_RACE_BOUNDARY_HOUR` / `GPRO_RACE_TZ`.

## Security posture

Reviewed against the OWASP Top 10:2025.

- CSRF token on every POST, validated in the front controller.
- Emails and API tokens encrypted at rest (AES-256-GCM, domain-separated keys derived from `APP_SECRET`); lookups use HMAC-SHA256 email hashes, so a stolen DB file can't enumerate users. The key lives in `.env` on the same host, so this protects a stolen database file — not against an attacker with arbitrary file read on the server.
- The decrypted GPRO API token is never sent back to the browser: the Control Panel shows a masked last-4 hint and accepts a new value (blank = unchanged); the token is also stripped from the shared Twig `user` global.
- Login leaks nothing about whether a username exists: unknown usernames produce a decoy pending state that routes to `/verify` identically to a real account (and can never verify).
- Security headers in `public/.htaccess`: Content-Security-Policy, HSTS, X-Content-Type-Options, X-Frame-Options, Referrer-Policy, Permissions-Policy — proxy-aware via `X-Forwarded-Proto`.
- Session cookies HttpOnly + Secure + SameSite=Lax. "Remember me" tokens store only a hashed validator, rotate on every use for theft detection, and are revocable. Dynamic responses send `Cache-Control: no-store`, so authenticated pages aren't retained in the browser cache after logout on a shared machine.
- Login and registration are reCAPTCHA-gated and rate-limited per IP; verification codes carry a TTL, an attempt cap, and a per-account hourly email cap, so blind username-guessing can't spam real users. Sensitive actions require step-up re-authentication.
- Race telemetry is **anonymous at the data model**: the `race_telemetry` table carries no user id, username, or account-derived key, and de-duplicates on the race's own natural key rather than a per-user token — so an admin (or anyone with the DB file and `APP_SECRET`) cannot attribute a row to the manager who produced it. The admin intelligence screen reads only whole-dataset aggregates.
- One centralised authorisation gate (`requireAuth` / `requireAdmin` / `requireFreshAuth`) — every mutating, admin and debug route is gated server-side, not just hidden in templates.
- The contact form is authenticated-only with a whitelisted subject list (no user text ever reaches an email header) and a security-logged per-user rate limit — layered controls that make a CAPTCHA unnecessary there.
- Structured `[security]` event logging for failed logins, rate-limit hits and token-theft detection; admin mutations recorded in an append-only `audit_log`.
- Prod never leaks exception detail: controller-level catches log server-side and show a generic message; anything that bubbles past them is caught by the front controller, logged under a short reference id, and rendered as a generic 500 page.
- Outbound API calls carry connect + total timeouts so a hung upstream can't pin a PHP worker; the filesystem cache deserializes with `allowed_classes => false`, so a tampered cache file degrades to a miss, not an object-injection gadget.
- Prepared statements only; Twig autoescaping everywhere (no `|raw`); registration usernames whitelisted to `[A-Za-z0-9_]` server-side.
- Pre-commit + CI secret scan (`bin/check_no_secrets.sh`); PHPStan level 8, the full test suite and the coverage floor all required to pass before merge.

## License

Proprietary — © 2026 Micael Vinhas. Source available for transparency; not licensed for redistribution. The game-mechanics formulas in `config/secrets.php` are deliberately git-ignored.

Found a bug? [Open an issue](https://github.com/MVinhas/gpro-pitwall/issues).
