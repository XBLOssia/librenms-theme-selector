# Deployment

Installing a skin on a LibreNMS host, and getting it back off again.

Written against LibreNMS master @ `63e0394`.

---

## Deployed instances

| LibreNMS version | Devices | Mode | Skins exercised |
|---|---|---|---|
| `26.8.1-147-g63e0394bd1` | ~1,400 | `link` | all three |

The host version matches the commit the skins were developed and verified
against exactly, so there is no selector drift to account for.

The instance itself is deliberately not named here. It is a production
monitoring box, and pairing a resolvable hostname with an exact software
version in a public repository is free reconnaissance for no benefit to
anyone reading this.

Layout on that host:

```
/opt/librenms-skins                          repo, owned librenms:librenms
/opt/librenms/html/css/custom/zerg   ->      /opt/librenms-skins/skins/zerg
webui.custom_css                     =       ["css/custom/zerg/zerg.css"]
```

The directory still carries the repo's old name, `librenms-skins`; it predates
the rename to `librenms-theme-selector`.

Files were uploaded over SFTP rather than cloned. That was necessary while the
repo was private — it avoided putting a git credential on the monitoring host —
and it is now merely a leftover: the repo is public, so a plain
`git clone` needs no credential at all.

The trade-off of the SFTP arrangement is that `git pull` will not update it in
place. Converting it to a real clone under the new name makes updates normal
and retires the old path in the same step. `install.sh` resolves the repo from
its own location, so re-running it from the clone re-points the symlink:

```bash
sudo -u librenms git clone https://github.com/XBLOssia/librenms-theme-selector.git /opt/librenms-theme-selector
sudo -u librenms /opt/librenms-theme-selector/scripts/install.sh zerg
# once the UI checks out, /opt/librenms-skins can be removed
```

Also note the example above shows `zerg` active. Whichever skin is current,
`install.sh <name>` is what changes it.

To switch the active skin on that host:

```bash
sudo -u librenms /opt/librenms-skins/scripts/install.sh terran
```

---

## Does this survive `daily.sh`?

**Yes, and unlike a core-file patch it does so by design rather than by luck.**

This was verified by reading `daily.sh` rather than assuming. The update path
uses only:

```
git pull --quiet
git checkout master | <branch> | ${latest_hash}
git checkout --quiet -- composer.json composer.lock
```

There is **no `git clean`** anywhere in `daily.sh` or `daily.php`. `git pull`
and `git checkout` do not delete untracked or ignored files, and
`html/css/custom/*` is in LibreNMS's own `.gitignore`. So the skin directory is
invisible to the updater.

The `webui.custom_css` setting lives in the database, not in a file, so it is
untouched by any code update.

### How this differs from the plugin project

`librenms-network-config`'s research notes record a one-line **core-file**
patch applied on this same host, with the warning:

> This is a core-file patch, not tracked by this repo's git. It will be
> silently reverted by any future `git pull`/LibreNMS update on that host.
> […] reapply this one-line patch after every LibreNMS update.

That hazard does not apply here. **These skins modify zero LibreNMS core
files.** There is no patch to reapply, and nothing to re-check after an update.
If a skin ever needs a core change to work, that is a bug in the skin.

### The one thing that *would* wipe it

`git clean -fdx` inside `/opt/librenms` deletes ignored files, which includes
`html/css/custom/`. That is not part of any normal update, but it is a common
"reset my checkout" reflex.

The `link` install mode below is the mitigation: the canonical skin files live
outside the LibreNMS tree entirely, so a `git clean` removes only a symlink,
and re-creating it is one command. This mirrors the pattern the plugin project
already uses for `/etc/librenms-network-config/`.

---

## Install

On the LibreNMS host, as a user that can write to `/opt/librenms/html/css/` and
run `lnms` (normally `librenms`):

```bash
sudo -u librenms git clone https://github.com/XBLOssia/librenms-theme-selector.git /opt/librenms-theme-selector
cd /opt/librenms-theme-selector
./scripts/install.sh zerg
```

Then in the browser:

1. **Preferences → Theme → Dark.** The skins are an overlay on the stock dark
   theme; on the light base they look broken.
2. Hard-refresh (Ctrl-Shift-R).

Substitute `terran` or `protoss` for `zerg`. Preview first with `--dry-run`,
which changes nothing and prints every step.

### What the installer does

1. Reads the current `webui.custom_css` and saves it to
   `html/css/custom/.previous-custom_css` — **only on first install**, so
   switching skins later cannot clobber the true original.
2. Symlinks `html/css/custom/<skin>` → `/opt/librenms-theme-selector/skins/<skin>`.
3. Sets `webui.custom_css` to `["css/custom/<skin>/<skin>.css"]`.
4. Verifies the stylesheet is readable, the webfonts are present, and the
   config took — and tells you to back out if any check fails.

It replaces `custom_css` rather than appending, because two skins loaded at
once cascade into mush. If you had your own custom CSS there, it is recorded in
step 1 and restored on uninstall.

### link vs copy

`--mode link` (default) symlinks. Updating a skin becomes `git pull` in
`/opt/librenms-theme-selector`, and nothing under `/opt/librenms` is ever edited.
Apache's `html/.htaccess` sets `Options +FollowSymlinks` and nginx follows
symlinks by default, so this serves correctly.

`--mode copy` copies the directory instead. Use it if the repo lives somewhere
the webserver user cannot read.

Both are equally reversible.

### Updating a skin

```bash
cd /opt/librenms-theme-selector && git pull
```

With `link` that is the whole update. With `copy`, re-run `install.sh`.

**Then hard-refresh — and tell your users to.** LibreNMS emits `custom_css`
entries as a plain path with no version query:

```html
<link rel="stylesheet" href="css/custom/protoss/protoss.css">
```

Nothing in that URL changes when the file does, so browsers serve the cached
copy until it expires. A normal reload is not enough; the skin will look
exactly as it did before you deployed, which is a convincing way to waste
twenty minutes debugging a change that already shipped correctly.

- **Ctrl-Shift-R** (Cmd-Shift-R on macOS) on each client, or
- from devtools on the page:
  ```js
  await fetch('/css/custom/protoss/protoss.css', {cache: 'reload'});
  location.reload();
  ```

To confirm what the *server* is sending, independent of any cache:

```bash
curl -s https://your-instance/css/custom/protoss/protoss.css | wc -c
```

This is only a papercut for skin authors — end users get the file once and it
is correct — but it bites every single deploy.

---

## Optional: the port-graph core patch

**This is the only thing in this repo that touches a LibreNMS core file.**
Everything else lives in `html/css/custom/` and a few config rows. This is a
different risk class, so it is opt-in, separate from `install.sh`, and never
run automatically.

### Why

`port_bits` — the traffic graph on effectively every dashboard — renders
through `includes/html/graphs/generic_data.inc.php`, which hard-codes its six
series colours and reads no config at all. Without the patch, port graphs stay
stock green-and-lavender under every skin while the rest of the graph themes
correctly. The graph *chrome* (background, grid, frame) is themed either way;
it is only the series that are stuck.

### What it changes

Two files:

| File | Change |
|---|---|
| `includes/html/graphs/generic_data.inc.php` | reads `graph_colours.port_in` / `.port_out`, defaulting to the values it previously hard-coded |
| `resources/definitions/config_definitions.json` | declares those two keys |

The second is not optional. `lnms config:set` validates every key against the
definitions file and refuses anything undeclared — *"This is not a valid
setting."* — and the only wildcard LibreNMS defines is `alert.macros.rule.*`.

**With no config set, output is byte-identical.** Verified rather than
asserted: the same graph URL, with `from`/`to` pinned so the data window is
fixed, produced the same SHA-256 and the same 141,496 bytes before and after
patching.

```bash
./scripts/patch-core.sh status
./scripts/patch-core.sh apply     # then re-run install.sh to set the colours
./scripts/patch-core.sh revert
```

`apply` dry-runs first, so a version drift fails loudly instead of scattering
`.rej` files through core. It keeps a pristine `*.pre-skins-patch` copy of each
file, and `revert` prefers that copy over reversing the diff.

### The catch: `daily.sh` reverts it

`daily.sh` updates LibreNMS with `git pull` and `git checkout`, which restores
tracked files. **Both patched files go back to stock on every update.** The
config values survive — they are database rows — but they stop being read, so
port graphs quietly return to green and lavender.

Re-apply after each update. The script is idempotent, so this is safe to
automate:

```bash
# /etc/cron.d/librenms-theme-selector-patch  — after daily.sh has run
30 1 * * *  root  /opt/librenms-theme-selector/scripts/patch-core.sh apply >/dev/null 2>&1
```

Check it whenever graphs look wrong after an upgrade:

```bash
./scripts/patch-core.sh status
```

### Reverting completely

```bash
./scripts/patch-core.sh revert
./scripts/uninstall.sh
```

Order does not matter. `uninstall.sh` clears `graph_colours.port_in` /
`.port_out` back to unset, and it checks whether the core patch is still
applied and tells you rather than assuming either way. With the patch reverted
and the keys cleared, nothing of this repo remains anywhere in LibreNMS.

---

## Uninstall

```bash
cd /opt/librenms-theme-selector
./scripts/uninstall.sh
```

That removes every skin directory or symlink from `html/css/custom/` and
restores `webui.custom_css` to whatever it was before the first install
(clearing it if it was empty). Then hard-refresh.

Options:

| Flag | Effect |
|---|---|
| `--dry-run` | Print every step, change nothing |
| `--skin zerg` | Remove one skin's files, leave `custom_css` alone |
| `--purge` | Clear `custom_css` outright, ignoring the saved value |
| `--librenms DIR` | Non-default install path |

The uninstaller also cleans up the older layout (a bare `<skin>.css` dropped
directly into `custom/`) in case you installed by hand before these scripts
existed.

### Manual removal

If the scripts are unavailable:

```bash
rm -rf /opt/librenms/html/css/custom/{terran,protoss,zerg}
lnms config:clear webui.custom_css
```

Then hard-refresh. That is genuinely all of it.

### What uninstall deliberately does *not* touch

Your **theme preference** (Preferences → Theme → Dark) is a per-user setting
the installer never changed, so the uninstaller leaves it alone. If you were on
Light before and want to go back, switch it yourself.

---

## Rollback confidence

| Question | Answer |
|---|---|
| Core files modified? | None |
| Database schema changed? | None |
| Files outside `html/css/custom/`? | None |
| Services restarted or installed? | None |
| Affects polling, discovery, alerting? | No — CSS only |
| Affects other users on the instance? | **Yes** — `custom_css` is instance-wide |
| Recoverable if the skin 500s the UI? | It cannot; CSS cannot break PHP. Worst case is an ugly page, fixed by `lnms config:clear webui.custom_css` |

The blast radius is one config row and one directory of static files.

### If something looks wrong

1. **Everything unstyled / stock dark** — theme is not set to Dark, or the
   browser cached the old CSS. Hard-refresh first.
2. **Fonts look generic** — the `fonts/` directory did not come along. Check
   `ls /opt/librenms/html/css/custom/<skin>/fonts/`. With `link` mode this
   usually means the webserver user cannot traverse into `/opt/librenms-theme-selector`.
3. **Some components still stock-coloured** — expected. The skins cover 40 of
   92 components; see [ROADMAP.md](ROADMAP.md).
4. **Graphs look wrong** — expected and unfixable from CSS. RRDtool renders
   PNGs server-side; see [FINDINGS.md](FINDINGS.md) §5.

---

## Note on terminology

These are **not** a LibreNMS plugin. LibreNMS's plugin system exposes five
content-injection hooks and cannot carry CSS or assets at all — that is why
these ship as `custom_css` instead. Nothing here registers with
`PluginManager`, so nothing appears under the Plugins menu, and the plugin
uninstall path is not involved. See [FINDINGS.md](FINDINGS.md) §6.
