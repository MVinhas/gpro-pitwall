# Re-shooting the landing-page screenshots

The carousel on `templates/landing.twig` ships eight PNGs under
`public/assets/screenshots/` — one light and one dark per screen (cockpit,
strategy, wear, training). A UI change that alters any of those screens leaves
the landing page advertising a stale interface, so re-shoot them in the same
branch.

**Never shoot from a real account.** The landing page is public and indexed; a
real capture would publish the team name, driver, finances and division. Shoot
from the synthetic demo account below, which exists only for this purpose.

## 1. Isolated instance + synthetic upstream

Both servers run on the host (`flatpak-spawn --host`). The mock stands in for
the GPRO API so no real budget is spent and no real data can reach an image.

```bash
mkdir -p var/test/demo-shots
cp gpro_pilots.sqlite var/test/demo-shots/db.sqlite
```

Write `var/test/demo-shots/mock.php` — a router returning fabricated payloads
for `/office`, `/DriProfile`, `/TrackProfile`, `/UpdateCar`, `/RaceSetup`,
`/Calendar`, `/Tracks`, `/TyreSuppliers`, `/Menu`, `/NegOverview`, `/ViewStaff`.
Everything in it is invented: manager "Demo Manager", driver "Alex Demo",
group "Amateur - 42". Keep it that way.

Then wipe every real row from the copied DB and seed the one demo user:

```php
$db->exec("DELETE FROM users");            // plus verification_tokens,
$db->exec("DELETE FROM persistent_tokens"); // pending_registrations, audit_log
$u = $repo->create("demo", "demo@example.invalid");
$repo->markVerified((int) $u["id"]);
$repo->updateApiToken((int) $u["id"], "demo-token-not-real");
$repo->markSynced((int) $u["id"]);
```

Confirm `SELECT COUNT(*) FROM users` is exactly 1 before going further.

```bash
php -S 127.0.0.1:8021 -t var/test/demo-shots var/test/demo-shots/mock.php &
env RECAPTCHA_SITE_KEY= RECAPTCHA_SECRET_KEY= \
    DB_FILE=var/test/demo-shots/db.sqlite \
    GPRO_API_BASE_URL=http://127.0.0.1:8021 \
    CACHE_NAMESPACE=demo-shots \
    php -d variables_order=EGPCS -S 127.0.0.1:8020 -t public &
```

`CACHE_NAMESPACE` keeps the demo payloads out of the real `var/cache/`.

## 2. Log in and capture

Log in as `demo` via the curl flow in `.claude/skills/verify/SKILL.md` (the
code lands in `var/mail/`). Race Strategy needs a POST to
`/strategy_fragment` to produce results; splice that fragment into the page
before shooting.

For each screen and each theme, rewrite the stylesheet href to a `file://`
path, strip `fade-in`, stamp `data-theme`, force accordions open, then:

```bash
firefox --headless --screenshot "$PWD/out.png" --window-size=1280 "file://$PWD/page.html"
```

Width-only `--window-size` gives a full-page capture.

## 3. Crop, resize, commit

Crop 260 px off the top (header + nav chrome) to a 16:10 box, then resize to
**640×400** — enough at carousel size and keeps LCP sane. Save as
`public/assets/screenshots/{screen}-{light|dark}.png`.

## 4. Tear down

Stop both servers, `rm -rf var/test/demo-shots`, delete the login `.eml` from
`var/mail/`, and purge the demo entries from `var/cache/`.
