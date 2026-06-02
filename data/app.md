# Control

Centrale admin- en CMS-laag voor de Wapps-stack. Draait op `dashboard.wapps.nu`.

## Drie rollen

1. **Host-dispatch + admin-overzicht**: dashboard toont alle vhosts (version, db-size, visitor-count, links naar Adminer/IDE/site). Vhost-bron is `frankenphp/*.caddy`.
2. **Visitor-tracking**: centrale `visitors` + `lookup` tabellen, gevoed door `heartbeat`-pingbacks vanaf alle apps in de stack.
3. **CMS-framework**: herbruikbare CRUD-laag (`cms/` als Phlo-resource-bron via `paths.resources: ["%app/cms/"]`). REST API, dashboard widgets, form-rendering, list-views, settings.

## Stack

- Phlo (FrankenPHP worker mode, php-zts)
- App-root .phlo: `app, host, overview, site, visitor` (monitoring + CMS-laag). De losse `metrics.phlo` (/system), `performance.phlo` (/performance) en `status.phlo` (/uptime) zijn verwijderd - overview dekt monitoring; de enige nog gebruikte helper (`remoteRows()`) staat nu op `overview`. Het React-prototype `dashboard/` is ook weg (nagebouwd in Phlo).

## Routes (overzicht)

- `/` -- dashboard met BI-search + widgets
- `/overview` -- systeem-dashboard (servers + 10 apps × dev/stage/prod)
- `/sites` -- vhost-overzicht (uit `/srv/control/sites/*.caddy`)
- `/visitors`, `/visitor/<id>` -- visitor-overzicht (read-only)
- `/api/$record/...` -- REST API (POST/PUT/PATCH/DELETE; CSRF nu gegate)
- `/create/$record`, `/change/$record/$id` -- forms
- `/settings`, `/set/theme`, `/set/trans` -- UI appearance
- `/bi` -- natural-language SQL via OpenAI (admin-only, kritiek security-punt)
- `/whatsapp/receive/<secret>/...` -- WhatsApp webhook (secret in path; te HMAC-en)

## Externe deps

- ipgeolocation.io (visitor-lookup); API-key in `creds.ini`.
- OpenAI (BI-search); API-key in `creds.ini`.
- echarts (dashboard-widgets); self-hosted in `www/chart.js`.
- Adminer (DB-admin); mount op `/q/adminer.php`.

## Vhosts (15)

Bron: `frankenphp/*.caddy`. Caddy `import /srv/control/frankenphp/*.caddy` in main Caddyfile.

Inclusief: app.wapps.nu, dashboard.wapps.nu, dev.app.wapps.nu, delta.*.wapps.nu, factuur.software, files.wapps.nu, logbook.tools, pleroma, prod.delta.*, sr.backoffice.wapps.nu, sr.pos.wapps.nu, sronline.wapps.nu.

## Deploy-scripts

- `release.phlo.sh` -- naar phlo-target + q-dev (atomic-swap niet ingericht; risicovol).
- `release.messenger.sh` -- naar messenger (heeft set -euo pipefail).

## Auth

HTTP Basic via Caddy (`data/auth.ini`). Roteer naar bcrypt-hashed of full login-flow voor productie (zie security-prompts).

## DB

MySQL `control`. Tabellen:
- `visitors` -- visitor-events (heartbeat-trigger).
- `lookup` -- IP-geolocation cache (van ipgeolocation.io).
- `bi.json` -- file-based BI-search cache (groeit onbeheerst; cleanup nodig).

Heartbeat-pattern: andere apps pingen via `%req->path === 'heartbeat'`-route, dat schakelt naar `control`-DB.

## Theming

- Thema's/transities zijn **engine-resources** (`%phlo/resources/themes/*`, `transitions/*`), geactiveerd door ze in `data/app.json` `resources` te zetten. De builder schrijft per `ns` een `www/theme.*.css` / `www/trans.*.css`; settings-dropdowns scannen `www/`.
- Default-thema: **cobalt** (`app.phlo` prop `theme`), default-transitie `cards`. Cobalt = grijs/blauw/emerald, matcht het React-ontwerp in `dashboard/`.
- **CMS-library is uitgelijnd op het standaard Phlo-thema-vocabulaire** (11 vars: `$bg $surface $text $muted $border $primary $on-primary $focus $success $warn $error`). Oude eigen varnamen verwijderd: `$background→$bg`, `$link→$primary`, `$warning→$warn`, `$surface-alt→$border`, `$row-odd→transparent`, `$row-even/$row-hover→color-mix(...)`. Hierdoor werkt élk engine-thema met de CMS.
- De **React-look** (`dashboard/` prototype) is overgenomen als **lokale styling in de app-views** - géén eigen `ns=`-thema/assets. `CMS/` is een gedeelde library: daar is alléén var-alignment gedaan, geen restyle.
- **`/overview` ("🏠 Overzicht") is het monitoring-dashboard** (spec: 10 apps, 2 servers). Layout: header + 2 server-widgets (Productie=q-ai, Dev/Stage=qdev - uptime + CPU/geheugen/disk progress-bars) + een **applicatie-tabel**.
- **Applicatie-tabel** werkt met een **vaste lijst van 10 apps** (`overview::apps()`), niet met alle ~47 hosts. Per app expliciete env-hosts: prod `<app>`, dev `dev.<app>`, stage `stage.<app>` (uitzondering q-dev.nl → dev `dev.qdev.nl`, stage `stage.qdev.nl`). Kolommen: Applicatie · Bezoekers · Prod/Dev/Stage · Errors.
  - Vormgeving volgt `dashboard/` (React-prototype): inline SVG-iconen (`overview::icon()`, lucide-paths in `overview::icons()`), check/x per app, users-icoon bij bezoekers, gradient-titel + "Laatste update"-balk, server-cards met server/klok-iconen + progress-bars (disk in paars #a855f7).
  - De drie omgevingen staan in één genestelde **ping-box** (`.ping-box` met `.ping-div`-scheiders): per env status-dot + **HTTP-code** + slot-icoon als `status.authed=1` (basic auth) + ping. States: up (2xx/3xx), auth (401/403), warn (4xx), down (0/5xx). Ontbrekende env → "–". Elke env-chip linkt naar de site.
  - Bezoekers = `COUNT(DISTINCT token)` per prod-host (all-time). Errors = aantal checks met code 0/≥500 in 24u (prod+dev+stage).
- `status`-tabel heeft kolom **`authed`** (tinyint): 1 = checker passeerde basic auth. Niet-gemonitorde prod-hosts (bv. `factuur.software`, `wapps.nu`) ontbreken in `status` → tonen "–" (monitoring-gat, geen bug).
- `/` blijft de CMS-library dashboard. Menu: **Overzicht · Bezoekers · Sites**; routes `/uptime`, `/performance`, `/system` bestaan nog (niet in nav).
- **Visitors**: `visitor::lookup()` returnt `null` voor leeg/privé/reserved IP (geen API-call, geen onzin-locatie); untracked bezoekers (geen consent → leeg IP) tonen "untracked" i.p.v. foute geo. `country`/`city`/`isp`-labels zijn null-safe (`?->`). `referrer` staat `list: true` (search-engines als label, directe bezoeken als "direct"). Referrer-capture zit in de engine (`document.referrer`) - er is gewoon weinig referred verkeer.
- **/visitors-vormgeving** (alles in `visitor.phlo`): landvlag-emoji via `visitor::flag($cc)` in de locatie-kolom; device-emoji via `visitor::deviceIcon()` in Client; `kind`-prop + virtual `kind`-veld ("Type"-kolom) met badge real/bot/untracked (`.vkind`-CSS, bot = UA-match of IP 66.249.*); `requests` ("Pagina's") op `list: true`.

## Bekende issues

Zie `data/errors.json`. Plus:
- Pinfo route weg (in deze opschoning).
- `die('Invalid request')` -> `apply(error: ...)`.
- `sites/` directory mismatch in `site.phlo` -> nu gefixed naar `frankenphp/*.caddy`.
- `us` undefined constant -> gefixed naar `'us'`.
- Regex in `site.phlo:7` voor nginx-style `server { }` blocks; matched NIET op Caddy syntax. `/sites` toont nu mogelijk lege lijst tot regex is aangepast naar Caddy-block-headers.
- Hardcoded version `1.0.1` in app.phlo (geen acute fix).

## Security-attentie

Lopende prompts in `/srv/prompts/`:
- BI-LLM-SQL-injection (HOOG): `/bi` voert raw SQL-fragmenten uit van LLM-output. Read-only DB-user nodig.
- CSRF nu bedraad: `security/CSRF` in `data/app.json` + `%app->head = %CSRF->view` in `app.phlo` rendert `<meta name=csrf>` in de head (frontend stuurt `X-CSRF-Token`). `CMS.API` verifieert per write. Was eerder niet geladen → API-writes faalden met "Class CSRF not found" (latent: control heeft alleen het read-only `visitor`-model).
- Adminer-URL met `username=root` (gefixed in deze opschoning).
- IPGeolocation API-key in URL (kandidaat voor header-auth).
- HTTP_REFERER-redirect na PATCH (validatie nodig).
- WhatsApp-webhook secret in URL-pad (vervang door HMAC-signed payload).
- Release-script `rm -rf /srv/phlo` zonder backup (atomic-swap rewrite).

## Niet in git

`/srv/control/` is GEEN git-repository. Wijzigingen zijn lokaal-only. Voor rollback: handmatige backup van `.phlo`-bestanden voor wijzigingen.

## Verifieer

- Build: `php-zts /srv/control/www/app.php build::run`
- Lint: `php-zts /srv/control/www/app.php build::lint`
- Sites-route: `dashboard.wapps.nu/sites` toont vhosts (na regex-update; zie issues).
