# Changelog

What changed in each release of Theme Selector for LibreNMS, newest first. The format follows
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/) and the versions follow [Semantic Versioning](https://semver.org/):
a major version for a change that breaks skins that were valid before (the bundle format, a token's
meaning, a removed column), a minor one for something new (a skin, a token, a feature, an additive
migration), a patch for fixes, styling and documentation. How a release is made: [docs/RELEASING.md](docs/RELEASING.md).

Write what changed under **Unreleased** as part of each pull request. `scripts/release.sh prepare` turns
that section into the next version's.

## [Unreleased]

### Skins
- **The easter eggs got more to do, and they show on any page** (not only a device page). The white rabbit in
  Digital Rain stays three and a half seconds (it was two), and clicking it blacks the screen out for under
  five seconds with a line typed on it and a cursor that blinks three times, then it is gone as if it had not
  happened. The eye in the Paisley skins, when clicked, makes purple rain fall all over the screen for ten
  seconds, then fade. Still CSS only (no script), hidden for visitors who ask for reduced motion, and
  bundled-only. The chances are unchanged (one load in ten, one in twelve), so with every page now eligible
  they will be seen more often.

## [1.1.0] - 2026-10-12

### Skins
- **Renamed** (display names and ids): Protoss is now **Ascended** (`ascended`) and Zerg is now **Organic**
  (`organic`), in line with how the other designs inspired by something are named. A new migration carries
  every user's choice and the instance default over to the new ids, so nobody's skin changes; uploaded skins
  are unaffected. Anything of yours that names `protoss` or `zerg` (a bookmark of `?skin=zerg`, a script) needs
  the new id. The old directories are removed from the web root on the next publish.
- **Easter egg.** The Paisley skins have a rare effect of their own: on one device-page load in twelve, an eye
  in a triangle opens in the bottom-left corner, glances either way and fades after five seconds, in the
  skin's gold and violet. CSS only, hidden under reduced motion, and bundled-only like Digital Rain's rabbit
  (on the parity list in docs/ROADMAP.md).
- **Fixed:** a hovered row that carries a status colour (success, info, warning, danger) was unreadable on
  every dark skin: the cell took the full status colour and the links inside stayed the skin's bright text
  (1.4:1 to 2.3:1 on a light green, yellow or blue; white on a light red was under 3:1). It is now the status
  colour mixed into the skin's ground, with the skin's bright text: 5.6:1 or better on every bundled skin.
- Updated for how LibreNMS master now looks (it has moved on since 26.9.1): the Alerts menu state is an
  icon, not a count, and the skins' glow, pulse and danger colour now sit on it (Terran's LED, Protoss's
  pulse, Zerg's swell, the ornament skins' glow; those two are now Ascended and Organic); the new device page, filters, date picker, hover cards and
  settings pages, which paint themselves with Tailwind's own greys, blues, greens, reds and ambers, follow
  the skin's roles in dark and light mode; `.lnms-input` fields follow the skin; the light palette gains the
  shades the new markup uses. Skins written for earlier LibreNMS releases are unaffected.
- **Paisley**, a purple-led pair in one family: **Dawn** (light mode: lilac and white, peach and gold)
  and **Velvet** (dark mode: deep aubergine, white type, peach and gold trim). Soft corners (two long,
  two short) with a gold flourish at each, a lace scallop and a ribbon on every panel heading, a string
  of pearls along the navbar, and a faint computed paisley print behind the page. Inbound traffic on
  graphs is violet and outbound is peach. Both come from `scripts/make-paisley.py`, use the Playfair
  Display and Libre Baskerville files already in the package, and are valid as uploads.

### Tooling
- A weekly check of LibreNMS master for design changes (`scripts/upstream-drift.py`, the Upstream design check
  workflow): one issue when something new needs an answer, closed when the skins have caught up.
  See docs/UPSTREAM-DRIFT.md. `docs/PREVIEWING.md` says how to look at a skin before a release.

## [1.0.0] - 2026-10-09

The first release: what has been running in production, as a version a host can follow.

### Skins
- Seven bundled skins: **Terran**, **Protoss**, **Zerg**, **Digital Rain**, and **Clock Tower** in three
  moods, **Daylight** (light), **Lantern** and **Gotham** (dark). Fonts and textures are bundled; nothing
  is fetched from elsewhere.
- The plugin page says who made the plugin and links to the project, with the installed version.
- Light and dark mode: every user picks a skin for each mode, with a live preview on a sample page
  before applying; any skin can go in either slot. Admins set an instance default for each mode.
- Skins can be uploaded by an administrator as a validated `.zip`; what is served is regenerated from a
  strict parse, never the upload itself (see docs/SECURITY.md). A family label groups related skins.
- Ornament layers an upload may use too: panel frames, cut corners, motion, glow, and a drifting page layer.

### Graphs
- Graph colours follow the skin of the mode LibreNMS draws the graph in, per user, down to the colours of
  the port traffic series, without editing any LibreNMS file.

### Installing and updating
- Install and uninstall as a LibreNMS package plugin (docs/DEPLOYMENT.md). The install uses a git-mode
  Composer repository, which does not hit GitHub's API limit.
- LibreNMS's own nightly `daily.sh` updates the plugin; `lnms theme-selector:status` checks it,
  `scripts/update.sh` updates now, and `scripts/ensure-installed.sh` is an optional cron safety net.
- Releases are tags. A host that follows `^1.0` takes only what is released.

### Security and privacy
- Uploads are treated as hostile input; every control has a test, and a mutation check proves the tests
  would notice it breaking. No request leaves the host and no visitor's browser contacts anyone else.
