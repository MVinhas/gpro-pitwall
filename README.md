# GPRO Pitwall

Race-weekend cockpit for [Grand Prix Racing Online](https://www.gpro.net) managers. Pulls your own GPRO data via the public API and turns it into the answers you actually need before qualifying: what to train, what to swap, what to set, what to bet on weather.

**Live:** [gpro-pitwall.com](https://gpro-pitwall.com)
**Contact:** admin@gpro-pitwall.com
**Source:** [github.com/MVinhas/gpro-pitwall](https://github.com/MVinhas/gpro-pitwall)

Free for every registered user. No tier, no paywall. Voluntary support via [Buy Me a Coffee](https://buymeacoffee.com/mvinhas).

---

## Features

### Cockpit (the race-weekend spine)
One screen, in race-prep order:

- **PHA match** — track vs car Power/Handling/Acceleration alignment, with favourite-track and verdict cards.
- **Testing projection** — 100-lap forecast with 3-race decay (Test Points → R&D → Engineering → Car Character) so you see where the car actually lands.
- **Boost-lap fuel cost** — per-track dry/wet coefficient lookup.
- **Weather call** — Q1 / Q2 / race-start dry/wet assessment.
- **Sponsors** — ongoing negotiation list with per-negotiation characteristics and the recommended answer for each of the five negotiation questions.
- **Training picks** — gap-closer recommendations weighted against the division ideal.
- **Car wear panel** — per-part end-of-race wear projection with a live risk slider that re-runs without a page reload, plus a ranked list of swap options for every flagged part (filtered by your group's car-level band from `MoneyLevels` and your live cash from `Menu`).
- **"Set your race strategy" handoff** — one click to the Strategy tab pre-populated.

### Race Strategy
Fuel + tyre + setup for every compound (Extra Soft / Soft / Medium / Hard / Rain). Live risk slider; the calc auto-runs on first visit so you don't need to click Calculate. Best-compound highlighted; per-compound breakdown of lost time (pits / fuel / tyre-compound difference). Setup table for Q1, Q2 and race with weather-aware tyre choices.

### Car Wear
Per-part end-of-race wear forecast from your real driver attributes. Read-only driver stats pulled from the API (no manual entry). Risk slider. Per-part: level, start wear, estimated added wear, projected end wear (colour-coded by survival risk).

### Training Planner
Multi-program schedule — combine several programs in one shot, see the cumulative effect of every (program × count) combination with a sum-then-clamp model that respects the [0, 250] attribute bounds. Projected Overall Ability before/after for contract renegotiation context. Per-attribute delta chips and a full comparison table.

### Recruitment Analyzer
Scores the full GPRO driver market against your division's ideal pilot. Rating is anchored to the division baseline rather than a hand-tuned formula — the closer your ideal converges as you populate the baseline, the sharper the score gets. Self-improving feedback loop.

- Attributes below ideal: −0.1 per unit.
- Age: −2 per year older, +0.5 per year younger.
- Weight: −0.5 per kg heavier, +0.125 per kg lighter.
- Salary and fee don't count.
- Floored at 0, capped at 100. `MIN_RATING = 50` filter so the result set stays bounded on a full 4–5k-driver market.

**Favourite-track fit** — a compact `Fav` column flags how many of each candidate's favourite tracks are raced this season (`C·n`) and next season (`N·n`), green when matched, with the track names on hover. Computed from data already in hand — the market dump carries each driver's favourite tracks, and the season calendars come from the cache the per-user sync already warms — so it adds **no extra API call** and needs no per-driver lookup.

### Division Baseline / Division Differences *(admin-only)*
Per-division ideal-pilot tables with OA caps (Rookie 85 / Amateur 110 / Pro 135 / Master 160 / Elite ∞), plus pairwise comparison insights across divisions.

### Admin user management *(admin-only)*
`/admin/users` — paginated list, toggle admin flag (with self-demotion guard), resend verification, soft-delete. Every mutation is recorded in an append-only `audit_log` table. Audit panel shows the last 50 actions.

### Authentication
Passwordless: register/login with a one-time 6-digit code emailed to you (no passwords stored, ever). Login is rate-limited per IP; codes carry a TTL and a max-attempts cap. reCaptcha guards registration in prod.

- **"Keep me signed in"** — opt-in persistent login backed by a selector+validator token (hashed at rest, rotated on every use for theft detection, rolling 30-day window). Survives the short PHP session and is revoked on logout.
- **Step-up re-authentication** — sensitive actions (account deletion, API-token change) demand a fresh emailed code when the session was restored from a remember token rather than freshly verified.
- **Username recall** — the login form remembers the last username used on that browser (client-side `localStorage`, same-origin, no server state), so persistent-login users who rarely type it don't have to remember it.

### No-driver prompt
When your account has a calendar and tyre supplier but no pilot under contract, Cockpit / Race Strategy / Car Wear show a dedicated notice recommending the Recruitment Analyzer (with a direct link) instead of a cryptic error.

### Health endpoint
`GET /healthz` returns JSON with per-check status (DB reachable + cache roundtrip). 200 when both green, 503 when either fails. Built for an external uptime probe.

---

## Tech stack

- **PHP 8.5** (composer requires `>=8.3`; deploy targets 8.5).
- **Twig 3** templates.
- **Tailwind v4** compiled to a static asset (no CDN, no in-browser compile).
- **SQLite** via PDO. Encrypted user emails (AES-256-GCM) and API tokens at rest.
- **PHPMailer 7** for SMTP; in dev, writes `.eml` files to `var/mail/` instead.
- **PHPUnit 11** — 216 tests, 560 assertions, all green at **PHPStan level 7**.
- **No framework.** Custom front controller + flat DI container in `bootstrap.php`. Routes in `config/routes.php`.
- **Timestamps are stored and served as UTC**, then localised per-visitor in the browser (`<time data-localtime>` + `Intl`), so each user sees their own timezone with no server-side config.

---

## Local development

Zero-infra by design — no Docker, no Mailpit, no Redis, no APCu required.

```bash
composer install
cp .env.example .env             # then fill in values
php bin/seed_tracks.php          # bootstrap SQLite schema + seed tracks
bin/build_tailwind.sh            # compile public/assets/app.css
php -S localhost:8000 -t public  # dev server
```

In dev (`IS_DEV=true`), `EmailService` writes outgoing mail to `var/mail/*.eml` instead of hitting SMTP. Tail with:

```bash
php bin/dev_mail_tail.php
```

### `.env` keys you must set

| Key | Notes |
|---|---|
| `APP_SECRET` | 64-hex random; HMAC root for email hashes + verification codes |
| `EMAIL_ENCRYPTION_KEY` | 64-hex random; AES key for email + API-token storage |
| `IS_DEV` | `true` in dev, `false` in prod |
| `CACHE_DRIVER` | `filesystem` (default; zero infra), `apcu`, `redis`, or `none` |
| `MAIL_HOST` / `MAIL_PORT` / `MAIL_USER` / `MAIL_PASS` | SMTP credentials in prod |
| `MAIL_FROM` | Defaults to `admin@gpro-pitwall.com` |
| `MAIL_FROM_NAME` | Defaults to `GPRO Pitwall` |
| `RECAPTCHA_SITE_KEY` / `RECAPTCHA_SECRET_KEY` | Required in prod; bypassed when `IS_DEV=true` |
| `SYNC_SAFETY_MARGIN` | Default 20. The sync defers when `apiRequestsRemaining < calls + margin` |

Generate random keys with `openssl rand -hex 32`.

---

## Commands

```bash
composer install                       # Install deps
composer check                         # lint + analyse (PHPStan L7) + twig-lint + test
composer test                          # PHPUnit only
composer analyse                       # PHPStan only
composer lint                          # PSR-12

bin/build_tailwind.sh                  # Compile assets/css/app.css → public/assets/app.css
bin/build_tailwind.sh --watch          # Rebuild on every save
bin/build_release.sh                   # Assemble dist/gpro-pitwall/ for deploy
bin/build_release.sh --tar             # Also produce dist/gpro-pitwall.tar.gz
bin/seed_tracks.php                    # Initialise SQLite + seed tracks (one-shot)
bin/dev_mail_tail.php                  # Tail var/mail/ in dev
bin/db_browser.php                     # Local SQLite viewer (CLI only — never served)
bin/check_no_secrets.sh                # Pre-commit secret scan
bin/probe_security.sh <url>            # Post-deploy leak probe (must exit 0)
```

---

## Deployment

Source of truth is GitHub; deployment is a manual file copy to your host of choice.

1. Locally: `bin/build_release.sh --tar`. Produces `dist/gpro-pitwall.tar.gz` — a self-contained bundle with `vendor/` already installed (no dev deps), the compiled CSS, and the writable `var/` skeleton.
2. Upload `dist/gpro-pitwall/*` to your domain's web root.
3. On the host, configure:
   - **Document root** = `public/` *(the load-bearing setting — every sensitive file lives outside)*
   - **PHP version** = 8.5
   - **HTTPS** enabled
4. Create `.env` on the server. Set `APP_ENV=prod`, `IS_DEV=false`, fill SMTP credentials, generate fresh `APP_SECRET` + `EMAIL_ENCRYPTION_KEY` (never reuse dev keys — `openssl rand -hex 32`).
5. Set file permissions:
   ```bash
   chmod 600 .env
   chmod 640 config/secrets.php gpro_pilots.sqlite
   chmod 750 var var/cache var/mail var/log
   ```
6. Visit `/` — should render the landing page.
7. Run the probe:
   ```bash
   bin/probe_security.sh https://gpro-pitwall.com
   ```
   It must exit 0. Twenty sensitive paths must return 4xx; five public surfaces must return 200; four security headers must be present on `/`.

---

## Security posture

Reviewed against the OWASP Top 10:2025.

- CSRF token on every POST (validated in `public/index.php`).
- Email + API token encrypted at rest via AES-256-GCM with domain-separated keys derived from `APP_SECRET` / `EMAIL_ENCRYPTION_KEY`.
- `email_hash` (HMAC-SHA256) used for lookups so an attacker reading the DB can't enumerate users by email.
- Security headers in `public/.htaccess`: Content-Security-Policy, HSTS, X-Content-Type-Options, X-Frame-Options, Referrer-Policy, Permissions-Policy. HSTS + cookie Secure flag trust `X-Forwarded-Proto` so they still apply behind a TLS-terminating proxy.
- Session cookies HttpOnly + Secure (when HTTPS) + SameSite=Lax. Persistent "remember me" tokens store only a hashed validator, rotate on use, and are revocable.
- Login + registration are reCAPTCHA-gated and rate-limited per IP; verification codes have a TTL + max-attempts. A per-account code cap (`MAX_CODES_PER_USER_PER_HOUR`, default 3) bounds how many emails any one user can receive in an hour, so blind username-guessing on the login form can't spam real users regardless of source IP. A capped `/resend_code` link covers the rare missed-delivery case. Sensitive actions require step-up re-authentication.
- Centralised authorisation gate (`requireAuth` / `requireAdmin` / `requireFreshAuth`); every mutating, admin, and debug route is gated server-side.
- Security event logging (`SecurityLogger`) emits structured `[security]` lines for failed logins, rate-limit hits, and remember-token theft detection; admin mutations recorded in `audit_log`.
- Prod never leaks exception detail to clients — errors are logged server-side under a short reference id and the user sees a generic message.
- Prepared statements only (no string-concatenated SQL). Output XSS defence is Twig autoescaping (on everywhere, no `|raw`); user data is never interpolated into inline JS/event-handler attributes. New registrations additionally whitelist the username (`[A-Za-z0-9_]`, server-enforced), narrowing what can be stored — though that gate is not retroactive, so autoescaping remains the guarantee for pre-existing rows.
- Pre-commit + CI secret scan (`bin/check_no_secrets.sh`).
- PHPStan level 7 + PHPUnit suite required to pass before merge.

---

## Architecture

```
Request → public/index.php → Http\Router → Controller → Service → Repository → Twig
```

- `bootstrap.php` wires every dependency into a flat `$container` array — adding a service means adding one line.
- `config/routes.php` is the route table. No POST `action`-switch routing.
- Controllers are thin. Logic lives in services. Services talk to repositories; repositories own SQL.
- Cache adapters under `src/Cache/Adapter/` resolved by `src/Cache/CacheFactory`. Default driver `filesystem`.
- `src/Service/GproApiFetcher` does raw HTTP. `src/Service/GproApiClient` composes it with cache + endpoint naming.
- **Race-window cache keys.** Race-critical data (car wear, race setup, next-track profile) is namespaced by the current race window (`App\Support\RaceWindow`, computed from the clock against GPRO's weekly Tue/Fri schedule — no API call). The key rolls once per race weekend, so a stale read auto-refreshes a single endpoint at the boundary instead of serving last-window data within `CACHE_TTL_SHORT`. Schedule configurable via `GPRO_RACE_DAYS` / `GPRO_RACE_BOUNDARY_HOUR` / `GPRO_RACE_TZ`; empty `GPRO_RACE_DAYS` disables it.

---

## License

Proprietary — © 2026 Micael Vinhas. Source available for transparency; not licensed for redistribution. The game-mechanics formulas in `config/secrets.php` are deliberately git-ignored.

Found a bug? [Open an issue](https://github.com/MVinhas/gpro-pitwall/issues).
