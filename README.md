# GPRO Pitwall

[![CI](https://github.com/MVinhas/gpro-pitwall/actions/workflows/ci.yml/badge.svg)](https://github.com/MVinhas/gpro-pitwall/actions/workflows/ci.yml)
[![Release](https://img.shields.io/github/v/tag/MVinhas/gpro-pitwall?sort=semver&label=release&color=blue)](https://github.com/MVinhas/gpro-pitwall/tags)
[![PHP 8.5](https://img.shields.io/badge/PHP-8.5-777BB4?logo=php&logoColor=white)](composer.json)
[![PHPStan level 8](https://img.shields.io/badge/PHPStan-level%208-4F5B93)](phpstan.neon)
[![Coverage floor](https://img.shields.io/badge/coverage-%E2%89%A545%25%20CI--enforced-yellow)](.github/workflows/ci.yml)
[![PSR-12](https://img.shields.io/badge/code%20style-PSR--12-blue)](https://www.php-fig.org/psr/psr-12/)

![GPRO Pitwall — race strategy, setup calculator and car wear analysis for GPRO managers](public/assets/og-image.png)

Race-weekend cockpit for [Grand Prix Racing Online](https://www.gpro.net) managers. Pitwall reads your own GPRO data through the official public API and turns it into the answers you need before qualifying: what to train, which parts to swap, what setup to run, how hard to push — and what to bet on the weather.

- **Live:** [gpro-pitwall.com](https://gpro-pitwall.com) — free for every registered user; no tiers, no paywall
- **Contact:** admin@gpro-pitwall.com · [open an issue](https://github.com/MVinhas/gpro-pitwall/issues)
- **Support:** voluntary, via [Buy Me a Coffee](https://buymeacoffee.com/mvinhas)

Getting started takes two minutes: register with your email (passwordless — a one-time code, no password ever stored), paste your GPRO API token in the Control Panel (encrypted at rest), and every tab fills in with your driver, car, team and next race.

---

## Features

Everything sits behind a sticky tab bar — scrollable pills on mobile, underline tabs on desktop — grouped by intent: **race weekend** (Cockpit · Strategy · Car Wear · Testing), **team building** (Training · Recruitment) and admin. Every page shares one visual system (shared cards, one-line notices, verdict-first accordions that stay informative while closed) and works at 375 px and without JavaScript.

### Cockpit — the race-weekend spine

One screen in race-prep order. A **decision summary board** leads: one verdict tile per card, so every call is readable without scrolling; each tile jumps to and opens its card.

- **PHA match** — car vs track Power/Handling/Acceleration alignment, with a favourite-track badge. Only strict matches count: **top** (your car's #1 attribute is the track's #1) or **perfect** (all three ranks align).
- **Testing projection** — 100-lap forecast with 3-race decay (Test Points → R&D → Engineering → Car Character), so you see what actually lands in the car.
- **Boost-lap fuel cost** — per-track dry/wet coefficients.
- **Weather call** — Q1 / Q2 / race-start dry-wet assessment for the *upcoming* race. Track identity comes from feeds that roll over the moment a new race opens, so the cockpit is correct even before you've saved a setup in GPRO — and it withholds the forecast (with a notice) rather than show the previous race's weather.
- **Sponsors** — ongoing negotiations with the recommended answer for each of the five negotiation questions.
- **Training picks** — gap-closers weighted against your division's ideal driver.
- **Car wear panel** — per-part end-of-race wear with a live risk slider. Flagged parts land in one status table (Part · Lvl · now% → end% · verdict); each part's swap options are ranked, filtered by your group's car-level band and your live cash, and collapse to their best pick. A reference table of each part's PHA contribution per level is one click away.
- **Handoff** — one click to the Race Strategy tab, pre-populated.

### Race Strategy

Fuel, tyres and setup for every compound (Extra Soft / Soft / Medium / Hard / Rain), auto-run on first visit, re-run live by a risk slider. The **verdict leads**: best compound, stops, fuel per stint, total time lost and the margin over the runner-up — always compared within the same tyre type (a dry race compares dry compounds only; a wet race recommends Rain outright — it's the only wet compound). Below it: a per-compound breakdown of time lost (pits / fuel / compound difference), the Q1 / Q2 / race setup table with weather-aware tyre choices, and your contracted tyre supplier's dry/wet rating and ideal temperature beside the track name.

#### Advice from the Race Engineer ⭐

The headline feature. A race engineer that reads your driver, the track and the forecast, then tells you in plain words how to fill the race form:

> *"Overtaking at Barcelona is hard, so I'd push overtake up to 60 to make moves stick — and since the track already makes you hard to pass, 35 on defence is plenty. Grip here is low — sliding cars punish ambition, so I've shaved both numbers."*

- **Overtake and defend risk dials (0–100)** — weighed from the track's overtaking rating, the driver (concentration and experience carry dry races; talent takes the wheel in the wet), aggression both ways (backed by experience it buys pace; beyond it, it's the mistake trap), the forecast, track grip, tyre wear, and stamina on long races.
- **Boost-lap placement** — early in traffic, on the in-laps to overcut through the pit cycle, or at the flag — pit-window aware via the best strategy's stint plan.
- **Race start approach** and the **technical-problem pit threshold**, derived from driver control and this track's pit-lane time.
- **Pit-count tie-breaker** when two strategies are close on paper, and a race-distance note when the length is worth flagging.

Honest by design: a transparent heuristic built from the game's own attribute semantics, not a reverse-engineered formula — and it says so right in the box.

#### Push or hold? ⭐

A checklist that turns binary signals into one read for your **Clear Track Risk** dial: car–track PHA match, driver favourite track, tyres suiting the race, track temperature near the tyre's ideal, car level and driver ability ranked against your group, and whether the car has the wear headroom to absorb a push. More signals met → the weekend is set up in your favour, carry more risk; a full sweep points to a very likely win. Signals that don't apply to your division are hidden, not failed.

### Car Wear

Per-part end-of-race wear forecast from your real driver attributes (read-only, pulled from the API — no manual entry). Risk slider; per-part level, start wear, added wear and colour-coded projected end wear.

### Testing

Everything for a testing session: the testing track's demands vs your car, the points split across Test / R&D / Engineering / **Car Character** (highlighted — it's what actually lands in the car), points gained per 5 laps for each testing priority, the ideal setup for the track, and a slider-driven wear projection.

### Training Planner

Combine several training programs in one shot and see the cumulative effect of every program × count combination, with attribute bounds respected and projected Overall Ability before/after — useful context for contract renegotiation.

### Recruitment Analyzer

Scores the full GPRO driver market (4–5k drivers) against your division's ideal pilot — attribute gaps, age and weight priced in; salary and fee ignored. Value-range filters per attribute persist across sorting and pagination, and a compact `Fav` column flags how many of each candidate's favourite tracks are raced this season and next — computed from data already in hand, no extra API calls.

### Admin

- **Division baseline / differences** — per-division ideal-pilot tables with OA caps (Rookie 85 / Amateur 110 / Pro 135 / Master 160 / Elite ∞), plus pairwise division insights.
- **User management** — paginated, sortable user list with growth trends over a selectable 7/30/90-day window; admin-flag toggle with self-demotion guard, soft-delete/restore, and every mutation in an append-only audit log.
- **Telemetry** (`/debug`) — registered vs active users (successful sync in the last 30 days), tokens set, API budget, runtime info, masked environment.

### Accounts

- **Passwordless** — register and log in with a one-time 6-digit emailed code; no passwords stored, ever. Rate-limited, TTL'd, attempt-capped, reCAPTCHA-guarded in prod.
- **Verified-only namespace** — a registration only becomes an account once its code is verified; an unverified or bounced sign-up can never squat a username or email.
- **Keep me signed in** — opt-in persistent login (hashed validator, rotated on every use for theft detection, rolling 30-day window), with **step-up re-authentication** for sensitive actions like account deletion or API-token changes.
- **In-app feedback form** — whitelisted subjects, delivered with Reply-To set to your account address, used only to reply — never for marketing.

Also in the box: a `/healthz` endpoint for uptime probes, full SEO and social-sharing markup, styled error pages, and a friendly no-driver prompt that points new accounts at the Recruitment Analyzer instead of a cryptic error.

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
- **Twig 3** templates; **Tailwind v4** compiled to a static asset (no CDN, no in-browser compile).
- **SQLite** via PDO — emails and API tokens encrypted at rest (AES-256-GCM).
- **PHPMailer 7** for SMTP; dev writes `.eml` files instead.
- **PHPUnit 13** — 365 tests, 937 assertions — with **PHPStan level 8** and enforced type-declaration coverage (100% return/property/constant + `strict_types`; 99.5% param). Twig linted by a native `bin/twig_lint.php` built on Twig's own parser. CI measures statement coverage with `pcov` and enforces a floor (currently 45%, ratcheted up as coverage grows).
- **Timestamps stored and served as UTC**, localised per visitor in the browser — no server-side timezone config.

## Architecture

```
Request → public/index.php → Http\Router → Controller → Service → Repository → Twig
```

- Controllers are thin; logic lives in services; repositories own the SQL (prepared statements only).
- `bootstrap.php` wires every dependency into a flat container — adding a service is one line.
- Cache adapters (`filesystem` default, APCu, Redis, none) behind one interface, resolved by `CacheFactory`.
- **Host-wide outbound throttle** — all GPRO API calls leave from one IP, so a token bucket shared across PHP workers (a `flock`'d state file) paces real fetches under burst load; cache hits never touch it. It never throws — worst case is "slightly slower", not a failed page. Complements the per-token budget guard (`SYNC_SAFETY_MARGIN`).
- **Race-window cache keys** — race-critical data is namespaced by the current race window (computed from the clock against GPRO's Tue/Fri schedule, no API call), so caches roll over exactly once per race weekend instead of serving last week's data until TTL. Configurable via `GPRO_RACE_DAYS` / `GPRO_RACE_BOUNDARY_HOUR` / `GPRO_RACE_TZ`.

## Security posture

Reviewed against the OWASP Top 10:2025.

- CSRF token on every POST, validated in the front controller.
- Emails and API tokens encrypted at rest (AES-256-GCM, domain-separated keys derived from `APP_SECRET`); lookups use HMAC-SHA256 email hashes, so a stolen DB file can't enumerate users. The key lives in `.env` on the same host, so this protects a stolen database file — not against an attacker with arbitrary file read on the server.
- The decrypted GPRO API token is never sent back to the browser: the Control Panel shows a masked last-4 hint and accepts a new value (blank = unchanged); the token is also stripped from the shared Twig `user` global.
- Login leaks nothing about whether a username exists: unknown usernames produce a decoy pending state that routes to `/verify` identically to a real account (and can never verify).
- Security headers in `public/.htaccess`: Content-Security-Policy, HSTS, X-Content-Type-Options, X-Frame-Options, Referrer-Policy, Permissions-Policy — proxy-aware via `X-Forwarded-Proto`.
- Session cookies HttpOnly + Secure + SameSite=Lax. "Remember me" tokens store only a hashed validator, rotate on every use for theft detection, and are revocable.
- Login and registration are reCAPTCHA-gated and rate-limited per IP; verification codes carry a TTL, an attempt cap, and a per-account hourly email cap, so blind username-guessing can't spam real users. Sensitive actions require step-up re-authentication.
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
