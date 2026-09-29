# Deployment

Installing Theme Selector for LibreNMS, keeping it updated, and removing it
again. The one host that ran the older `install.sh` setup has been migrated;
what happened is recorded at the end of this page.

Written against LibreNMS master @ `63e0394`; tested on the `dev/` Docker
instance (LibreNMS 26.9.1.1).

---

## Deployed instances

| LibreNMS version | Devices | Install | Status |
|---|---|---|---|
| `26.8.1-147-g63e0394bd1` | ~1,400 | Plugin (`dev-main`) | Migrated 2026-09-28/29 from `install.sh`; SSO login only |

The instance itself is deliberately not named here. It is a production
monitoring box, and pairing a resolvable hostname with an exact software
version in a public repository is free reconnaissance for no benefit to
anyone reading this.

Because logins there go straight to Microsoft SSO, the login page never
renders on that host.

---

## Install

On the LibreNMS host, as the `librenms` user, from `/opt/librenms`:

```bash
php scripts/composer_wrapper.php config --global repositories.theme-selector vcs https://github.com/XBLOssia/librenms-theme-selector
./lnms plugin:add xblossia/librenms-theme-selector dev-main
./lnms migrate --force
php artisan route:cache
./lnms theme-selector:publish
```

1. **The repository goes in the global Composer config**, through the same
   wrapper `daily.sh` and `plugin:add` use (it finds a system `composer` or
   downloads `composer.phar`), and not in LibreNMS's
   `composer.json`. `daily.sh` resets `composer.json` on every update; an entry
   there disappears, and the failed `composer require` that follows can take
   other packages down with it (the Network Command Suite deploy lost M365 SSO
   this way).
2. **`plugin:add`** runs `composer require` and records the package in
   `composer.plugins.json`, which `daily.sh` reads to reinstall plugins after
   each update. The plugin is enabled by default.
3. **`migrate`** creates the plugin's one table, `theme_selector_settings`.
   `plugin:add` doesn't run migrations; `daily.sh` does, so this step only
   saves waiting for the next nightly run.
4. **`route:cache`** rebuilds LibreNMS's route cache. Production installs
   cache their routes, and while a cache exists Laravel ignores routes that
   packages register, so without this the Theme Selector page is a 404 even
   though the plugin is enabled. `lnms plugin:enable` rebuilds the cache;
   `plugin:add` doesn't. (Found on the production migration, 2026-09-28.)
5. **`theme-selector:publish`** copies the base stylesheet and bundled skins
   into `html/css/custom/theme-selector/`. The first page load after any
   plugin update does this anyway; running it by hand confirms the directory
   is writable by `librenms`.

Then, in the web UI:

- **Plugins → Theme Selector.** Every user picks a skin here: the instance
  default, stock LibreNMS, or a named skin.
- **Instance default** (admins only, on the same page): what users who haven't
  chosen get, and what the login page shows. Its graph palette also becomes
  the instance's graph colours; see [Graph colours](#graph-colours).

Skins apply in dark mode. A user on **Preferences → Theme → Light** sees stock
LibreNMS whatever they picked.

### Where things live

| What | Where |
|---|---|
| Plugin code | `vendor/xblossia/librenms-theme-selector` (Composer) |
| Published skins | `html/css/custom/theme-selector/` (gitignored by LibreNMS) |
| Instance default, graph originals | table `theme_selector_settings` |
| Each user's choice | `users_prefs`, key `theme_selector.skin` |
| Graph colours | LibreNMS config rows (`graph_colours.*`, `rrdgraph_def_text_dark`, `rrdgraph_def_text_color_dark`) |

Nothing under `/opt/librenms` is edited, and no core file is touched, except
by the optional [port-graph patch](#optional-the-port-graph-core-patch).

---

## Updates

**LibreNMS updates:** `daily.sh` resets `composer.json`, pulls, then
re-requires every package in `composer.plugins.json` and runs
`composer install --no-dev` and `lnms migrate` (`daily.sh:302, 361-367`). The
plugin survives, and `html/css/custom/` survives because `daily.sh` never runs
`git clean`.

**Plugin updates:** installed as `dev-main`. On each update run, `daily.sh`
resets `composer.lock` and re-requires every plugin
(`FORCE=1 composer require ... xblossia/librenms-theme-selector:dev-main`), so
it resolves the latest `main`. To update immediately, re-run the same
`plugin:add`, as `librenms` in `/opt/librenms`:

```bash
./lnms plugin:add xblossia/librenms-theme-selector dev-main
```

(Plain `composer update` is refused: LibreNMS's Composer hooks block it unless
`FORCE=1` is set.)

**After an update that adds routes or tables, do these as well.** Production
caches its routes, and Laravel ignores a package's new routes while a cache
exists (the same reason the install includes `route:cache`), so an update that
adds a page needs the cache rebuilt, and one that adds a table needs a
migration (`daily.sh` runs migrations nightly, but not immediately):

```bash
./lnms migrate --force
php artisan route:cache
```

The upload page shipped this way: it added the `theme_selector_skins` table and
three routes. Until `route:cache` is re-run, the admin section of the picker
page can't build its upload and delete links.

The next page load republishes changed skins. Every stylesheet link carries a
`?v=<mtime>` cache-buster, so browsers fetch the new files without a hard
refresh. (The old `webui.custom_css` setup had no cache-buster, which made
every skin deploy look like it hadn't worked.)

---

## Custom skins (admins)

**Plugins → Theme Selector → Custom skins** lists every skin (bundled and
uploaded) and takes a `.zip` to add another. What a bundle contains and the
rules it must follow are in [AUTHORING.md](AUTHORING.md); why those rules exist,
and what is and isn't defended, is in [SECURITY.md](SECURITY.md).

- **Installing changes nobody's view.** The skin appears in everyone's "Your
  skin" list. Try it yourself, then make it the instance default if you want it
  to be everyone's.
- **Removing** an uploaded skin puts everyone who chose it back on the instance
  default. If it *was* the default, the default is cleared first and the graph
  colours are restored. Bundled skins can't be removed.
- **Uploading the same `id` again replaces the skin** (its palette is re-applied
  if it is the default). Up to 50 uploaded skins at once.
- **What ends up on disk:** for each uploaded skin, one generated `skin.css` in
  `html/css/custom/theme-selector/skins/<id>/`, and a row in
  `theme_selector_skins` holding its name and graph palette. Nothing you upload
  is stored or served as-is, and fonts are embedded in that stylesheet.
- **Requirements:** the web server user must be able to write
  `html/css/custom/theme-selector/` (`lnms theme-selector:publish` shows the
  error if it can't), and PHP's `upload_max_filesize` and `post_max_size` must
  allow a bundle (the plugin's own cap is 4 MB; PHP's default of 2 MB is
  usually enough, since bundles are typically tens of KB).
- **A skin that makes a page unusable:** add `?theme-selector=off` to that
  page's address to see it with no skin, then pick another or ask an admin to
  remove it. Nothing stored changes.
- **Audit trail:** every rejected upload, install, replacement and removal is
  logged as `ThemeSelector: ...` in `storage/logs/librenms.log`, with the user,
  their IP and the bundle's SHA-256.
- **Check a bundle before uploading it:** `./lnms theme-selector:validate
  my-skin.zip` runs the upload page's checks and installs nothing.

---

## Migration record

What it took to move the production host off `install.sh` (a symlinked skin in
`webui.custom_css`, plus graph colour config) onto the plugin. It happened once
and there is no other install to migrate, so this is history, not a procedure.
The scripts are deleted; they are in git history before the commit that removed
them. Where reality differed from the plan:

1. **The plugin installed cleanly** with the commands under [Install](#install).
2. **The Theme Selector page was a 404** even with the plugin enabled.
   Production caches its routes, Laravel ignores package routes while a cache
   exists, and `plugin:add` doesn't rebuild it. `php artisan route:cache` fixed
   it, and is now part of the install.
3. **The old uninstaller crashed** on `lnms config:clear webui.custom_css`.
   Current LibreNMS's `config:clear` is Laravel's cache clear and takes no
   arguments. The three skin symlinks had already been removed by then. The
   host's copy was also uploaded over SFTP without execute bits, so it had to
   be run with `bash`.
4. **Its saved "original" graph colours were wrong.** For `graph_colours.default`
   and `graph_colours.pinks` the recorded originals were Protoss's own colours:
   an earlier install had already changed them before the installer began
   recording per key. Restoring them would have pinned Protoss colours as
   "stock", so the overrides were erased instead
   (`lnms config:set <key>`, answering the reset prompt), which returns
   LibreNMS's real defaults, and `webui.custom_css` was reset the same way.
5. **The instance default was set afterwards** in the picker (Protoss, the
   skin the host had actually been running, not the Zerg the plan assumed). The
   plugin recorded the then-clean originals.

Checked on the host: the skin loads after any `custom_css`, a second account
gets the instance default, each user's graphs follow their own skin, and the
navbar stays pinned in all three skins. Not checked: the login page (SSO), and
a full `daily.sh` cycle, which is covered by `composer.plugins.json`; after the
first nightly run, `grep theme-selector /opt/librenms/composer.plugins.json`
and confirm a skin still applies.

The old copy at `/opt/librenms-skins` can be deleted if it is still there.

---

## Graph colours

RRDtool draws graphs on the server from LibreNMS config, so CSS can't reach
them. The plugin handles this two ways, from the same `skins/<id>/graph.conf`
palette (background, grid, frame, font, and the `graph_colours.*` ramps):

- **Per user.** On a graph request (`/graph`, and `graph.php`, which core
  rewrites to it) the plugin overrides the palette keys **in memory, for that
  request only**, to match the requesting user's skin. Nothing is written, so
  other users and later requests are untouched. A user who chose stock
  LibreNMS gets stock graphs whatever the default is.
- **Instance default.** Setting a default writes that skin's palette into
  LibreNMS config, so graphs nobody requested through a logged-in session
  (API, emailed reports, signed or IP-allowed URLs) still match the default.
  Before a key is first overwritten its original state is recorded: whether the
  database held an override, and its value. Switching the default restores any
  key the new palette doesn't set; clearing it restores everything. Keys that
  had no override before are erased rather than pinned, so LibreNMS's own
  defaults keep applying after upgrades.

Graph responses carry `Cache-Control: no-cache, private` and no validators, so
browsers refetch on every load: a skin switch shows on the next graph load with
no cache to clear.

- `graph_colours.port_in` / `port_out` are skipped unless the port-graph patch
  below has declared them. Without it port traffic series keep their fixed
  colours under every skin; the chrome around them still follows.
- Graph chrome uses the `*_dark` settings, so it only follows a skin for users
  on the dark theme.
- The `graph_colours.*` ramps and the `*_dark` keys are the only settings the
  plugin ever touches, and only from a skin's `graph.conf` (other keys in that
  file are ignored).

**Testing it:** `dev/test-graphs.sh` builds a dummy device and synthetic RRD in
the Docker instance and draws the same graphs as users with different skins
under different defaults: 20 checks, including that nothing leaks into the
persistent config.

---

## Optional: the port-graph core patch

**This is the only thing in this repo that touches a LibreNMS core file.** It
is opt-in, separate from the plugin, and never run automatically.

### Why

`port_bits`, the traffic graph on effectively every dashboard, renders
through `includes/html/graphs/generic_data.inc.php`, which hard-codes its six
series colours and reads no config at all. Without the patch, port graphs stay
stock green-and-lavender under every skin while the rest of the graph themes
correctly. The graph *chrome* (background, grid, frame) is themed either way;
it is only the series that are stuck.

### What it changes

| File | Change |
|---|---|
| `includes/html/graphs/generic_data.inc.php` | reads `graph_colours.port_in` / `.port_out`, defaulting to the values it previously hard-coded |
| `resources/definitions/config_definitions.json` | declares those two keys |

The second is not optional: LibreNMS validates config keys against the
definitions file, and the plugin skips keys it doesn't declare.

**With no config set, output is byte-identical.** Verified rather than
asserted: the same graph URL, with `from`/`to` pinned so the data window is
fixed, produced the same SHA-256 and the same 141,496 bytes before and after
patching.

```bash
./scripts/patch-core.sh status
./scripts/patch-core.sh apply     # then re-save the instance default
./scripts/patch-core.sh revert
```

After applying, re-save the instance default (Plugins → Theme Selector) so the
plugin writes the two port keys now that they exist.

`apply` dry-runs first, so a version drift fails loudly instead of scattering
`.rej` files through core. It keeps a pristine `*.pre-skins-patch` copy of each
file, and `revert` prefers that copy over reversing the diff.

### The catch: `daily.sh` reverts it

`daily.sh` restores tracked files, so **both patched files go back to stock on
every update**. The config values survive (they are database rows) but stop
being read. Re-apply after each update; the script is idempotent, so this is
safe to automate:

```bash
# /etc/cron.d/librenms-theme-selector-patch  - after daily.sh has run
30 1 * * *  root  /path/to/librenms-theme-selector/scripts/patch-core.sh apply >/dev/null 2>&1
```

---

## Uninstall

1. **Clear the instance default** (Plugins → Theme Selector → None). This
   restores the graph colours. Skipping it leaves the last default's palette
   in LibreNMS config.
2. Remove the plugin and its files, as `librenms` in `/opt/librenms`:

```bash
./lnms plugin:remove xblossia/librenms-theme-selector
rm -rf html/css/custom/theme-selector
```

What stays behind, harmlessly: the `theme_selector_settings` and
`theme_selector_skins` tables and `theme_selector.skin` rows in `users_prefs`.
To remove them too:

```sql
DROP TABLE theme_selector_settings;
DROP TABLE theme_selector_skins;
DELETE FROM users_prefs WHERE pref = 'theme_selector.skin';
```

**To switch it off without uninstalling:** `./lnms plugin:disable ThemeSelector`.
Pages go stock on the next load; the graph palette stays until a default is
cleared, which needs the plugin enabled.

---

## Rollback confidence

| Question | Answer |
|---|---|
| Core files modified? | None (unless you apply the optional port-graph patch) |
| Database schema changed? | One table of its own, `theme_selector_settings` |
| Files outside `html/css/custom/`? | The Composer package under `vendor/` |
| Services restarted or installed? | None |
| Affects polling, discovery, alerting? | No. CSS, plus graph colour config |
| Affects other users? | Only through the instance default and the graph palette; each user's own choice affects only them |
| Can a skin break a page? | The code that adds the stylesheet catches every error and falls back to stock styling. CSS itself can't break PHP. |

### If something looks wrong

1. **Stock styling everywhere.** The user is on Light, has chosen stock, or
   there's no default. Check Plugins → Theme Selector. If the page source has
   no `data-theme-selector` links, check `storage/logs/librenms.log` for
   `ThemeSelector:` lines.
2. **Skin half-applied, or 404s for `theme-selector/...` files.** Publishing
   failed, usually because `librenms` can't write
   `html/css/custom/theme-selector/`. Run `./lnms theme-selector:publish` to
   see the error.
3. **Some components still stock-coloured.** Expected: see
   [ROADMAP.md](ROADMAP.md) for coverage.
4. **Port graph series still green and lavender.** That needs the port-graph
   patch; everything else about graphs follows the default skin.
