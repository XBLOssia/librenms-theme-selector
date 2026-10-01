# Theme Selector plugin — design

Turning the three skins into a LibreNMS package plugin: skins as data, one
interpretation layer that applies them, per-user selection, and admin-managed
custom skins.

Written 2026-09-25 against LibreNMS master @ `63e0394`. Master was 39 commits
ahead at the time; the only plugin-relevant change among them is noted under
[Caveats](#caveats).

---

## Goal

1. **Each skin lives in its own file** — a small set of token values, not a
   70KB stylesheet.
2. **An interpretation layer** turns the selected skin into overrides of stock
   styling on every page.
3. **Admins add and remove custom skins** from the UI.
4. **Each user picks their own skin.** Only admins can add or remove.

## Decisions

Settled 2026-09-25.

| Question | Decision |
|---|---|
| Skin format | **Shared base stylesheet + per-skin token files.** The base maps LibreNMS components onto custom properties; a skin supplies values. |
| Light mode | **Skins may support both modes.** The format allows a light and a dark token set; the current three stay dark-only for now. |
| Graph colours | **Instance-wide in v1, per-user as a later phase** once per-request config override is proven. |
| Custom skin upload | **Zip bundle** — manifest, token file, optional fonts, optional graph palette. Validated before install. |
| Names | Package `xblossia/librenms-theme-selector`. Display name **Theme Selector for LibreNMS**; short name **Theme Selector**, `ThemeSelector` where spaces aren't allowed (plugin name, PHP namespace). |
| Admin permission | **LibreNMS's own `admin` role** (`can:admin`), not `plugin.admin` or a plugin-specific permission. One less knob. |
| Install paths | **The plugin only.** `install.sh`/`uninstall.sh` are deleted (still in git history); the one existing install, the production host, was migrated by hand on 2026-09-28/29. |

---

## What upstream allows

This section overturns an earlier conclusion. README, FINDINGS §6 and
DEPLOYMENT all say the plugin system cannot carry CSS. That is true of the five
**hooks** — none touches `<head>` — but a **package** plugin also owns a Laravel
service provider, and a provider can do anything Laravel allows.

### Getting a stylesheet onto every page

`resources/views/layouts/librenmsv1.blade.php:42-51` builds `<head>` in this
order:

```
styles.css → tw_dark.css → css/<site_style>.css (blue/mono only)
           → webui.custom_css[] → @yield('css') → @stack('styles')
```

Core already attaches a view composer to that layout
(`app/Providers/ComposerServiceProvider.php:41`), and Laravel allows several per
view. So the plugin's provider does:

```php
View::composer('layouts.librenmsv1', function () {
    // resolve the user's skin, then
    View::startPush('styles', '<link rel="stylesheet" href="...">');
});
```

- Lands in `@stack('styles')` — **after** `custom_css`, so it wins the cascade
  at equal specificity.
- Covers legacy pages (`layouts/legacy_page.blade.php:1` extends librenmsv1),
  login and 2FA, plugin pages, and ~110 other views. Not covered:
  `layouts.error`, `layouts.install`.
- Verified against Laravel v12's `ManagesStacks::startPush` and the order
  `View::renderContents` calls composers. **Not yet verified on a live host.**

Rejected alternative: overriding `webui.custom_css` per request.
`LibrenmsConfig::set()` is in-memory only, so it would work — but done in
middleware it also applies to the ajax requests behind the settings UI, and an
admin saving settings there could persist one user's skin as global.

### Per-user preference

`App\Models\UserPref` (`users_prefs` table) stores arbitrary per-user keys with
no schema change: `UserPref::setPref($user, 'theme_selector.skin', 'zerg')`.
Values are JSON-decoded on read.

**There is no hook for the Preferences page**, and its store action whitelists
pref names (`UserPreferencesController.php:67-89`). The picker has to live on
the plugin's own page, reached from a `MenuEntryHook` in the navbar Plugins
menu.

Resolution order: user pref → plugin's admin default → none (stock).

### Authorization

- `Gate::define('admin')` = `hasRole('admin')`; `Gate::before` grants admins
  everything (`AppServiceProvider.php:249-266`).
- Spatie permission `plugin.admin` exists; upstream plugin admin routes use
  `can:plugin.admin` (`routes/web.php:309`).
- Plugin routes: picker under `['web','auth']`; upload/delete/default under
  `can:admin` — the core admin role, by decision.
- Type-hint `authorize()` as `Illuminate\Contracts\Auth\Authenticatable`.
  `App\Models\User` silently injects an unauthenticated model (lesson from the
  Network Command Suite plugin; upstream PR #20543 is converging on the same).

### Where skin files live

The webroot is `html/`. There is no public disk and no storage symlink.

**`html/css/custom/theme-selector/`** — `base.css` plus `skins/<id>/`. Gitignored, documented for
exactly this use (`doc/Support/Configuration.md:396-403`), served statically,
survives `daily.sh` (it pulls, never cleans), and relative `url(fonts/...)`
keeps working. Needs the fpm user to have write access.

Bundled skins ship inside the package under `vendor/`, which is **not**
web-served, so the plugin copies them into that directory — on enable, and again
whenever the package version changes.

Serving through a plugin controller from `storage/app` was considered and
rejected: PHP plus session on every CSS and font request, and a path-traversal
surface.

No CSP or security headers found in app, config or `.htaccess`. The target
host's nginx config has not been checked.

### Install and updates

- Composer `"type": "package"`, auto-discovered via `extra.laravel.providers`.
  Reference: murrant/librenms-example-plugin.
- Production: `lnms plugin:add vendor/pkg`, as the librenms user. This records
  the package in the gitignored `composer.plugins.json`.
- `daily.sh` resets `composer.json`, then re-requires everything in
  `composer.plugins.json` and runs `composer install --no-dev`
  (`daily.sh:302, 361-367`). **Plugins survive updates.**
- A path or VCS repository must go in the **global** composer config
  (`composer config --global repositories...`), not LibreNMS's `composer.json`.
  The Network Command Suite deploy learned this the hard way:
  the local entry was wiped by `daily.sh` and the failed `composer require`
  took M365 SSO down with it.

### Caveats

- **A hook that throws disables the plugin**, and it stays off until
  re-enabled (`PluginManager.php:111-131`). Every hook must catch everything.
  The view composer is not a hook and is not protected this way — an exception
  there breaks page rendering outright. It must catch everything too and fall
  back to "no skin".
- **Plugin settings can be deleted.** `cleanupPlugins()` hard-deletes a v2
  plugin's row — settings included — if it published no hook that request
  (`PluginManager.php:187-195`). Always publish at least one hook; keep user
  data and the skin registry in `users_prefs` and a plugin table, never in
  plugin settings.
- **`App\Plugins\Hooks\SettingsHook` bug at `63e0394`**: `data()` is called
  twice, nesting `settings` (`SettingsHook.php:53`). Fixed on master in
  `ecd7b4d` (#20572). Implement the interface directly rather than extending
  the abstract.
- **`librenms/plugin-interfaces` has one release (1.0).** Hooks are UI slots
  only and are still being fixed upstream (#20498, #20287).
- **Selector drift.** murrant has said the legacy colours need to move to
  Tailwind before theming is "much more realistic" (#19029). The base
  stylesheet will need maintenance as that migration lands.

---

## Skin format

### Package layout

```
<skin>/
  skin.json        manifest: id, name, author, version, modes
  skin.css         the token file (below)
  fonts/           optional .woff2 files
  LICENSE.txt      optional licence notice (stored, shown to admins, never served)
  textures/        optional .png tiles (cleaned and embedded; never served as files)
  graph.json       optional graph palette (instance-wide in v1)
```

### The token file

`skin.css` is **a restricted CSS file, not a stylesheet**: custom-property
declarations inside a mode wrapper, plus `@font-face` for the bundle's own
fonts. Nothing else. It started out as a JSON file the plugin would compile
into CSS; making it CSS directly means the browser and the harness load it
with no build step, and it stays one file per skin. The upload check parses
it against the same allow-list a JSON schema would have, so the security model
in PROPOSAL.md ("a manifest, not a stylesheet") carries over unchanged:

- **Structure:** top-level blocks are only `html.dark { ... }` (later also the
  light-mode wrapper) and `@font-face { ... }`. No other selectors, at-rules,
  `@import`, or nesting.
- **Names:** `--ts-*` is the base stylesheet's API and must be a known token;
  unknown ones are rejected, not ignored. `--p-*` is the skin's private palette,
  free-form, for its tokens to reference. Nothing else, so a skin can't touch
  core variables such as `--tw-color-*`; the base does that retint.
- **Values:** colours, lengths, numbers, gradients, shadows, filters,
  font-family lists, `var(--ts-*)`/`var(--p-*)` references, and a small set of
  keywords. No `url()` outside `@font-face`, no `expression`, `image-set`,
  `element()`, no `;`/`{`/`}` or backslash escapes smuggled inside a value.
- **`@font-face`:** `src` only as `url("fonts/<file>.woff2") format("woff2")`,
  pointing at a file that exists in the bundle.

Fonts inside the bundle are same-origin, so bundling them does not reintroduce
the remote-font beacon problem PROPOSAL flagged.

Every token has a default in `base.css`, so a skin sets only what it changes;
the 20 core roles are enough for a complete skin. The full list, with
defaults and where each is used, is `docs/TOKENS.md`.

Anything the three current skins do that cannot be expressed as a token belongs
in the base stylesheet — possibly behind a token that switches it on — not in a
per-skin raw CSS escape hatch.

### Zip validation

As built (2026-09-29; the design here originally allowed `.ttf` and `.txt`
and extracted files, and neither survived a threat model): the reader parses the
zip itself, extracts nothing, and accepts only `skin.json`, `skin.css`,
`graph.conf`, `LICENSE.txt`, `fonts/<slug>.woff2|woff` and `textures/<slug>.png` by exact name. Fonts are embedded in
the generated stylesheet, so no uploaded file is served as a file. The full
list of controls, each with the test that would notice it breaking, is
[SECURITY.md](SECURITY.md); the format is [AUTHORING.md](AUTHORING.md).

### Light and dark

Today every skin rule is prefixed `html.dark`, giving (0,2,1) to beat
`tw_dark.css`'s `.dark .x` (0,2,0) without `!important`. With two modes:

- A token file gains a second wrapper, `html:not(.dark) { ... }`, for its light set.
- The base must only apply in a mode the active skin supports, so a dark-only
  skin leaves light mode stock. One option: the pushed `<head>` content also
  sets `data-ts-modes="dark"` on `<html>` via a two-line inline script, and
  base rules are scoped `html.dark[data-ts-modes~="dark"]`.
- Light mode fights stock light rules that are unprefixed but sometimes
  `!important` inside Tailwind layers; FINDINGS §2 covers reaching those by
  retinting `--tw-color-*`.

This is the first thing to prototype in the harness. Specificity must still
beat stock in both modes.

---

## Repo layout

The repo becomes the plugin package. Skins, harness and docs stay.

```
composer.json              xblossia/librenms-theme-selector, type "package"
src/
  ThemeSelectorProvider.php  wiring: hooks, composer, middleware, routes
  Skin/                      the upload validator: zip reader, token-file parser,
                             value grammar, font/graph/manifest checks, output guard
  SkinInstaller, SkinRegistry, SkinRepository, SkinPublisher, DefaultSkin
  GraphPalette, GraphColours (middleware), SkinResolver, SkinInjector
  Http/Controllers/PickerController.php
  Console/                   theme-selector:publish, theme-selector:validate
routes/web.php
resources/views/             the picker and admin page
resources/token-catalog.json which tokens exist / which uploads may set (generated)
database/migrations/         settings and uploaded-skin tables
base/base.css                the interpretation layer's stylesheet
skins/<id>/                  bundled skins: skin.css, skin.json, graph.conf, fonts/
examples/minimal/            the smallest complete skin
tests/                       php tests/run.php: validator, installer, fuzz; mutate.sh
harness/                     preview pages, colorway, leaks.html
dev/                         Docker instance, end-to-end tests, test.sh
scripts/                     patch-core.sh, daily-wrapper.sh, coverage, fonts, token docs/catalog, pack-skin.py
```

---

## Phases

**0 — Injection spike. Done 2026-09-28**, on the `dev/` instance: installed
through a real `lnms plugin:add`; per-user choice saved from the picker; the
skin's links land after `webui.custom_css` on Laravel and legacy pages; a user
with no choice gets stock; stylesheet and fonts served with correct types.
Not yet proven: the login page (the dev instance logs in by header, so it never
shows one) and surviving a real `daily.sh` run (the Docker image has no git
checkout to update); both are checked during the production migration.

**1 — Base + tokens. Equivalence done 2026-09-28; the tooling was retired
2026-09-29.** `base/base.css` (47KB,
shared) plus a `skin.css` of 18-19KB per skin, down from ~70KB standalone
(sizes before 1b).
Verified two ways (both checks compared against the original standalone skins,
which were deleted on 2026-09-29 once production had migrated and `base.css` had
legitimately diverged from them; `harness/leaks.html` is the check that
replaced them, and git history keeps the originals and the tooling):

- *Rendered:* `harness/compare.html?all=1` loads each harness page with the
  original skin and with base + tokens and compares every element's computed
  style, `::before`/`::after` included. 0 differences on both pages for all
  three skins. The one tolerated difference is style or colour of a
  zero-width border side, which can't render.
- *Textual:* substituting each skin's tokens back into `base.css` reproduces
  every declaration of the original, rule by rule, including hover/focus
  states and elements the harness pages don't render.

Where a skin never declared a property that another skin did, its token file
now carries the stock value explicitly (measured from the original in the
harness, or read from stock CSS for elements the harness doesn't render).

**1b — Token API. Done 2026-09-28.** Every one of the 301 tokens now has a
default, in a block at the top of `base.css`; `skin.css` loads after it, so a
skin sets only what it changes. Defaults, by source:

- **20 core roles** (`bg`, `surface`, `border`, `text`, `accent`, `success`,
  `font-display`, `radius-sm`...): stock LibreNMS dark. A skin normally sets
  these, and they're enough on their own: `examples/minimal/skin.css` is only
  the core roles, and renders as a complete skin in the harness
  (`?skin=example`). With no token file at all (`?skin=defaults`), the result
  is close to stock dark.
- **18 derived roles** (`success-deep`, `font-code`, `radius-lg`...): mixes of
  the core roles.
- **132 component tokens** the three skins all set differently: restrained
  defaults built from the roles. Flat surfaces, one soft shadow, accent edges
  only where they carry meaning (active tab, focus, row status), no
  decoration.
- **127 component tokens** that are stock in some skins or shared by two: that
  value.

Tokens were renamed from generated names to `component-part-property`
(`--ts-navbar-default-navbar-nav-li-a-hover-bg` → `--ts-navbar-link-hover-bg`).
Every bundled-skin token equal to its default was dropped: Terran 207 tokens
(12KB), Protoss 246 (15KB), Zerg 239 (16KB); `base.css` is 60KB with the
defaults. Both equivalence checks still pass with 0 differences.

`docs/TOKENS.md` is the token reference, generated from `base.css` by
`scripts/gen-token-docs.py`.

**Found during 1b, fixed 2026-09-28: the skins broke LibreNMS's sticky
navbar.** Core pins the navbar with `nav.navbar-sticky-top { position: sticky }`
(`styles.css:1306`, specificity 0,1,1). All three original skins set
`html.dark .navbar-default { position: relative }` (0,2,1), which won, so with
any skin active the navbar scrolled away instead of staying pinned. The skins
wanted a positioned box for the navbar's `::before`/`::after` decorations, and
`sticky` already is one, so the declaration is gone from `base.css` and from
the original `<id>.css` files (which production still loads). The harness now
uses the real `navbar-sticky-top` markup; its old `navbar-fixed-top` markup is
why the bug never showed there.

**2 — v1 plugin, and migrate production. Plugin done 2026-09-28; migration
pending.** On the `dev/` instance:

- *Publishing:* the plugin copies `base.css` and the bundled skins (token
  file, manifest, graph palette, fonts) into `html/css/custom/theme-selector/`
  on the first request after the package changes, detected by a fingerprint of
  paths, sizes and mtimes. `lnms theme-selector:publish` does it on demand.
  Tested: touching a skin in the package republished it on the next page load.
- *Resolution:* a user's choice (a skin, or stock), else the instance default,
  else stock. The login page gets the default. Skins carry a `skin.json`
  manifest (name, description, modes).
- *Instance default* (admin role only; a non-admin POST gets 403), stored in
  the plugin's own `theme_selector_settings` table, not plugin settings.
  Setting it applies the skin's graph palette to LibreNMS config, after
  recording each key's original state. Switching and clearing restore those
  states exactly: tested with a hand-set `graph_colours.pinks` override, which
  survived two default changes and came back when the default was cleared.
  `port_in`/`port_out` (which LibreNMS doesn't declare) are written whenever
  something will honour them (`RecolouringRrd`, below, or a helper that reads them),
  and are stored with `persist()` without a definitions entry.
- *Every stylesheet link* carries a `?v=<mtime>` cache-buster, which fixes
  the hard-refresh problem the `custom_css` setup had.

**Production migrated 2026-09-28/29** (`docs/DEPLOYMENT.md`, "Migration
record"). Confirmed there: per-user skins, the instance default reaching a
second account, per-user graph colours, and the pinned navbar with every skin.
Not verified on production, by choice: the login page (that host sends every
visitor straight to Microsoft SSO, so it never renders there; the code path is
the one the picker page already exercises, and the Docker instance covers it)
and a full `daily.sh` cycle (the plugin is recorded in `composer.plugins.json`,
which `daily.sh` reinstalls from; check after the first nightly run). The old
`install.sh`/`uninstall.sh` are deleted.

**Found on production, fixed 2026-09-29: stock table backgrounds showing through
Protoss and Zerg.** A production screenshot showed gray tables inside navy and
plum widgets. The gray was exactly `#2e3338`, LibreNMS's own dark surface, which
appears in no skin palette, so it was a gap and not a design choice: stock's
`.dark table` (0,1,1) and `.dark .table-responsive > .table` (0,3,0) paint
tables, and our `.table` rules never set a background. Terran's surfaces are
near that gray, which hid it.

A property-level sweep (now `harness/leaks.html`) found the same shape of bug
in stock rules with more classes than ours (`.dark .form-control[disabled]`,
`.dark .list-group-item.disabled`, `th.success` and its siblings), two of them
real accessibility failures: read-only and disabled inputs at 1.7-1.9:1 and
contextual header cells at 2.2:1. All fixed in `base.css` with selectors one
point above the stock ones, behind five new tokens (`table-bg`,
`table-nested-bg`, `input-disabled-bg`, `input-disabled-fg`, and the existing
`table-stripe-odd-bg` for BGP rows). Read-only inputs are now 4.7-5.7:1 in
every skin; contextual cells 10:1 or better. The checker was confirmed to fail
on the pre-fix stylesheet (21 leaks, 7 contrast failures for Zerg).

Stock backgrounds that are legible and left alone, with reasons, are listed on
`leaks.html`: active menu and list items (10-14:1), badges inside buttons and
headings, validation-state addons.

Not covered, because the harness cannot render them: `:hover`/`:focus` variants
of stock rules and pages with no harness equivalent. `leaks.html` skips state
selectors; a live-page pass on the pages a host actually uses is still the
final check.

**3 — Admin upload and delete. Done 2026-09-29** on the `dev/` instance;
not yet on the production host. The design decision that shaped it: an upload
is attacker-controlled input under a web root that runs PHP, so **nothing
uploaded is ever served**. The bundle is parsed and validated, and the
stylesheet is regenerated from the parse; fonts become base64 inside it. That
gave:

- a strict zip reader of our own (no extraction, exact name allowlist, cross-
  checked headers, bounded inflation);
- a token-file grammar of one block type (`html.dark { custom properties }`)
  plus `@font-face`, with every value tokenised against a short allowlist;
- a catalog of 305 tokens derived from `base.css`, 36 of them *structural*
  (position, size, generated text, clip-path, animation) and bundled-only, so an
  upload can't paint a fake message or hide a control;
- an independent output guard, a registry table (the row is what makes a
  directory a skin), atomic staged installs with rollback, removal that never
  follows a link, admin-only CSRF-protected rate-limited routes, a 50-skin cap,
  and `?theme-selector=off` as an escape hatch;
- `lnms theme-selector:validate` and `scripts/pack-skin.py` for skin authors.

Verification: 1,178 unit checks (hostile archives, a large CSS injection
corpus, a mutation fuzzer, installer failure paths), a mutation check that
breaks each defence and requires a failing test (46 caught, 7 documented as
redundant layers, 0 missed) and an end-to-end script against the real routes.
Deleting a skin in use falls its users back to the instance default; deleting
the default clears it and restores the graph colours. See
[SECURITY.md](SECURITY.md) for the controls and, as important, what is not
defended.

**Incident, 2026-09-29.** The mutation check once ran against a container with
the repository bind-mounted read-write, and a test that planted a symlink to
`/` let the deliberately broken deleter wipe the project directory. It was
rebuilt from GitHub and the session transcript and re-verified. The repository is
now mounted read-only in the dev container, the test links only inside its own
temp directory, and `dev/test.sh` runs the unit and mutation suites in a sealed
throwaway container.

**4 — Light variants.** Scoping approach from the harness prototype; a light
token set for at least one bundled skin.

**5 — Per-user graph colours. Done 2026-09-28** on the `dev/` instance; not
yet on the production host. Smaller than planned: graph images come from
`/graph` (which `graph.php` is rewritten to), a normal `web`-group route that
carries the user's session, and the graph code reads its colours from config at
render time. A middleware in the `web` group (`GraphColours`) sets the palette
keys with `LibrenmsConfig::set()`, which is in-memory only, for that request.
The persistent config keeps holding the instance default's palette, so graphs
with no session user (API, reports, signed URLs) still match the default.

Tested by `dev/test-graphs.sh`, 20 checks over `port_bits` (dark chrome) and
`port_errors` (colour ramps) with a synthetic RRD and a fixed time window, so
equal colours mean identical bytes:

- different skins draw different graphs; a skin differs from stock;
- an explicit "stock" choice stays stock whatever the default is (keys the
  default overwrote are restored from the recorded originals, or LibreNMS's
  definition default);
- following the default equals choosing it; one user's request leaves nothing
  behind for the next; persistent config never changes during requests.

The same 20 checks pass with the route and config caches built, as on a
production host. Graph responses are `Cache-Control: no-cache, private` with
no validators, so browsers refetch and a skin switch shows on the next load.

Known limits: port traffic series are recoloured by string-matching six options
(below), so they fall back to stock colours if core changes those lines; chrome only
follows a skin for users on the dark theme; a user on Light still gets the
`graph_colours.*` ramps of their skin, which is harmless but not "stock".

### Port traffic series without a core patch

`includes/html/graphs/generic_data.inc.php` (port_bits and 19 other graph types)
hard-codes its six in/out series colours and reads no config. An earlier version of this
document said a plugin can't reach them; that was never tested, and it is wrong. Every
graph is drawn by `Rrd::graph($rrd_options)` (`LibreNMS/Util/Graph.php`), and `Rrd` is a
container binding, so the plugin can wrap it:

- `Graph/RecolouringRrd` extends core's `LibreNMS\Data\Store\Rrd`, overrides only
  `graph()`, and rewrites the six options (`AREA:inbits_max#D7FFC7`, `AREA:inbits#90B040`,
  `LINE:inbits#608720`, and the `dout` three) from `graph_colours.port_in` / `port_out`
  just before rrdtool runs. Because it runs after the per-request override, each user's
  graphs follow their own skin.
- `Graph/PortSeries` does the rewriting and nothing else. An option is only changed when
  its colour is still the stock literal for its role, so a caller's own colour is never
  touched, a helper that already reads the palette is left alone, and if core changes
  those lines nothing matches and the graph is drawn in stock colours.
- `Graph/PortSeriesSupport` checks by reflection that core's `Rrd` is still a class this
  can extend (not final, `graph(array): string` public, no constructor arguments). A
  subclass whose parent changed shape would be an uncatchable fatal on every request, so
  if the check fails the subclass is never loaded.
- It is installed by `ThemeSelectorProvider::boot()` while the plugin is enabled, on web
  requests and in console processes alike. Web requests draw graphs for users; the alert
  process draws them for emails and chat messages (`Util/Mail.php`, `Transport/Telegram.php`),
  with no session, so those use the instance default, which is what the config holds. If
  something has already used the store when the plugin boots, it is not replaced (that
  state isn't ours) and a line is logged.
- A palette that is not three six-digit hex colours is ignored for that direction.

Nothing in LibreNMS is edited, so `daily.sh` has nothing to trip over. Tested on the dev
stack with an unmodified helper (`dev/test-port-recolour.sh`): a skin's six colours are in
the drawing and the stock ones are not, per user, under an instance default, from a
legacy-style CLI process (as `alerts.php` runs), and back to stock when the default is
cleared.

### The upstream change: decided to leave it alone, for now

We drafted a one-helper change to LibreNMS (read the six colours from declared
`graph_colours.port_in` / `port_out`, defaults equal to today's literals, byte-identical
output). It was **not submitted**: the plugin no longer needs it, and it would cost review
effort and goodwill for a change nobody sees by default (murrant has rejected relocation
without payoff before: "you are just moving the garbage around", librenms/librenms#20594).
The tools to regenerate and prove it are kept (`dev/port-colours-diff.py`,
`dev/test-port-colours.sh`, `scripts/helper-audit.py`). The decision and what would change
it are recorded in [ROADMAP.md](ROADMAP.md), "Upstream: the port series change". The core patch (`scripts/patch-core.sh`) is no longer needed.

Out of scope: the other config-blind helpers (`generic_simplex`, `generic_duplex`,
`generic_multi_data`, `generic_multi_bits`) take their series colours from variables each
caller sets, not from the helper, so there is no single place to rewrite them.

---

## Open questions

None blocking. Settled 2026-09-25: test instance (Docker, `dev/`), names,
admin permission, install path.
