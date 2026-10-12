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
- Updated for how LibreNMS master now looks (it has moved on since 26.9.1): the Alerts menu state is an
  icon, not a count, and the skins' glow, pulse and danger colour now sit on it (Terran's LED, Protoss's
  pulse, Zerg's swell, the ornament skins' glow); the new device page, filters, date picker, hover cards and
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
