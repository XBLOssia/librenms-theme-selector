# Deployment

Installing Theme Selector for LibreNMS, keeping it updated, and removing it
again. The one host that ran the older `install.sh` setup has been migrated;
what happened is recorded at the end of this page.

Needs PHP 8.2 or newer and a LibreNMS with the package plugin system. Written against
LibreNMS master @ `63e0394` (2026-09-17). Install, uninstall, graph colours and the live
skin audit were re-run on a stock LibreNMS 26.9.1.1 (the `dev/` image, and a second,
clean container for the install and uninstall) on 2026-10-02.

---

## Deployed instances

| LibreNMS version | Devices | Install | Status |
|---|---|---|---|
| `26.8.1-147-g63e0394bd1` (as of the migration; it has been updated since) | ~1,400 | Plugin (`dev-main`) | Migrated 2026-09-28/29 from `install.sh`; SSO login only |

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
3. **`migrate`** creates the plugin's two tables, `theme_selector_settings` (the
   instance default and the recorded original graph colours) and
   `theme_selector_skins` (uploaded skins), in four migrations.
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
| Published skins | `html/css/custom/theme-selector/` (gitignored by LibreNMS), with two bookkeeping files, `.bundled.json` and `.install.lock` |
| Instance default, graph originals | table `theme_selector_settings` |
| Uploaded skins (name, graph palette, textures, licence notice, SHA-256) | table `theme_selector_skins`; each one's generated `skin.css` is under `html/css/custom/theme-selector/skins/<id>/` |
| Each user's choice | `users_prefs`, key `theme_selector.skin` |
| Graph colours | LibreNMS config rows (`graph_colours.*`, `rrdgraph_def_text_dark`, `rrdgraph_def_text_color_dark`) |

No LibreNMS core file is touched. Outside its own files the plugin changes only:
`composer.json` and `composer.lock` (edited by `plugin:add`; `daily.sh` resets and
re-requires them), `composer.plugins.json`, the route cache under `bootstrap/cache/`, and
`html/css/custom/theme-selector/`. (The older [port-graph core
patch](#legacy-the-port-graph-core-patch-not-needed) did edit a core file; it is not needed.)

---

## Updates

**LibreNMS updates:** `daily.sh` resets `composer.json`, pulls, then
re-requires every package in `composer.plugins.json` and runs
`composer install --no-dev` and `lnms migrate` (`daily.sh` lines 302 and 361-368 in 26.9.1.1). The
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

Licence notices (an optional `LICENSE.txt` in a bundle) add a `license_text`
column to `theme_selector_skins`. Run `php artisan migrate --force` after
updating; until it has run, uploads fail. Textures add a `textures` column the
same way; an upload still installs before that migration has run, and only the
texture list on the admin page is missing.

The upload page shipped this way: it added the `theme_selector_skins` table and
three routes. Until `route:cache` is re-run, the admin section of the picker
page can't build its upload and delete links.

The next page load republishes changed skins. Every stylesheet link carries a
`?v=<mtime>` cache-buster, so browsers fetch the new files without a hard
refresh. (The old `webui.custom_css` setup had no cache-buster, which made
every skin deploy look like it hadn't worked.)

---

## Custom skins (admins)

**Plugins → Theme Selector → Installed skins** lists every skin (bundled and
uploaded) with the mode it is written for, its source and install date, can be filtered, sorted and paged,
has a Preview link on each row, and takes a `.zip` to add another. (The preview
is one more route: if you cache routes with `php artisan route:cache`, run it
again after updating or the preview frame shows a 404.) What a bundle contains and the
rules it must follow are in [AUTHORING.md](AUTHORING.md); why those rules exist,
and what is and isn't defended, is in [SECURITY.md](SECURITY.md).

- **Light and dark.** A skin is written for light or dark mode (`skin.json`'s `mode`), and every
  user chooses a skin for each mode; admins set a default for each. The upgrade that added this
  needs `./lnms migrate --force` (two columns on `theme_selector_skins`; `daily.sh` runs it nightly,
  but run it now). Until it has run, uploading a light-mode skin is refused rather than recorded as
  dark. Everyone's existing choice becomes their dark-mode skin and the instance default becomes the
  dark-mode default; light mode stays stock until someone chooses a light skin. The update also
  publishes `base-light.css` and a `skin.mirror.css` beside every skin. A skin uploaded before the
  update has no mirror on disk; the first request after the next update makes one from its
  installed stylesheet (checked as an install checks it), so it can be used in either slot without
  being uploaded again.
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
  `theme_selector_skins` holding its name, graph palette, texture list, licence notice, SHA-256 and who installed it.
  Nothing you upload is stored or served as-is, and fonts are embedded in that
  stylesheet. A bundle's `LICENSE.txt` lives only in the database and is shown
  under "Licence notice" in the skin list.
- **Requirements:** the web server user must be able to write
  `html/css/custom/theme-selector/` (`lnms theme-selector:publish` shows the
  error if it can't), and PHP's `upload_max_filesize` and `post_max_size` must
  allow a bundle (the plugin's own cap is 4 MB; PHP's default of 2 MB is
  usually enough, since bundles are typically tens of KB).
- **A skin that makes a page unusable:** add `?theme-selector=off` to that
  page's address to see it with no skin, then pick another or ask an admin to
  remove it. Nothing stored changes.
- **Audit trail:** every rejected upload, install, replacement and removal is
  logged at warning level (LibreNMS's default, so nothing needs configuring) as
  `ThemeSelector: ...` in `/opt/librenms/logs/librenms.log`, with the user, their IP and
  the bundle's SHA-256. `dev/test-upload.sh` checks each of them against a stock instance.
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

- `graph_colours.port_in` / `port_out` (the port traffic series) are recoloured by the
  plugin itself, with no change to LibreNMS: see "Port traffic series" below. If core
  ever changes the lines it matches, those series fall back to stock colours and the
  chrome around them still follows the skin.
- Graph chrome uses the `*_dark` settings, so it only follows a skin for users
  on the dark theme.
- The `graph_colours.*` ramps and the `*_dark` keys are the only settings the
  plugin ever touches, and only from a skin's `graph.conf` (any other key in that
  file is an error: an uploaded skin is refused, and the unit tests fail for a bundled one).

**Testing it:** `dev/test-graphs.sh` builds a dummy device and synthetic RRD in
the Docker instance and draws the same graphs as users with different skins
under different defaults: 20 checks, including that nothing leaks into the
persistent config.

---

## Port traffic series

The series colours of port traffic graphs (`port_bits` and 19 other graph types) are
hard-coded in `includes/html/graphs/generic_data.inc.php`. The plugin recolours them
itself: in web and console processes it wraps LibreNMS's RRD store and rewrites exactly those six
options just before rrdtool draws, from the skin's `graph_colours.port_in` / `port_out`.
**Nothing in LibreNMS is edited**, so nothing here can interfere with `daily.sh`, on a
cron install or on one where LibreNMS's own scheduler runs it. A reflection check
refuses to install the wrapper if core's RRD store has changed shape, and if core changes
the six lines the series simply draw in stock colours. Design and tests: `docs/PLUGIN.md`.

Nothing to do after `plugin:add` or an update. To check on a host:

```bash
sudo -u librenms php artisan tinker --execute='echo Xblossia\ThemeSelector\Graph\PortSeriesSupport::compatible() ? "yes" : "no";'
```

`yes` means the wrapper is available. Then pick a skin (Plugins → Theme Selector) and
load a port graph.

Run it again after a LibreNMS update. `no` means core changed its RRD store: port series are
on stock colours (nothing else is affected) until the plugin is updated, and that is one of the
conditions listed in `docs/ROADMAP.md` for reopening the upstream change. Nothing is logged in
that case (it would be a line per request and per poller), so this check is the way to know.
`yes` with port series still stock means the skin sets no `graph_colours.port_in` / `port_out`,
or the store was already in use when the plugin booted (an `info` line, hidden at LibreNMS's
default level; `LOG_LEVEL=info` in `.env` shows it).

**A skin with no port colours of its own shows another skin's.** A skin without
`graph_colours.port_in` / `port_out` should draw stock green and lavender. If it shows another
skin's colours (seen once on a host that had run the old core patch under a Protoss default),
the config table holds `port_in` / `port_out` rows the plugin isn't tracking, which LibreNMS
treats as the instance's own setting. Check, then erase them (the plugin's own cleanup does
the same):

```bash
php artisan tinker --execute='foreach (["graph_colours.port_in","graph_colours.port_out"] as $k) echo $k." effective=".json_encode(App\Facades\LibrenmsConfig::get($k))." db=".json_encode(App\Models\Config::where("config_name",$k)->value("config_value")).PHP_EOL;'
php artisan tinker --execute='App\Facades\LibrenmsConfig::erase("graph_colours.port_in"); App\Facades\LibrenmsConfig::erase("graph_colours.port_out");'
```

Only do this if you never set those two keys yourself: from here on a value in the config table
is read as an admin's choice and drawn as the stock colours for every skin without its own.

## Legacy: the port-graph core patch (not needed)

**You do not need this section.** It describes an earlier way to the same result,
`scripts/patch-core.sh`, which edits a LibreNMS core file. It is kept for hosts that
applied it, and to get off it (below). Because the file is tracked by git, a patched copy
can stop LibreNMS updating, so **if you applied the patch, revert it** (see "If the patch is
already on a host"); the plugin's own recolouring does the same job. The rest of this
section is about that patch. **If LibreNMS's own scheduler runs `daily.sh` on your host
(the `librenms-scheduler.timer` systemd unit, the standard install), never apply it.**

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

That is all. Earlier versions also patched `resources/definitions/config_definitions.json`
to declare the two keys. They don't need declaring: `lnms config:set` refuses a key
LibreNMS doesn't declare, but the plugin stores them with `LibrenmsConfig::persist()`
and every later process reads them back from the `config` table (checked on LibreNMS
26.9.1). The plugin writes the two keys whenever something will honour them: its own wrapper, or the
patched helper. `config_definitions.json` changes upstream far more often than
the helper does, so leaving it alone removes most of the exposure described below.

**With no config set, output is byte-identical.** Verified rather than
asserted: the same graph URL, with `from`/`to` pinned so the data window is
fixed, produced the same SHA-256 and the same 141,496 bytes before and after
patching.

```bash
cd /opt/librenms
P=vendor/xblossia/librenms-theme-selector/scripts/patch-core.sh
$P status
$P apply --wrapped   # then re-save the instance default
$P revert
```

`apply` refuses without `--wrapped`, which means "`daily.sh` on this host is started
through `scripts/daily-wrapper.sh`". Say it only when that is true (next section).
After applying, re-save the instance default (Plugins → Theme Selector) so the
plugin writes the two port keys. `apply` dry-runs first, so a version drift fails
loudly instead of scattering `.rej` files through core. `revert` reverses the patch
itself; it never copies a saved file back (that would be stale after an update), and
falls back to `git checkout` only if the patch no longer reverses cleanly.

### The catch: a patched file can stop `daily.sh`

`daily.sh` updates LibreNMS with `git pull`. It does **not** quietly put a patched file
back to stock. If upstream has also changed the patched file, the pull stops with
`Your local changes to the following files would be overwritten by merge`, and LibreNMS
stops updating, security fixes included, until someone notices. It worked for nine days
on the first host this was used on, and then upstream touched a patched file.

So the patch must never be in place when `daily.sh` runs. `scripts/daily-wrapper.sh`
does that:

1. reverses the patch (and the old `config_definitions.json` declaration, if an
   earlier version of the patch left one);
2. runs `daily.sh` with the same arguments;
3. re-applies the patch afterwards, from an `EXIT` trap, so that happens even if
   `daily.sh` fails.

It exits with `daily.sh`'s status, leaves the patch off if it was off to begin with,
and if upstream has since changed the patched lines it says so on stderr (the update has
still happened; port graphs use stock colours until the patch is regenerated).

#### It only helps if it is what starts `daily.sh`

On most installs it is **not**. The standard LibreNMS install runs a systemd timer,
`librenms-scheduler.timer`, which calls `lnms schedule:run`; that runs `daily.sh` itself
from code inside the checkout. There is no cron line to replace, and nowhere to put the
wrapper: editing the tracked file that schedules it would cause the very stopped pull
this is trying to avoid. To see which kind of host you have:

```bash
systemctl list-timers --all | grep librenms     # librenms-scheduler.timer  -> scheduler install
sudo crontab -l -u librenms; ls /etc/cron.d     # a daily.sh line           -> cron install
```

| Your host | What to do |
|---|---|
| **Scheduler (systemd timer) runs `daily.sh`** | **Leave the patch off.** Port graphs keep stock colours; nothing else is affected. If it is already on, revert it (next section). |
| **A cron line (or your own timer) runs `daily.sh`** | Point that entry at the wrapper (`15 0 * * *  librenms  /opt/librenms/vendor/xblossia/librenms-theme-selector/scripts/daily-wrapper.sh >> /dev/null 2>&1`), then `patch-core.sh apply --wrapped`. A `./daily.sh` run by hand bypasses it; run the wrapper by hand instead. |
| Scheduler host, and you really want the colours | Turn LibreNMS's `update` setting off and run the wrapper from a timer of your own. Not recommended: with `update` off, `daily.sh` only migrates and cleans up and never pulls, so the wrapper would be the only thing updating LibreNMS, and you take over that job. |

The lasting fix is upstream (below).

### If the patch is already on a host (revert it)

If `daily.sh` has been stopping on `Your local changes ... would be overwritten`, or you
simply want out of the patch, put the patched files back to stock. As `librenms` in
`/opt/librenms`:

```bash
git status --short       # expect M on generic_data.inc.php (and, from older versions, config_definitions.json)
git checkout -- includes/html/graphs/generic_data.inc.php resources/definitions/config_definitions.json
git status --short       # should now list only composer.json and composer.lock
./daily.sh               # or wait for the nightly run
```

(Name only the files `git status` showed as modified. `composer.json` and `composer.lock` are
expected to stay listed: `lnms plugin:add` edits them and `daily.sh` resets and re-requires
them each run. Any other stray files, such as a `php-snmp.pcap`, are untracked and harmless.)

Rows already stored under `graph_colours.port_in` / `.port_out` are the plugin's own and are
overwritten or erased the next time the instance default is saved. Note that `revert` from versions before
this one copied a saved `*.pre-skins-patch` file over the target, which is stale after an
update and would undo upstream's changes; this version removes those files and never
restores them.

### What LibreNMS's validate page says (and what to do about each)

`/validate` (or `./validate.php`) reports four things after installing the plugin and the
patch. Some are expected; one is a real problem. From a production host:

| Message | Meaning | Action |
|---|---|---|
| **WARN: Your database schema has extra migrations** (the plugin's four) | LibreNMS compares the `migrations` table with its own migration files and does not know about a plugin's. The text about switching from the daily to the stable release does not apply. | Cosmetic. Nothing to do; it stays for as long as the plugin is installed. |
| **WARN: Your local git contains modified files**: `composer.json`, `composer.lock` | `lnms plugin:add` runs `composer require`, which edits both. `daily.sh` resets them and re-requires every plugin (`composer.plugins.json`), so they don't stop updates. | Expected. |
| **...and** `includes/html/graphs/generic_data.inc.php`, `resources/definitions/config_definitions.json` | The core patch. `config_definitions.json` is the old two-file patch's second file, and **it is what stops `daily.sh`** when upstream edits it. | `generic_data.inc.php` stays listed while the one-file patch is applied; that is only safe where `daily-wrapper.sh` starts `daily.sh`, and on a scheduler install it should be reverted. `config_definitions.json` should not be listed once you have followed "If the patch is already on a host". |
| **FAIL: files owned by a different user than `librenms`**: `/opt/librenms/minimal.zip` | A stray file, almost certainly the zip from an earlier `pack-skin.py examples/minimal` run in that directory. It is not part of LibreNMS and nothing uses it, but validate says it "will stop you updating automatically". | `ls -l /opt/librenms/minimal.zip`, then remove it (`sudo rm`) or `sudo chown librenms:librenms` it. Run `pack-skin.py` from a scratch directory, not from `/opt/librenms`. |

Do **not** use the `./scripts/github-remove` that the "modified files" warning suggests:
it is for undoing a pull-request checkout and discards local changes wholesale, `composer.json`
and `composer.lock` included. The targeted `git checkout --` of the patched files, above, is
what is meant. And run `patch-core.sh` as `librenms`: run as root it still puts the file's owner
back, but a root-owned file under `/opt/librenms` is exactly what the FAIL row is about.

### Getting off the patch

Revert it (above). The plugin's own wrapper does the same job without touching core. A change
that would make LibreNMS read these colours itself was drafted and deliberately not submitted;
`docs/ROADMAP.md`, "Upstream: the port series change", says why and what would change that.

---

## Uninstall

Tested end to end on a clean LibreNMS 26.9.1.1: install, set a default and a user choice,
uninstall as below, reinstall. As `librenms` in `/opt/librenms`:

1. **Clear the instance default** (Plugins → Theme Selector → None). This restores the graph
   colours. Skipping it leaves the last default's palette in LibreNMS config, and removing the
   plugin first loses the record of the originals (see "Plugin already removed" below).
2. **Remove the plugin and its files:**

```bash
./lnms plugin:remove xblossia/librenms-theme-selector
rm -rf html/css/custom/theme-selector
php artisan route:cache
php scripts/composer_wrapper.php config --global --unset repositories.theme-selector
```

   `plugin:remove` only runs `composer remove`; it does **not** rebuild the route cache, which
   keeps routes to the removed controller until something rebuilds it (`route:list` fails with
   it in that state), so `route:cache` goes right after. The last line removes the Composer
   repository entry that the install added.
3. **Optional: remove the data.** The tables, `theme_selector.skin` rows in `users_prefs`, the
   plugin's four rows in `migrations`, and its row in `plugins` stay behind, harmlessly:

```sql
DROP TABLE theme_selector_settings;
DROP TABLE theme_selector_skins;
DELETE FROM users_prefs WHERE pref = 'theme_selector.skin';
DELETE FROM migrations WHERE migration LIKE '%theme_selector%';
DELETE FROM plugins WHERE plugin_name = 'ThemeSelector';
```

   **Delete the `migrations` rows together with the tables.** If you drop the tables and keep the
   rows, a later reinstall's `lnms migrate` reports "Nothing to migrate" and creates nothing, so
   uploads fail and the default can't be saved. (LibreNMS's validate page also keeps warning
   about the extra migrations until they are gone.)

**Plugin already removed, default never cleared.** The `graph_colours.*`,
`rrdgraph_def_text_dark` and `rrdgraph_def_text_color_dark` rows stay in the `config` table,
and the record of their originals went with the table. List them, then erase the ones you did
not set yourself (a key that had an override before you installed anything is the one to keep):

```bash
php artisan tinker --execute='foreach (App\Models\Config::where("config_name","like","graph_colours.%")->orWhere("config_name","like","rrdgraph_def_text%")->pluck("config_name") as $k) echo $k, PHP_EOL;'
php artisan tinker --execute='App\Facades\LibrenmsConfig::erase("graph_colours.port_in"); App\Facades\LibrenmsConfig::erase("graph_colours.greens");'   # one call per key
```

**To switch it off without uninstalling:** `./lnms plugin:disable ThemeSelector`. Pages go stock
on the next load and the graph wrapper is not installed; the graph palette stays until a default
is cleared, which needs the plugin enabled. `./lnms plugin:enable ThemeSelector` turns it back on
(it also rebuilds the route cache).

---

## Rollback confidence

| Question | Answer |
|---|---|
| Core files modified? | None |
| Database schema changed? | Two tables of its own, `theme_selector_settings` and `theme_selector_skins` |
| Files outside `html/css/custom/`? | The Composer package under `vendor/`, `composer.json`/`composer.lock`/`composer.plugins.json`, and the route cache |
| Services restarted or installed? | None |
| Affects polling, discovery, alerting? | Not their behaviour: the plugin's store subclass overrides only `graph()`. Alert emails and chat messages that embed a graph use the instance default's colours, port series included |
| Affects other users? | Only through the instance default and the graph palette; each user's own choice affects only them |
| Can a skin break a page? | The code that adds the stylesheet catches every error and falls back to stock styling. CSS itself can't break PHP. |

### If something looks wrong

1. **Stock styling everywhere.** The user is on Light, has chosen stock, or
   there's no default. Check Plugins → Theme Selector. If the page source has
   no `data-theme-selector` links, check `/opt/librenms/logs/librenms.log` for
   `ThemeSelector:` lines.
2. **Skin half-applied, or 404s for `theme-selector/...` files.** Publishing
   failed, usually because `librenms` can't write
   `html/css/custom/theme-selector/`. Run `./lnms theme-selector:publish` to
   see the error.
3. **Some components still stock-coloured.** Expected: see
   [ROADMAP.md](ROADMAP.md) for coverage.
4. **Port graph series still green and lavender.** Run the `PortSeriesSupport::compatible()`
   check under [Port traffic series](#port-traffic-series). `no` means core changed its RRD store
   (port series stay stock until the plugin is updated; the rest of the graph still follows the
   skin); `yes` means check that the skin sets `graph_colours.port_in` / `port_out`.
5. **`plugin:add` fails with "Host key verification failed".** Composer fell back to SSH after
   GitHub's anonymous API limit was used up (it happens after several installs from one
   address). Wait for the limit to reset, or give Composer a token:
   `php scripts/composer_wrapper.php config --global github-oauth.github.com <token>`.
