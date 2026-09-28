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
| Install paths | **The plugin only.** `install.sh`/`uninstall.sh` are retired once the plugin reaches parity; the one existing install (the production host) is migrated by hand. |

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
  fonts/           optional .woff2 + licence files
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

Anything the three current skins do that cannot be expressed as a token belongs
in the base stylesheet — possibly behind a token that switches it on — not in a
per-skin raw CSS escape hatch.

### Zip validation

Extension allow-list (`json`, `css` for `skin.css` only, `woff2`, `woff`,
`ttf`, `txt`); no SVG (runs script when opened same-origin); reject absolute
paths and `..` (zip-slip); size cap; `skin.json` and `skin.css` must parse and
validate before anything is written.

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
  ThemeSelectorProvider.php  hooks, composer, routes, views, migrations
  Hooks/                     MenuEntry (picker link), Settings (admin)
  Skins/                     registry, compiler, zip validator, publisher
  Http/Controllers/
routes/web.php
resources/views/
database/migrations/       skin registry table
base/base.css              the interpretation layer's stylesheet
skins/<id>/                bundled skins: skin.css, fonts/, graph.conf; the
                           original <id>.css stays until production migrates
harness/                   preview pages; ?build=tokens and compare.html
dev/                       Docker test instance (dev/README.md)
scripts/                   patch-core.sh stays; install.sh/uninstall.sh are
                           removed once the plugin reaches parity
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

**1 — Base + tokens. Equivalence done 2026-09-28.** `base/base.css` (47KB,
shared) plus a `skin.css` of 18-19KB per skin, down from ~70KB standalone.
Verified two ways:

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

**1b — Token API.** What's left before custom skins are practical: the base
was extracted mechanically, so it has 38 named roles (`--ts-accent`,
`--ts-surface`, `--ts-border`...) but also ~250 detail tokens with generated
names (`--ts-navbar-default-bg-image`) and no fallbacks. A custom skin
currently has to set all of them. 1b gives every detail token a fallback built
from the roles, so a minimal skin sets roughly 20 values and the bundled skins
override the rest; renames the detail tokens; and writes the token reference.
Equivalence is re-checked after every step.

**2 — v1 plugin, and migrate production.** Bundled skins published to `html/css/custom/theme-selector/`,
per-user picker page, admin default, composer injection with a cache-buster
(the skins' current lack of one is FINDINGS' hard-refresh problem). Then
migrate the production host: `uninstall.sh` to restore `webui.custom_css` and
the graph keys, `lnms plugin:add`, re-apply the graph palette as the
instance default. Delete `install.sh`/`uninstall.sh` after.

**3 — Admin upload and delete.** Zip validation, compile, publish, registry.
Deleting a skin in use falls those users back to the default.

**4 — Light variants.** Scoping approach from the harness prototype; a light
token set for at least one bundled skin.

**5 — Per-user graph colours.** Spike: override graph config only for
graph-render requests, based on the requesting user's skin. Needs a check that
graph requests carry the session.

Out of scope: the `generic_data` port-graph patch stays a separate, optional
core patch. A plugin can't fix config-blind graph helpers.

---

## Open questions

None blocking. Settled 2026-09-25: test instance (Docker, `dev/`), names,
admin permission, install path.
