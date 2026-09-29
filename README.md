# Theme Selector for LibreNMS

StarCraft-inspired skins for [LibreNMS](https://github.com/librenms/librenms).

Three skins: **Terran**, **Protoss** and **Zerg**.

All artwork is original CSS — gradients, shadows and generated geometry. No
Blizzard assets are used or redistributed. These are "inspired by" skins, not
asset ports.

---

## Status

| Skin | Geometry | Palette | Type |
|---|---|---|---|
| **Terran** | Square, riveted, symmetric | Gunmetal + hazard yellow, red LEDs, green phosphor | Saira Condensed + JetBrains Mono |
| **Protoss** | Chamfered, gold-bracketed | Void blue + keratinous gold, psionic flame | Cinzel + Rajdhani |
| **Zerg** | Asymmetric, grown, uneven | Creep purple + bone, ichor green, ember orange | Metamorphous + Chakra Petch |

All three are installed and verified on a production instance. They cover
**92 of 92** components LibreNMS's dark theme styles, and **179 of 218** once
you also count the `styles.css` classes the dark theme never touches — most of
the remainder being dead Observium-era classes. A full survey of
`styles.css` finds 127 rules — 671 lines — that nothing in LibreNMS can match
(see `docs/FINDINGS.md` section 7).

```bash
./scripts/coverage.sh /opt/librenms          # the floor
```

Coverage counts selectors answered, not whether it looks right — and it does
not count the inline `tw:` utilities at all, which is where several real bugs
lived. The real test is [the live audit](#auditing-a-live-instance), which all
three skins currently pass with zero findings on `/`, `/devices`,
`/alert-rules`, `/eventlog` and the graph pages.

[docs/ROADMAP.md](docs/ROADMAP.md) has the backlog and open decisions.

Verified against LibreNMS master @ `63e0394` (2026-09-17).

---

## What they look like

Each is the same LibreNMS dashboard, same markup, same data — only the skin
differs.

### Terran
Gunmetal plating, hazard yellow, green phosphor readouts. Square and riveted.

![The Terran skin on a LibreNMS dashboard](docs/img/dashboard-terran.png)

### Protoss
Void blue and keratinous gold, chamfered corners, psionic teal.

![The Protoss skin on a LibreNMS dashboard](docs/img/dashboard-protoss.png)

### Zerg
Creep purple and bone, acid green against ember and magenta. Asymmetric,
uneven, grown rather than built.

![The Zerg skin on a LibreNMS dashboard](docs/img/dashboard-zerg.png)

**None of these are screenshots of a production instance.** Every hostname,
interface, site and number is invented, and the page says so in its own
header. They are captures of [the offline mockup](#the-dashboard-mockup),
whose markup is read off a live instance so the rendering is faithful. The
graphs are genuine rrdtool output from a synthetic RRD — a real renderer, with
traffic that never existed.

Regenerate them with:

```bash
./scripts/make-demo-graphs.sh     # once; needs rrdtool
python -m http.server 8777
./scripts/capture-mockups.sh      # headless Chrome -> docs/img/
```

Deterministic by construction — fixed window size, fixed device scale factor,
fixed epoch in the synthetic data — so re-running does not churn the repo.

---

## Install

Theme Selector is a LibreNMS plugin. On the LibreNMS host, as the `librenms`
user in `/opt/librenms`:

```bash
php scripts/composer_wrapper.php config --global repositories.theme-selector vcs https://github.com/XBLOssia/librenms-theme-selector
./lnms plugin:add xblossia/librenms-theme-selector dev-main
./lnms migrate --force
php artisan route:cache
```

Then **Plugins → Theme Selector**: each user picks a skin for themselves, and
admins set the instance default (what the login page and users who haven't
chosen get). Skins apply in dark mode; users on Light see stock LibreNMS.

**Nothing else to install.** Each skin bundles its own webfonts (~58–77KB of
Latin-subset woff2, all SIL Open Font License). No system fonts to chase, and
no request ever leaves the box.

Updates, uninstalling and troubleshooting: **[docs/DEPLOYMENT.md](docs/DEPLOYMENT.md)**.

### Safety

No LibreNMS core file is modified. The plugin adds one table of its own,
copies static files into `html/css/custom/theme-selector/` (gitignored by
LibreNMS), and stores each user's choice in `users_prefs`. It survives
`daily.sh`, which reinstalls plugins after every update and never runs
`git clean`.

> **Graphs follow each user's skin too.** RRDtool draws them on the server
> from config, so on a graph request the plugin overrides the palette in
> memory for that one request. The instance default's palette is what
> LibreNMS stores, for graphs no logged-in user asked for.

> **One optional exception.** `scripts/patch-core.sh` patches two core files
> so port traffic graphs read their colours from config instead of six
> hard-coded hexes. It is opt-in, byte-identical with no config set, fully
> reversible, and `daily.sh` reverts it on every LibreNMS update, so it has to
> be re-applied. Without it, port graphs stay stock green-and-lavender while
> everything else themes. See [docs/DEPLOYMENT.md](docs/DEPLOYMENT.md).

---

## Retheming

A skin is a token file, `skins/<id>/skin.css`: `--ts-*` values that the shared
[base stylesheet](base/base.css) applies to LibreNMS. Every token has a
default, so a skin sets only what it changes. The 20 core roles (surfaces,
text, accent, status colours, fonts, radius) are enough for a complete skin;
[examples/minimal/skin.css](examples/minimal/skin.css) is exactly that. The
full list with defaults is [docs/TOKENS.md](docs/TOKENS.md).

The bundled skins set far more: [terran](skins/terran/skin.css) ·
[protoss](skins/protoss/skin.css) · [zerg](skins/zerg/skin.css). Each also
keeps a private `--p-*` palette its tokens refer to.

### Typography

Terran uses two voices: `--tn-font-chrome` (condensed caps) for the frame —
navbar, panel headers, table headers, buttons — and `--tn-font-data`
(monospace) for the readouts — device hostnames, table body cells, status
labels and badges. Body cells also get tabular figures so uptimes and counters
align down the column.

Protoss uses the same split, but louder: carved ceremonial capitals for the
frame against a clean futuristic sans for the data. Zerg puts a gnarled organic
display face on the frame and keeps a readable angular sans on the data — the
weirdness lives in the geometry instead, which is what keeps it usable.

All three bundle their faces, so this works with no setup and no external
requests — which matters on an air-gapped NOC box, where a Google Fonts
`@import` would silently degrade exactly where it is least convenient to
debug. Details, sizes, licensing and how to swap a face:
[terran](skins/terran/FONTS.md) · [protoss](skins/protoss/FONTS.md) ·
[zerg](skins/zerg/FONTS.md).

---

## Why these are "skins" and not themes

**And why they will stay skins.** When this was proposed upstream, two
maintainers said installable themes are not wanted — *"LibreNMS isn't
Wordpress"* — and offered the alternative of **selectable built-in colour
schemes hosted in the LibreNMS codebase**
([thread](https://community.librenms.org/t/a-theme-system-for-librenms-a-phased-proposal/29463)). So there is no third-party theme format coming, by choice
rather than by omission, and the upstream path for these three is to become
built-ins rather than to be installed.

LibreNMS has no theme installation system. There is no packaging format, no
distribution story, and no way to register a new theme without patching a core
file that updates overwrite. The two built-in hooks are:

- `webui.custom_css[]` — an array of stylesheets appended last. Instance-wide,
  not per-user. The skins used this before the plugin.
- A `site_style` entry in `resources/definitions/config_definitions.json` —
  gives a per-user dropdown, but that file is core and is overwritten on update.

The plugin system's five hooks (`DeviceOverviewHook`, `MenuEntryHook`,
`PortTabHook`, `SettingsHook`, `SinglePageHook`) all inject content, and none
publish CSS or assets. A *package* plugin, though, owns a Laravel service
provider, and that can push a stylesheet into every page's `<head>`, per user,
with no core change. That is how Theme Selector works; see
**[docs/PLUGIN.md](docs/PLUGIN.md)**.

Building these surfaced concrete, measurable problems with theming LibreNMS as
it stands. They are written up in **[docs/FINDINGS.md](docs/FINDINGS.md)** with
reproducible numbers — that document, not the skins, is the interesting output
of this project. **[docs/PROPOSAL.md](docs/PROPOSAL.md)** turns it into a
phased upstream proposal — three small fixes that need no theme system, then
the token work, then built-in colour schemes on top of it.

---

## Test harness

You can preview and verify a skin without a LibreNMS install.
`harness/leaks.html` checks that no stock LibreNMS background is still showing
through a skin and that the awkward states (read-only inputs, contextual table
cells) stay readable; run it after any change to `base/base.css`.

```bash
# one-time: vendor the stylesheets from a LibreNMS checkout
./harness/sync-css.sh /path/to/librenms

python -m http.server 8777
# then open http://localhost:8777/harness/
```

Switch skins with `?skin=terran` / `?skin=protoss` / `?skin=zerg`, or the
buttons at the top of the page. `harness/colorway.html` renders a skin's full
token set and graph ramps.

### The dashboard mockup

`harness/mockup.html` is a LibreNMS dashboard reproduced offline with invented
data — hostnames, interfaces, sites, counts, all fictional. It exists so the
project can be shown without publishing anything from a production instance.

```bash
./scripts/make-demo-graphs.sh          # once; needs rrdtool
python -m http.server 8777
# http://localhost:8777/harness/mockup.html?skin=protoss
```

The markup is read off a running instance rather than guessed, which paid for
itself immediately: the first draft used `.availability-map-oldview-box-*`
(styled by the skins, but not what this LibreNMS renders) and reported three
contrast failures that do not exist on the real page. Widget header colour and
font now match the live DOM exactly.

The graphs are genuine rrdtool output, not CSS imitating a graph.
`scripts/make-demo-graphs.sh` renders them with the same chrome options and
the same in/out ramps the skin ships, from a synthetic RRD on a fixed epoch —
so the PNGs are byte-reproducible and contain no production data.

Two things are deliberately *not* the real thing, and the page says so: the
world map is an abstract drawing (any real tile is a real place), and nothing
is interactive.

### Auditing a live instance

The harness cannot show you a gap it does not contain, so the real test is
**[harness/audit.js](harness/audit.js)** — paste it into devtools on a
logged-in LibreNMS page with a skin active. It reports light surfaces that
should not exist and any text below WCAG AA, measured on what the browser
actually computed.

That is what found the dashboard widget header (its colour lives in a
JavaScript string), the vendored Leaflet cluster markers, 77 icon buttons whose
glyphs the skin had broken, six contrast failures the skins introduced
themselves, and one that core ships — the down-device links, at 3.35:1 on a
table row and 3.01:1 on an alternate row. (A seventh, the `/eventlog` filter
placeholder at 1.2:1, was first listed as core's; it is the skins', and
FINDINGS §2c records the retraction.) A stylesheet-based check saw none of
them.

The harness reproduces LibreNMS's real DOM and loads the real stylesheets in
the real order from `resources/views/layouts/librenmsv1.blade.php`. Vendored
CSS and webfonts are gitignored — LibreNMS is GPLv3 and its assets are not
redistributed here.

---

## Repository layout

```
composer.json               the LibreNMS package plugin (xblossia/librenms-theme-selector)
src/                        plugin code: provider, picker, publisher, graph palette
routes/, resources/views/   the Theme Selector page
database/migrations/        the plugin's settings table
base/base.css               the base stylesheet: token defaults + every rule
skins/<name>/skin.css       a skin: token values, private palette, @font-face
skins/<name>/skin.json      manifest: name, description, modes
skins/<name>/graph.conf     graph palette, applied when it's the instance default
skins/<name>/fonts/         bundled OFL webfonts + licence notices
skins/<name>/FONTS.md       typography rationale and how to swap faces
examples/minimal/           a skin that sets only the 20 core roles
harness/index.html          static preview, real LibreNMS CSS, real DOM
harness/mockup.html         full dashboard mockup, invented data
harness/leaks.html          stock backgrounds still showing through, and contrast
harness/graphs/             rrdtool graphs rendered from a synthetic RRD
harness/colorway.html       a skin's tokens and graph ramps, rendered
harness/audit.js            live-page contrast + stock-colour audit
dev/                        Docker LibreNMS for developing the plugin
scripts/gen-token-docs.py   regenerate docs/TOKENS.md from base.css
scripts/fetch-fonts.ps1     regenerate the bundled fonts reproducibly
scripts/coverage.sh         report which components no skin has styled yet
scripts/make-demo-graphs.sh generate the mockup's graphs (needs rrdtool)
scripts/capture-mockups.sh  screenshot the mockup per skin, headlessly
scripts/patch-core.sh       optional: let port graphs read their colours
patches/                    that patch, as a reviewable unified diff
docs/img/                   the screenshots above
docs/PLUGIN.md              plugin design, decisions and phases
docs/TOKENS.md              token reference (generated)
docs/DEPLOYMENT.md          install, updates, migration, uninstall, rollback
docs/FINDINGS.md            what building these surfaced about theming LibreNMS
docs/PROPOSAL.md            upstream proposal, ready to post
docs/ROADMAP.md             prioritised backlog and open decisions
```

## Licence

[MIT](LICENSE).

Two carve-outs:

- The bundled webfonts under `skins/*/fonts/` are **SIL Open Font License
  1.1**, with the upstream notice included beside the font files in each
  directory.
- The patch under `patches/` is **GPLv3**, matching LibreNMS, which it is a
  diff against and a small amount of which it quotes as context.

No LibreNMS source is otherwise redistributed here. The harness needs several
of its stylesheets to render anything realistic, and those are fetched at
setup time by `harness/sync-css.sh` into a gitignored directory rather than
committed.
