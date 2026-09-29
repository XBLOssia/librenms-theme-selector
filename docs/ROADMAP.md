# Roadmap

Where the project actually stands, and what to pick up next.

Last updated 2026-09-21, against LibreNMS master @ `63e0394`.

---

## Honest status

Three skins are complete, verified and installable. But "complete" means the
application frame — navbar, panels, tables, buttons, forms, alerts, labels,
tabs, modals. It does not mean every component.

Run `./scripts/coverage.sh /opt/librenms` for the current number. As of today:

| Stylesheet | Coverage |
|---|---|
| `base/base.css` (shared by every skin) | 179 / 218 (82%) |

(Was 183 / 218 for each of the three standalone skins. Four selectors were
named only in those files' comments, which the split into `base.css` did not
carry over. The number is now one, not three, because the skins share it.)

That number went *down* from a previously reported 85%, twice, because the
measurement was wrong both times — see below. Group A is 92/92; the remainder
is group B legacy.

There are **two** denominators, and using only the first hid a real gap for
several rounds:

- **A — `tw_dark.css` (92):** components upstream gives dark-mode treatment.
  All three skins cover 92/92.
- **B — `styles.css` (123):** colour-bearing classes `tw_dark.css` *never*
  overrides, so they render identically in light and dark. Skins cover 92/123.

Measuring against A alone reported **100%** while the navbar search dropdown was
`#fff`, device-overview rows were `#f9f9f9`, and the availability map boxes were
stock Bootstrap. `scripts/coverage.sh` now reports both.

The ~31 still uncovered in B are overwhelmingly dead Observium-era classes
(`.datacell`, `.shadetabs`, `.dropdown_3columns`). That was first judged from
`resources/views` alone; a full survey across every emitter (FINDINGS section 7)
confirms the pattern and finds 127 dead rules in `styles.css`, 671 lines.
`.body-1`, named here originally, turns out to be *at risk* rather than dead —
something concatenates a `body-` prefix. Deliberately not chased for the
skins; run `coverage.sh <path> <skin> -v` to see them.

All three sit at the same number because they share structure. Fix a gap in one
and the same gap exists in the other two; the work is parallel by construction.

Coverage counts *selectors answered*, which is not the same as *correct*. It
cannot see contrast failures, elements whose colour comes from a JavaScript
string, vendored stylesheets like `MarkerCluster.Default.css`, or a rule of the
skin's own that breaks something else. Every one of those happened, and every
one was caught by **[harness/audit.js](../harness/audit.js)** run against a
live page — not by this number.

Treat coverage as a floor, and the live audit as the actual test.

---

## Done — deployed and walked

Zerg, Protoss and Terran have all run on a live production instance — LibreNMS
`26.8.1-147-g63e0394bd1`, the exact commit the skins were built against, around
1,400 devices. It now runs as the Theme Selector plugin; see
[DEPLOYMENT.md](DEPLOYMENT.md).

Walking real pages is what produced everything in the Completed section below,
and it found things the harness structurally could not:

- **Dashboard widget title bars** — found because no stylesheet-based audit
  could see them: the class list is assembled in a JavaScript template string.
  Note the original write-up over-claimed this as *unthemeable*; it is not, and
  that claim is retracted in FINDINGS §2b.
- **Scrollbars**, which upstream never styles, so every scrollable widget
  showed bright browser chrome.
- **Alert-rule row contrast**, which turned out to be an upstream bug rather
  than a gap in the skins.
- **The `/eventlog` filter placeholders**, at 1.2:1 — first recorded here as
  a second upstream bug. **Retracted:** stock dark leaves the select2 field
  white, where core's `#272b30` ink reads at 14.2:1. The skins' own field
  darkening caused the failure (FINDINGS §2c).
- **Inline `tw:` utilities**, which `coverage.sh` cannot see at all because
  they are in neither denominator. Chasing these overturned the central claim
  of FINDINGS §2 — they are reachable after all, via the prefixed theme
  variables. Skins now remap core's dark ramps; see §16 of any skin.
- **Port graph *series***, which no skin can theme. The chrome themes fine, and
  the split is by graph **type**, not by page — see Not planned below.

Still worth a look when convenient: the rule builder (`query-builder` is
unstyled upstream), a datetimepicker, and the narrow/mobile layout.

The harness itself got closer to the real thing in the process: it now vendors
the Vite bundle (`html/build/assets/app-*.css`) instead of the standalone
`bootstrap.min.css` the application does not actually load. That bundle
carries the cascade layer declaration and the `--tw-color-*` theme variables,
without which the `tw:` utilities resolve to nothing and the harness shows
colours the real page never renders.

---

## Completed — the coverage pass

The original priorities 2–5 are done, generated from a single template so the
three skins could not drift. Closed: contextual panels,
`.text-*` / `.bg-*`, headings, `.label-primary`, `.btn-info`, list groups,
pagination, `.close`, popovers, `.navbar-toggle`, bordered/responsive tables,
form validation states, LibreNMS-specific classes, legacy `.blue/.grey/.red`,
select2 and overlib.

Two additions not on the original list, both found by looking at a real
instance rather than the harness:

- **Scrollbars.** LibreNMS styles none, so every scrollable widget rendered a
  bright browser-default trough against a dark UI. The single most conspicuous
  stock element on a dashboard, and free to fix — upstream never touches it.
- **Map tiles.** `html.dark .leaflet-tile` is the same specificity the skins
  use, so source order lets custom_css retune the filter per skin.

Still stock because `tw_dark.css` never styled them, so they are outside the
coverage denominator: `query-builder` (alert rules), `bootstrap-datetimepicker`,
`bootstrap-switch`.

---

## Verifying a skin

Three tools, and the order matters.

**1. `harness/audit.js` — run this first.** Paste into devtools on a logged-in
page with the skin active. Reports light surfaces that shouldn't exist and any
text below WCAG AA. This is what actually finds problems: the widget header
built in a JS string, the vendored Leaflet cluster markers, 77 icon buttons
whose font-family the skin had clobbered, and five contrast failures the skins
themselves introduced. None were visible to a stylesheet-based check.

Run it on at least `/`, `/devices`, `/alert-rules`, `/eventlog`, `/graphs` and
a device graph page. All three skins return zero findings on all six.

`/eventlog` earns its place on that list: it is the only one that exercises a
select2 placeholder, which is where the skins' own field darkening turns
core's dark placeholder ink into 1.2:1. It sat unnoticed through every
previous audit round — and was then misattributed to core, because it was
only ever measured with a skin active. **Measure stock with the skin link
disabled before calling anything an upstream bug.**

`/graphs` earns its place the same way, and later. It is the only page that
pairs `tw:dark:bg-white!` with a dark `tw:dark:text-gray-800`
(`graphs/show.blade.php:53`), so a skin that repaints the background without
also setting the text lands at 1.19:1 — worse than the white box it replaced.
That regression shipped in `d266a63`, survived every audit round, and was
reported by a user rather than caught here, because no page on this list
exercised it.

**Open collapsed things before you trust a clean run.** `audit.js` skips any
element under 4x4px, so anything inside a `display:none` container measures 0x0
and is silently not audited. Verifying `/graphs` this way returned zero
findings three times while four white 319x29 inputs sat in the closed
date-range picker. Menus, modals, accordions and pickers all need opening
first. See FINDINGS 2 for that case and its fix.

**3. `harness/leaks.html` — the property-level check, in the harness.**
`audit.js` looks for *light* surfaces, so it cannot see a stock *dark* colour
sitting on a skin that isn't that colour. That is how every skin shipped
with `#2e3338` behind its tables: LibreNMS's `.dark table` and
`.dark .table-responsive > .table` (specificity 0,1,1 and 0,3,0) paint it, and
our rules set borders and text on `.table` but never a background. Terran's
gunmetal hid it; Protoss's navy and Zerg's plum made it obvious. It was
reported from a production screenshot, not caught here, on 2026-09-29.

`leaks.html` finds every place a stock rule still decides a background: on the
elements the harness pages render, on bare probe elements for stock rules
whose elements the harness doesn't render, and by text contrast for the states
that go unreadable when a stock background wins. It found the tables, nested
tables, BGP stripes, and two real contrast failures: read-only and disabled
inputs at 1.7-1.9:1 (stock `.dark .form-control[disabled]` is 0,3,0; ours was
0,2,1) and contextual `th` cells at 2.2:1. Stock colours that are legible and
deliberately left are listed on the page with their reasons; anything else
fails. Run it after any change to `base.css`; it needs no LibreNMS install.

Coverage by selector (`coverage.sh`) says a selector was answered, not that
the property that paints the pixels was. That gap is the whole story here.

**Hard reload is not enough after editing a skin.** `webui.custom_css` is
served without a cache buster, so the browser keeps the old stylesheet —
Ctrl+Shift+R did not shift it. Append a query string to the `<link>` href
before measuring, or you will verify the previous version of your own fix.

**2. `scripts/coverage.sh` — run this second**, as a floor. It answers "did I
forget a component", not "does it look right".

### Harness gaps

`harness/index.html` renders roughly what the skins already cover, so it is a
weak regression net on its own. Worth adding:

1. The components from the coverage pass — contextual panels, `.text-*`,
   pagination, list groups, popovers, `.close`
2. A narrow-viewport view so `navbar-toggle` is exercised
3. A real select2 and a datetimepicker
4. Form validation states
5. Dashboard widget markup (`grid-stack-item-content > header`), and an
   icon-on-a-button (`<button class="btn fa fa-x">`) — the two shapes that
   caused the most rework
6. An inline `tw:`-utility fixture, including a `tw:dark:bg-white!` and a
   `tw:dark:text-red-500!` — and a **bare** `tw:bg-white` with no `dark:`
   companion, which is a separate bug class the `!`-matching rules miss.
   These are invisible to `coverage.sh` by construction and were the source
   of the last round of live-only bugs
7. The graph-page date-range picker, **in both states**. Its date and time
   inputs carry bare `tw:bg-white` inside a `display:none` panel, so they are
   invisible to `coverage.sh` and to `audit.js` alike until the picker is
   opened — four white 319x29 boxes that survived three consecutive clean
   audit runs on `/graphs`. The fixture needs the collapsed container and a
   toggle, not just the open markup: the state worth testing is the one where
   a skin looks clean and is not. Any other collapsed component would serve
   the same purpose; this is simply the one that caught us

A `?compare` mode rendering all three skins side by side would make drift
obvious at a glance.

---

## Open decisions

**~~Screenshots in the README.~~ Done** — three of them, one per skin, in
`docs/img/`. Not from the real instance: an earlier version of this note said
they should be, but that would mean publishing hostnames, interface
descriptions, site names and a map centred on real geography, and redacting
all of that afterwards is error-prone and looks it.

They are captures of `harness/mockup.html`, whose markup is read off a running
instance rather than invented, with graphs rendered by rrdtool from a
synthetic RRD. `./scripts/capture-mockups.sh` regenerates them deterministically.

**Upstream.** *Status: **posted**, 2026-09-21 —
[community.librenms.org → Projects](https://community.librenms.org/t/a-theme-system-for-librenms-a-phased-proposal/29463).*
It is public now, so it can no longer be quietly revised; corrections have to
be replies.

Drafted — see [PROPOSAL.md](PROPOSAL.md). Scoped to a phased
**theme system**: admin installs a theme from a validated JSON manifest, users
select it, custom themes are deletable and built-ins protected. Five phases,
each independently shippable:

- **0** — **three** small fixes, no theme system required: drop the `!` from
  22 inline colour utilities (0a), tokenise the 58 graph-helper literals (0c),
  fix contextual row contrast (0d). *0b — the widget header class — is
  withdrawn; its premise was wrong.* 0a is also weaker than first drafted: the
  utilities are reachable via the prefixed theme variables, so the argument is
  "requires two undocumented Tailwind facts", not "impossible". **0c is the
  strongest of the three** and has a scoped patch already written.
- **1** — define the token contract from the 603 literals in `styles.css` +
  `tw_dark.css`. Pixel-identical. This list *is* the theming API.
- **2** — one palette source for both CSS and graphs.
- **3** — themes as data: a `themes` table shaped like `custom_map`, built-ins
  seeded from `resources/definitions/`, `lnms theme:import`.
- **4** — upload/delete UI, policy-gated.

Security model is the load-bearing part: a theme is a validated token manifest,
never arbitrary CSS, because arbitrary CSS enables exfiltration via
`url()`, clickjacking, and remote beacons.

Venue is the forum's Projects category (GitHub Discussions is disabled on the
repo). AI tooling is disclosed up front in the post.

### Ready to write: the Phase 0c patch

Scoped this session, not yet written. `includes/html/graphs/generic_data.inc.php`
is the highest-value single file in Phase 0c — it is behind `port_bits`, the
most-viewed graph in the product, and it reads no config at all.

- **Six lines carry the in/out series** — 149–151 and 157–159. Those are the
  ones users see as green and lavender.
- **The other twelve literals stay.** Percentile rules, port-speed lines and
  prediction overlays are arguably *meant* to be fixed; changing them widens
  the diff and the argument for no gain.
- **The pattern already exists in the same directory.**
  `generic_multi_bits_separated.inc.php` does
  `LibrenmsConfig::get("graph_colours.$colours_in.$iter")`. This is applying an
  in-tree idiom to a helper that predates it, not inventing anything.
- **Pixel-identical if the defaults keep the current values**, which is the
  whole reviewability argument — and the before/after is easy to evidence by
  sampling the PNG histogram rather than eyeballing it.

Deliberately **not** written yet. Submitting a working PR before the forum
conversation cuts against the sequencing murrant asked for on #19029, which
this whole proposal is built on. Write it when 0c is welcome, not before.

Resolved since:

- **~~Whether to make this repo public.~~** It is public, as of 2026-09-21,
  after a pre-publication sweep (see the commit log). The post does not link
  it — that was written while the repo was private and is now a free move
  available as a follow-up reply if the thread warrants it.
- **~~Whether to generate screenshots first.~~** Three exist, one per skin, in
  `docs/img/`. Note these are *skin* screenshots; the post separately offers
  **before/after** evidence for anything claimed pixel-identical upstream,
  which is a different artefact and still unbuilt. It is only needed if a
  phase is welcomed.

Still open:

- Don't write a line of Phase 1 until the token contract question gets an
  answer — that list becomes the theming API and is the expensive thing to get
  wrong.
- **Phase 0c has a patch already scoped but unwritten** (see "Ready to write"
  above). It is running locally on the production instance; that is a
  downstream hack, not a submission. Write the real thing when 0c is welcome.

---

## Not planned *here*

These are deliberately out of scope for this repo. Two of them are out of scope
because they belong upstream, not because they're unwanted — see
[PROPOSAL.md](PROPOSAL.md).

- **Per-user theme selection via `site_style`, as a local hack.** It would
  need a patch to `resources/definitions/config_definitions.json`, which
  updates overwrite, so doing it downstream means re-patching forever
  (FINDINGS §6). *Superseded 2026-09-25:* a package plugin can do per-user
  selection without touching `site_style` or any core file — see
  [PLUGIN.md](PLUGIN.md).

- **Theming graph interiors beyond what config allows.** Worth stating
  precisely, because an earlier version of this file got it wrong:

  | Surface | Themeable? |
  |---|---|
  | Graph chrome — background, grid, frame, arrows | **Yes**, via `rrdgraph_def_text_dark`. Done, on *every* graph including port graphs. |
  | Series on config-reading helpers (10 of 15) | **Yes**, via `graph_colours.*`. Done. |
  | Series on the 5 config-blind helpers, incl. `port_bits` | **No.** 40 literals, zero config reads. |

  CSS can't reach any of it — RRDtool renders server-side — but "unthemeable"
  was too strong twice over. Chrome themes everywhere, and two thirds of the
  shared helpers already read config.

  **This is a property of the graph type, not of the page.** Everything renders
  through one `/graph/id=N?type=X` endpoint; there is no dashboard-specific
  path. A dashboard built mostly from `port_bits` widgets therefore looks
  entirely unthemed while the device pages beside it look correct. Measured by
  sampling the PNGs:

  | `type` | Helper | Dominant colours |
  |---|---|---|
  | `port_bits` | `generic_data` | `#0f1a2e` (ours) · `#90b040` `#8080c0` (stock) |
  | `device_bits` | `generic_multi_bits_separated` | `#3fb8f5` `#3ad6a8` `#2cb08a` `#218c6e` (all ours) |

  Fixing it downstream would mean patching a core file that `daily.sh` reverts,
  so it stays out of scope *here* — but it is Phase 0c of the proposal, and the
  patch is now scoped (see below).

- **Supporting LibreNMS older than current master.** Selectors are verified
  against `63e0394` only.

- **Arbitrary-CSS theme upload.** Not planned anywhere, including upstream. A
  theme should be a validated token manifest; arbitrary CSS enables
  exfiltration via `url()`, clickjacking and remote beacons. PROPOSAL.md has
  the reasoning.
