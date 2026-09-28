# Deployment

Installing Theme Selector for LibreNMS, keeping it updated, migrating the one
host that ran the older `install.sh` setup, and removing it again.

Written against LibreNMS master @ `63e0394`; tested on the `dev/` Docker
instance (LibreNMS 26.9.1.1).

---

## Deployed instances

| LibreNMS version | Devices | Install | Status |
|---|---|---|---|
| `26.8.1-147-g63e0394bd1` | ~1,400 | `install.sh`, `link` mode, Zerg | **To migrate** to the plugin (runbook below) |

The instance itself is deliberately not named here. It is a production
monitoring box, and pairing a resolvable hostname with an exact software
version in a public repository is free reconnaissance for no benefit to
anyone reading this.

Its current layout:

```
/opt/librenms-skins                          SFTP copy of the repo, pre-rename
/opt/librenms/html/css/custom/zerg   ->      /opt/librenms-skins/skins/zerg
webui.custom_css                     =       ["css/custom/zerg/zerg.css"]
```

---

## Install

On the LibreNMS host, as the `librenms` user, from `/opt/librenms`:

```bash
composer config --global repositories.theme-selector vcs https://github.com/XBLOssia/librenms-theme-selector
./lnms plugin:add xblossia/librenms-theme-selector dev-main
./lnms migrate --force
./lnms theme-selector:publish
```

1. **The repository goes in the global Composer config**, not LibreNMS's
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
4. **`theme-selector:publish`** copies the base stylesheet and bundled skins
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

The next page load republishes changed skins. Every stylesheet link carries a
`?v=<mtime>` cache-buster, so browsers fetch the new files without a hard
refresh. (The old `webui.custom_css` setup had no cache-buster, which made
every skin deploy look like it hadn't worked.)

---

## Migrating from install.sh

For the host above. Every step is reversible, and the old setup keeps working
until step 3.

**0. Before you start: push this repo.** The host installs the plugin from
GitHub, so `main` must contain it.

**1. Record the current state**, so there's something to compare against:

```bash
cd /opt/librenms
sudo -u librenms ./lnms config:get webui.custom_css
sudo -u librenms ./lnms config:get graph_colours.greens
sudo -u librenms ./lnms config:get rrdgraph_def_text_dark
ls -la html/css/custom/
```

**2. Install the plugin** ([Install](#install)), still as `librenms`. With no
default set and no user choices, it changes nothing visible yet: the old
`custom_css` skin is still loading.

**3. Remove the old setup** with the uninstaller from the host's own copy,
which restores `webui.custom_css` and the graph keys to their values before
the first `install.sh`:

```bash
sudo -u librenms /opt/librenms-skins/scripts/uninstall.sh --dry-run
sudo -u librenms /opt/librenms-skins/scripts/uninstall.sh
```

Pages go stock dark at this point.

**4. Set the default** to the skin the host was running: Plugins → Theme
Selector → Instance default → **Zerg** → Set default. That restores the look
for everyone and re-applies Zerg's graph palette. The plugin records each
graph key's original state before overwriting it; because step 3 ran first,
those originals are LibreNMS's own values, not the old installer's.

**5. Check:**

- [ ] A page shows two `data-theme-selector` links in `<head>` (view source),
      and no `css/custom/zerg/zerg.css`.
- [ ] **The login page shows the default skin.** Log out to see it. (The Docker
      instance logs in by header, so this is the first place it can be seen.)
- [ ] The navbar stays pinned when scrolling (fixed in `080046e`).
- [ ] Graphs use Zerg's colours.
- [ ] A second user can pick a different skin without affecting yours.

**6. Let one `daily.sh` run happen** (or run it: `sudo -u librenms ./daily.sh`),
then check the plugin is still listed in `composer.plugins.json`, the
Theme Selector menu entry is still there, and the skin still applies.

**7. Clean up** once satisfied:

```bash
sudo rm -rf /opt/librenms-skins
```

Then `scripts/install.sh` and `scripts/uninstall.sh` can be deleted from this
repo (docs/PLUGIN.md, phase 2).

**If something goes wrong** before step 7, the old setup is one command away:
`sudo -u librenms /opt/librenms-skins/scripts/install.sh zerg`, after
disabling the plugin (`./lnms plugin:disable ThemeSelector`).

---

## Graph colours

RRDtool draws graphs on the server from instance-wide config, so CSS can't
reach them and they can't follow each user's skin (yet: docs/PLUGIN.md,
phase 5). They follow the **instance default** instead:

- Setting a default writes that skin's palette (`skins/<id>/graph.conf`) into
  LibreNMS config: `rrdgraph_def_text_dark` (background, grid, frame),
  `rrdgraph_def_text_color_dark`, and the `graph_colours.*` ramps.
- Before a key is first overwritten, its original state is recorded: whether
  the database held an override, and its value.
- Switching to another default restores any key the new palette doesn't set.
  Clearing the default restores everything. Keys that had no override before
  are erased rather than pinned, so LibreNMS's own defaults keep applying
  after upgrades.
- `graph_colours.port_in` / `port_out` are skipped unless the port-graph patch
  below has declared them.

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

What stays behind, harmlessly: the `theme_selector_settings` table and
`theme_selector.skin` rows in `users_prefs`. To remove them too:

```sql
DROP TABLE theme_selector_settings;
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
