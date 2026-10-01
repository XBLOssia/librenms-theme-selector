# Ornaments for installed skins

The three bundled skins decorate the page with things an upload is not allowed
to do: corner brackets, accent bars, rivet rows, glowing strips under the navbar,
cut corners, animation. They do it through the *structural* tokens (position,
size, offsets, `z-index`, `content`, `pointer-events`, `clip-path`, `animation`),
and those are closed to uploads because a stylesheet that can set them can put a
fake "session expired, sign in" message over the page or an invisible box over a
button (docs/SECURITY.md).

The goal here is for an installed skin to get the same *kind* of decoration
without that power. The approach is to stop handing skins the mechanics. The
plugin draws a few fixed decorative layers whose position, size, stacking and
behaviour are written in `base/base.css`, and a skin supplies only **paint**
for them: gradients, colours, bounded lengths.

## The rules every layer follows

1. **Behind the data.** A layer is painted *under* the content of the element it
   decorates (`z-index: -1` inside an isolated stacking context), so anything
   opaque in the panel covers it. A skin can't raise it.
2. **A safe zone.** It is clipped to a ring at the element's edge. The interior,
   where the data is, can't be reached however the skin paints.
3. **Can't take a click**: `pointer-events: none`.
4. **No text**: `content` is empty and fixed. A layer can't show a message.
5. **Fixed geometry.** Position, inset, size of the layer and of each slot are
   constants in `base.css`. No token feeds any of them.
6. **Only for installed skins.** The plugin marks an uploaded skin's stylesheet
   links `data-ts-orn`, and the rules are keyed on that. The bundled skins keep
   their own mechanism and are unaffected.
7. **Pinned by a test.** `tests/OrnamentTest.php` compares each rule in
   `base.css` that carries the gate with its reviewed text, declaration by
   declaration, and checks that the only tokens it reads are the paint slots.
   Changing any of it fails the build and needs a deliberate review.

## Phase A (built): panel frames and per-corner radii

**Frame slots.** Every `.panel` gets one layer that extends 8px outside the panel
and 24px inside its edge. A skin paints it with eight gradients:

| Token | Where | Size of the slot |
|---|---|---|
| `--ts-frame-tl` `-tr` `-bl` `-br` | the four corners | 32 x 32px |
| `--ts-frame-top` `-bottom` | the top and bottom edges | full length x 12px |
| `--ts-frame-left` `-right` | the left and right edges | 12px x full length |

The layer overhangs by 8px, so the outer 8px of a slot is *outside* the panel:
that is where a bar can jut out past the frame. The part inside the panel is
under the panel's heading and body, which are opaque, so it shows only through
transparent areas and on the panel's own border.

A gradient varies along one axis, but each slot is a small box, which gives the
second axis. A 32px-long bar, 4px thick, jutting out of the top-left corner is

```css
--ts-frame-tl: linear-gradient(180deg, transparent 2px, #e50832 2px, #e50832 6px, transparent 6px);
```

Layered gradients in one slot (comma-separated) make L-shapes and stacked stripes.

**Per-corner radii.** `--ts-panel-radius-tl` `-tr` `-br` `-bl` (default
`--ts-radius-lg`, at most 64px) let each corner of a panel be square or round on
its own.

## Phase B (built): headings, navbar, widgets

| Layer | Fixed mechanics | Skin paints with |
|---|---|---|
| Heading marker | a 12px band on the heading's left edge, full height, under the heading's text | `--ts-heading-marker`, `-size`, `-position` |
| Heading strip | covers the heading, under its text | `--ts-heading-strip`, `-size`, `-position`, `-repeat`, `-opacity` |
| Navbar top strip | 8px band along the top edge, under the links | `--ts-navbar-strip-top`, `-size`, `-repeat`, `-opacity` |
| Navbar bottom strip | 12px band along the bottom edge, 8px of it below the navbar, under the links | `--ts-navbar-strip-bottom`, `-size`, `-repeat`, `-opacity` |
| Widget frames | the widget's own background layers: eight fixed slots inside its edge, above its colour and under its content | `--ts-widget-frame-*`, `--ts-widget-radius-*` |

Two details worth knowing:

* The size, position and repeat of a *background* can't move, resize or raise
  anything, so the token classifier now treats `background-size`,
  `background-position` and `background-repeat` as paint. That reclassified five
  older tokens (the ones only the bundled skins' pseudo-elements read) from
  structural to settable; they have no effect for an upload, whose pseudo-elements
  don't read them.
* Bootstrap gives `.navbar::before` and `::after` `display: table`, which shrinks
  an absolutely positioned strip to zero width, so the navbar layers set
  `display: block`.

## Phase C (built): cut corners

`--ts-btn-chamfer`, `--ts-label-chamfer` and `--ts-badge-chamfer` cut the
corners of buttons, labels and badges. Each has per-corner forms (`-tl`, `-tr`,
`-br`, `-bl`) that fall back to the all-corners token:

```css
--ts-btn-chamfer: 0px;          /* the corners I don't name are square */
--ts-btn-chamfer-tl: 8px;       /* cut the top-left and bottom-right */
--ts-btn-chamfer-br: 8px;
--ts-label-chamfer: 5px;        /* all four corners */
```

* **The polygon is fixed.** `clip-path` is structural, and stays so, except in
  the one polygon that `base.css` writes for these three elements. The
  classifier (`scripts/gen-token-catalog.py`) recognises only that exact
  template; a skin gives sizes, never points.
* **The angle.** Each cut is a right triangle: `--ts-btn-chamfer-*` is how far
  it runs along the edge, and `--ts-btn-chamfer-rise` (a plain number from 0.5
  to 2, default 1) is how far it rises for each unit it runs. 1 is a 45 degree
  cut; 1.732 makes the hypotenuse 60 degrees from the edge it runs along; 0.577
  makes it 30. The rise scales the vertical legs, so a button's cut is at most
  10px across and 20px up, a label's 6px and 12px.
* **Sizes are small and in px.** At most 10px for buttons and 6px for labels and
  badges, so a cut is a little triangle at a corner and can't reach the text
  (a label's text starts about 8px in from its corner). A size must be written
  in `px` (`0px` for none): `%` and `em` would scale past the cap, a `var()` could
  point at one, and a bare `0` makes the polygon's `calc()` invalid.
* **No cut, no clip.** The tokens default to `initial`, which makes the
  `clip-path` declaration invalid until a skin sets them, so an unchamfered
  control has no `clip-path` and keeps its focus ring and shadow.
* **A cut control clips what lies outside its box**: its focus outline at the
  cut corners and any outer shadow. Bootstrap's focus ring is drawn inside the
  button, so it survives, but a skin that chamfers buttons should keep a
  visible focus style.

## Phase D (built): motion and glow

Three effects, each with its mechanics fixed in `base.css`:

| Effect | Skin gives | Fixed in base.css |
|---|---|---|
| **Breathe**: a slow fade in and out of an ornament layer | a period per layer: `--ts-frame-breathe`, `--ts-heading-marker-breathe`, `--ts-heading-strip-breathe`, `--ts-navbar-strip-top-breathe`, `--ts-navbar-strip-bottom-breathe` (2s to 60s); and how deep: `--ts-breathe-low`, `--ts-breathe-high` (.3 to 1) | the keyframes (`opacity` only), the easing, `infinite` |
| **Glow**: a soft light behind a layer's shape | a plain colour per layer: `--ts-frame-glow`, `--ts-heading-marker-glow`, `--ts-heading-strip-glow`, `--ts-navbar-strip-top-glow`, `--ts-navbar-strip-bottom-glow` | `filter: drop-shadow(0 0 8px colour)` |
| **Alert pulse**: the navbar's alert badge swelling a glow | `--ts-alert-glow-period` (2s to 60s) and two plain colours, `--ts-alert-glow-low`, `--ts-alert-glow-high` | the keyframes (`box-shadow` only, 7px to 16px) |

The same thinking as the other layers, plus three rules for motion:

* **Never faster than one cycle in two seconds**, and only opacity or a shadow
  changes, so nothing flashes (WCAG 2.3.1) and nothing moves.
* **Off under `prefers-reduced-motion`**: one rule in `base.css` turns every one
  of these animations off, and a test checks that it names every animated
  selector and sits inside the media query.
* **Off until set.** Periods and glow colours default to `initial`, which makes
  the declaration that reads them invalid, so a skin that doesn't use them has no
  animation and no filter at all.

Why the values are so strict. A glow colour lands inside a `box-shadow` (the
alert pulse) or a `drop-shadow()`, so a colour that could carry a comma would
carry a second, enormous shadow: `#f00, 0 0 100px 60px #00f` in a keyframe would
paint a 100px blot over the navbar's neighbours. So these tokens take exactly one
literal colour (`#hex`, `rgb()`, `rgba()`, `hsl()`, `hsla()`), never a `var()`
(a palette value could hold the comma), and the period and fade depth take only
their own number formats. The generic bounds (a time of at most 5s for uploads)
don't apply to a period; its own pattern decides.

The classifier treats `animation` and `filter` as structural except in these
exact shapes, so `--ts-navbar-after-animation`, `--ts-alert-badge-animation` and
the other tokens the bundled skins use stay closed to uploads.

## Phase E (built): cut corners on panels and widgets, and round corners that stay round

**Panels and widgets** take the same cut as buttons (`--ts-panel-chamfer`, `-tl -tr -br
-bl`, `--ts-panel-chamfer-rise`, and the same for `--ts-widget-*`), at most 12px, plus a
colour for the line along the cut:

```css
--ts-panel-chamfer: 0px;
--ts-panel-chamfer-bl: 12px;
--ts-panel-chamfer-rise: 1.732;     /* 60 degrees */
--ts-panel-cut-stroke: #34497a;     /* a 2px line along the cut edge */
--ts-widget-chamfer: 0px;
--ts-widget-chamfer-bl: 12px;
--ts-widget-chamfer-rise: 1.732;
--ts-widget-cut-stroke: #34497a;
```

**It is a real clip**, through one polygon written in `base.css` (the same for panels and
widgets): the element's box with a margin of 10000px, and a zero-width slit into each
corner that removes only its triangle. A cut of 0 leaves the whole box, so a skin that
cuts one corner keeps the rest. Border, background and content are cut together, so
nothing is painted over the page and the cut works on any page background, including a
texture.

* **Nothing that hangs out is cut off.** The margin is 10000px, so a dropdown that opens
  past a panel's edge, or a fixed-position dialog inside one, is not clipped; only the
  cut triangles are. (Checked in a browser: both still receive clicks on a clipped panel.)
* **Cards that open inside a panel stay on top.** A panel is a stacking context (isolation and
  clip-path), and LibreNMS renders hover cards, menus and popups inside the element that owns
  them, so without more, every later panel would paint over a card opened in an earlier one
  (the device hover card on the device page did exactly that). While the pointer is over a
  panel or widget, or the panel holds an open menu, it is raised to `z-index: 1035`: above the
  sticky navbar (1030), below modals (1040 and up).
* **The notch is grown 2px off the box edges.** Each notch is the cut triangle plus 2px on
  the two sides that lie along the box edges (the hypotenuse stays on the same line), and
  the zero-width slit that joins it to the outside runs 2px outside the box. Reason: with
  the clip edge exactly on the border's outer edge, a border snapped to a device pixel and
  an unsnapped clip disagree at fractional zoom levels (100%, 110%, 125% and 150% in one
  browser, not at 90% or 175%) and leave a one-pixel hairline of border running out to the
  old square corner. A corner with no cut loses only a 2px sliver outside itself.
* **The cut edge gets a line.** The panel's `::after` draws it above the content, on the
  panel's own corners (it is exactly the border box for the usual 1px border), so the
  line runs from the left border to the bottom border and stops. For a widget it is one
  of the widget's background layers, so the title bar covers it at a top corner. The
  sizes fall back to `0px`, never to `auto`, and nothing is drawn unless the skin sets a
  size and a colour.
* **An earlier version painted a triangle in the page colour** over the corner, because a
  clip seemed to risk cutting off dropdowns. On a flat page that worked, but on a textured
  page the flat triangle showed as a darker patch, with faint edges running out to the old
  square corner. `--ts-panel-cut-fill` is still accepted so skins written for it keep
  installing, but nothing reads it now.
* A cut corner can take a pixel or two off text that sits right at it; each triangle is at
  most 12px by 24px, and table text starts 8px in.

**Colours** (`--ts-panel-cut-stroke`, `--ts-widget-cut-stroke`) take one literal colour,
like the glow colours: no `var()`, no list.

**Rounded corners that refuse to go.** Some LibreNMS elements carry a Tailwind
`rounded-*` utility with `!important` inside the `utilities` layer (the device
page header is `tw:rounded-2xl!`). An ordinary rule can't beat that, so
`--ts-panel-radius-*` and `--ts-widget-radius-*` had no effect on them. Both
token sets now reach panels and widgets that carry any `tw:rounded-*` class, from
a re-opened `utilities` layer, the same way the `bg-white!` colours are handled.
This applies to every skin, bundled ones too: the device header takes the skin's
radius instead of a fixed 16px.

## Roadmap to parity with the bundled skins

| Bundled skin does this | With | Status |
|---|---|---|
| Corner brackets on panels (Protoss) | frame corner slots | Phase A. Behind the content, so a bracket over the heading is hidden by it; brackets drawn outside the frame or on the border show |
| Bars that jut past a frame | frame corner and edge slots | Phase A |
| Different radius per corner | `--ts-panel-radius-*` | Phase A |
| Accent bar on a panel heading (all three) | the heading marker | Phase B, built |
| Rivet / sheen row in a heading (Terran) | the heading strip | Phase B, built |
| Glow or rivet strips on the navbar's top and bottom edge (all three) | the navbar strips (a glow is Phase D) | Phase B, built |
| Frames on dashboard widgets | widget frame slots inside the widget's own edge (no overhang: LibreNMS gives widgets uneven gutters and scrolls their contents) | Phase B, built |
| Cut (chamfered) corners on buttons, labels, badges (Protoss) | `--ts-btn-chamfer` and so on: sizes in px, used in a fixed `clip-path` polygon that base.css writes | Phase C, built. Panels and widgets are not cut: a clip would also trim the frame layers |
| Animation: a breathing strip (Zerg), the alert badge pulse (all three) | breathe and alert pulse | Phase D, built. Zerg's badge also scales up 9%; an upload's pulse changes only the glow |
| Page background (facets, glow) | already possible: `--ts-body-bg-image` takes gradients | done |
| `hr` height, dropdown submenu offset | not ornaments | stay bundled-only |

Each phase adds its layers to the gate list in `OrnamentTest` and its mutation
cases to `tests/mutate.sh`, the same way Phase A does.

## What an ornament can't do, and why that is accepted

* A skin can paint a frame slot in any colour, including one that makes text
  hard to read where the layer shows through a transparent area of a panel.
  That is no different from any colour choice a skin already makes; the layer
  adds no ability to hide content.
* The 8px overhang can paint over the gap between panels and the edge of a
  neighbour that is painted earlier. It is 8px of decoration, it can't carry
  text, and it can't take a click.
* The layer is not clipped to rounded corners. The ring is rectangular.
