# Writing a skin

A skin is a small folder you zip and upload on the Theme Selector page
(**Plugins → Theme Selector**, admins only). It changes colours, type and
spacing; it does not add rules or load anything.

```
my-skin/
  skin.json        required
  skin.css         required
  graph.conf       optional: graph colours
  fonts/           optional: .woff2 or .woff files skin.css refers to
  LICENSE.txt      optional: a licence notice, shown to admins
  textures/        optional: repeating .png tiles skin.css declares
```

Pack, check, upload (at most 40 files in the zip, 3 MiB unpacked, 4 MB zipped):

```bash
python scripts/pack-skin.py my-skin -o my-skin.zip
lnms theme-selector:validate my-skin.zip     # the upload page's checks, without installing
```

A working starting point is `examples/minimal/` (20 values, nothing else) for a dark skin, or
`examples/minimal-light/` for a light one.

## skin.json

```json
{
  "id": "slate-teal",
  "name": "Slate Teal",
  "description": "Cool slate surfaces with a teal accent.",
  "author": "You",
  "version": "1.0.0",
  "license": "MIT",
  "mode": "dark",
  "family": "Slate"
}
```

- `id` and `name` are required; `version` (if given) is three numbers like `1.2.0`. `id` is 1-63 characters: lowercase letters,
  digits and hyphens, not starting with a hyphen. It can't be one of the
  bundled skins' ids (`terran`, `protoss`, `zerg`) or `none`, `default`, `base`
  and a few other reserved words. Uploading the same id again replaces the skin.
- Text fields are plain text (they start with a letter or digit, then letters, digits, spaces
  and `. , _ ( ) ' + : / & -`; a `description` may also use `! ? ;`), up to 60 characters
  (200 for `description`).
- `mode` is the mode the skin is written for: `"dark"` (the default) or `"light"`. It must agree with
  the wrapper in `skin.css` (below). The older `"modes": ["dark"]` / `["light"]` spelling is still
  read and means the same.
- `family` (optional, up to 40 characters, plain text) names a group of skins that belong together,
  such as `Clock Tower` for Daylight, Lantern and Sepia skins. The picker lists skins with the same
  family together. A family is only a label: each skin in it is installed, chosen and removed on its
  own.
- No other fields.

### Light and dark

LibreNMS is light or dark depending on each user's settings, and users choose a skin for each mode.
A skin is **written for one mode**, and **any skin can be used in either**: for the mode it was not
written for, the plugin serves the same rules under the other mode's selector (you do nothing for
this). So a light skin should be written for light, a dark one for dark, and both will still look
deliberate if someone puts one in the other slot: it is the same tokens either way.

Graph colours are the exception: they are tuned to a ground, so a skin's `graph.conf` applies only in
the mode the skin is written for (see "graph.conf" below).

## skin.css

Exactly this shape, and nothing else (for a light-mode skin the wrapper is `html:not(.dark)` instead
of `html.dark`, and `skin.json` says `"mode": "light"`):

```css
html.dark {
  --ts-bg: #0f1a1f;
  --ts-accent: #2ec4b6;
  --p-teal: #2ec4b6;            /* your own palette, if you want one */
  --ts-highlight: var(--p-teal);
}
```

- **`--ts-*`** are the values the base stylesheet exposes. Every one has a
  default, so set only what you want to change. The 20 **core roles** (surfaces,
  text, accents, status colours, fonts, radius) are enough for a complete skin;
  `docs/TOKENS.md` lists all of them with their defaults. An unknown name is an
  error, not ignored.
- **`--p-*`** are names of your own. Refer to them with `var(--p-name)`.
- A value is built from: colours (`#hex`, `rgb()`, `rgba()`, `hsl()`,
  `color-mix()`, `oklch()`...), numbers with `px em rem % deg s ms`, keywords,
  `linear-gradient()` and friends, the filters `brightness contrast saturate
  sepia hue-rotate invert grayscale`, `cubic-bezier()`/`steps()`, and
  `var(--ts-*)` or `var(--p-*)` (one name, no fallback). Font family lists may
  hold quoted names.
- **Not allowed** (each is a validation error): any selector other than
  `html.dark`, any property other than custom properties, `url()`, `@import`
  and every other at-rule except `@font-face`, `calc()` and arithmetic,
  backslash escapes, `!important`, comments inside a value, `;`, `{`, `}`, `<`,
  `>`, `@`, `:` inside a value.
- **Some tokens can't be set by an upload**: the 31 *structural* ones that
  move or size things or generate text (`docs/TOKENS.md` lists them, and why).
  For decoration, use gradients and shadows on the tokens that allow them, and
  the frame slots below.
- **Sizes are bounded**: shadows up to 100 px, borders 24 px, radii and spacing
  64 px, everything else 800 px; durations up to 5 s; filter arguments within
  sensible ranges. Out-of-range values are rejected, not clamped.
- Keep it under 96 KB, at most 500 declarations, at most 250 of your own `--p-*` names, and
  each value under 1,200 characters.

### Fonts

Put the files in `fonts/` (`.woff2` or `.woff`, named
`fonts/<letters, digits, _ or ->.woff2`, up to 400 KB each, at most 8, with at most 12
`@font-face` blocks) and declare them:

```css
@font-face {
  font-family: "My Face";
  src: url("fonts/MyFace-Regular.woff2") format("woff2");
  font-weight: 400;
  font-display: swap;
}
html.dark { --ts-font-display: "My Face", system-ui, sans-serif; }
```

`src` must be exactly `url("fonts/<file>") format("woff2")` (or `woff`), naming
a file in the bundle; every font in `fonts/` must be used. Only `font-family`,
`src`, `font-weight`, `font-style`, `font-display` and `unicode-range` are
allowed in `@font-face`. On install the font is embedded in the skin's
stylesheet, so your files are not served as files.

**You are responsible for the font's licence.** Use fonts you may redistribute
(the bundled skins use SIL Open Font License faces), say so in `license`, and
include the licence text as `LICENSE.txt` (below), which most font licences
(the OFL among them) require you to keep with the font.

### Textures

A texture is a small PNG that repeats across a background: cellular tissue, brushed
metal, a fine weave. Put it in `textures/`, declare it in `skin.css` the way a font
is declared, and use it as a value in an image token:

```css
html.dark {
  --tx-creep: url("textures/creep.png");               /* declare: name and file match */
  --ts-body-bg-image: var(--tx-creep), linear-gradient(175deg, #0c070d, #170c17);
  --ts-body-bg-size: 256px 256px, auto;                /* optional: draw the tile smaller or larger */
}
```

* **Format.** PNG only: not interlaced, not animated, 8 bits per channel (greyscale
  may be 1, 2, 4 or 8 bits, and a palette image any depth up to 8), at most
  **256 x 256 px**. Non-square is fine. No JPEG, GIF, WebP or SVG.
* **Size.** At most **64 KB once cleaned** (256 KB as you upload it), 4 textures, 128 KB altogether. A palette
  (indexed) image or greyscale with alpha is usually far smaller than RGBA; a
  256 x 256 tile of soft detail is often 10 to 40 KB.
* **Names.** The file is `textures/<name>.png`, the name is lowercase letters,
  digits and hyphens (up to 41 characters), and the declaration is exactly
  `--tx-<name>: url("textures/<name>.png");` with the same name. Every file must be
  declared and every declaration used.
* **Where it goes.** A texture can be used by any **image token** (the ones that take
  gradients: `--ts-body-bg-image`, `--ts-panel-bg-image`, `--ts-widget-bg-image`,
  `--ts-btn-default-bg-image`, the frame and strip slots...), directly or through a
  `--p-*` palette entry. In a colour or a font it is an error.
* **Size and position of the page background.** `--ts-body-bg-size`,
  `--ts-body-bg-position` and `--ts-body-bg-repeat` take one value per layer in
  `--ts-body-bg-image`, in order (`auto` for a gradient that fills the page). Size and
  position may be up to 512px, so a 256px image can be shown at 2x. Other
  backgrounds draw the image at its own size.
* **Make it seamless.** The check can't tell whether a tile repeats cleanly, so
  export one that does: every edge should continue into the opposite edge. Keep
  contrast low behind text.
* **Export plain.** Tools write colour profiles, gamma, text and EXIF into a PNG.
  Those are dropped, not kept, which can change colours slightly (save as sRGB).
* **Licence.** Say where the image came from. A texture you did not make needs the
  same licence notice as a font (`LICENSE.txt`).

What is served is not your file: the plugin reads the PNG itself (no image library),
checks every chunk, checksum and row, and writes a clean copy, which it embeds in the
generated stylesheet as a `data:` URL. Nothing you upload is ever a file on the
server. `docs/ORNAMENTS.md` has more on what a skin can draw; the bundled Zerg skin's
`textures/creep.png` is a worked example, and `docs/TEXTURES.md` shows how the
bundled tiles (plate, crystal, creep) are generated so that they repeat.

## LICENSE.txt

Optional. Plain text, up to 20 KB: the copyright line and licence text for the
fonts (or anything else) in your bundle. The exact name `LICENSE.txt` is the only
one accepted; `license.txt`, `OFL.txt`, `fonts/OFL.txt` and so on are refused.

The text is stored with the skin and shown to admins under **Licence notice** in
the skin list. It is never served as a file and never reaches the stylesheet.
It must be UTF-8 text: letters, digits, punctuation, symbols, spaces, tabs and
newlines. Control characters, invisible or direction-changing characters
(zero-width spaces, bidi overrides), private-use and unassigned characters are
errors, so a notice can't hide or disguise anything. Angle brackets and
ampersands are fine (they are displayed as text, never interpreted). Replacing
a skin replaces its notice; a bundle with no `LICENSE.txt` has none.

## Ornaments

Skins installed by upload can decorate the edges and corners of panels. Each
panel has a fixed decorative layer, behind its content, that reaches 8px outside
the panel and 24px inside its edge. You paint it with eight gradients:

```css
html.dark {
  /* a red-over-orange bar that juts 8px out of the top-left corner and
     another at the bottom-right */
  --ts-frame-tl: linear-gradient(180deg, transparent 2px, #e50832 2px, #e50832 6px, #f37c2f 6px, #f37c2f 9px, transparent 9px);
  --ts-frame-br: linear-gradient(0deg,   transparent 2px, #e50832 2px, #e50832 6px, #f37c2f 6px, #f37c2f 9px, transparent 9px);
  /* each corner of a panel can have its own radius (up to 64px) */
  --ts-panel-radius-tl: 0;
  --ts-panel-radius-br: 12px;
}
```

The corner slots (`--ts-frame-tl`, `-tr`, `-bl`, `-br`) are 32 x 32px boxes at
the corners of the layer; the edge slots (`--ts-frame-top`, `-right`, `-bottom`,
`-left`) are 12px strips along the edges. A gradient changes along one direction,
and the box gives you the other, so a corner slot is how you make a short bar.
Put several gradients in one slot, separated by commas, for L-shapes and stripes.
You can't move, resize or raise the layer, and it can't hold text or take clicks.
The part of a slot that lies under the panel's heading or body is hidden by them.
`docs/ORNAMENTS.md` has the rules and the reasoning behind each limit.

**Heading marker and strip.** Each panel heading has a 12px-wide marker band on
its left edge and a strip layer across the whole heading, both under the
heading's text:

```css
--ts-heading-marker: linear-gradient(180deg, #e50832 50%, #f37c2f 50%);
--ts-heading-marker-size: 4px 100%;          /* a 4px bar, full height */
--ts-heading-marker-position: left top;
--ts-heading-strip: radial-gradient(circle, #8ea0c2 0, #8ea0c2 1.5px, transparent 1.6px);
--ts-heading-strip-size: 34px 6px;           /* a row of rivets */
--ts-heading-strip-position: 10px 5px;
--ts-heading-strip-repeat: repeat-x;
--ts-heading-strip-opacity: .5;
```

**Navbar strips.** `--ts-navbar-strip-top` paints an 8px band along the top
edge of the navbar; `--ts-navbar-strip-bottom` a 12px band along the bottom edge
(8px of it below the navbar). Each has `-size`, `-repeat` and `-opacity` tokens
(for example `--ts-navbar-strip-top-size: 100% 3px;`). They sit under the
navbar's links.

**Cut corners.** Buttons, labels and badges can have cut corners:

```css
--ts-btn-chamfer: 0px;        /* corners not named below stay square */
--ts-btn-chamfer-tl: 8px;     /* cut the top-left and bottom-right */
--ts-btn-chamfer-br: 8px;
--ts-label-chamfer: 5px;      /* every corner of labels */
--ts-badge-chamfer: 5px;
```

`--ts-btn-chamfer-rise` sets the angle: 1 (the default) is a 45 degree cut,
1.732 a 60 degree one, for example `--ts-btn-chamfer-rise: 1.732;` (labels and
badges have their own `--ts-label-chamfer-rise` and `--ts-badge-chamfer-rise`).
A size is written in `px` (`0px` for a square corner), at most 10px for buttons
and 6px for labels and badges. If you set `--ts-btn-chamfer-tl` alone, set
`--ts-btn-chamfer: 0px` too, or no corner is cut (an unset corner uses the
all-corners token, and with neither set there is no clip at all).

**Motion and glow.** Ornament layers can fade slowly and glow:

```css
--ts-navbar-strip-bottom-breathe: 6s;     /* 2s to 60s; unset = still */
--ts-heading-marker-breathe: 4s;
--ts-breathe-low: .45;                    /* how far it fades, .3 to 1 */
--ts-breathe-high: 1;
--ts-heading-marker-glow: rgba(255, 92, 122, .6);   /* a soft light behind it */
--ts-alert-glow-period: 3s;               /* the navbar's alert badge */
--ts-alert-glow-low: rgba(255, 92, 122, .5);
--ts-alert-glow-high: rgba(255, 92, 122, .95);
```

The layers are `frame`, `heading-marker`, `heading-strip`, `navbar-strip-top` and
`navbar-strip-bottom` (`--ts-frame-breathe`, `--ts-frame-glow`, ...). A period
is between 2s and 60s; a glow takes exactly one literal colour (`#hex`, `rgb()`,
`rgba()`, `hsl()` or `hsla()`), not a `var()` or a list. Motion stops for
visitors whose system asks for reduced motion.

**Cut corners on panels and widgets.**

```css
--ts-panel-chamfer: 0px;
--ts-panel-chamfer-bl: 12px;          /* at most 12px */
--ts-panel-chamfer-rise: 1.732;       /* 60 degrees */
--ts-panel-cut-stroke: #34497a;       /* a line along the cut */
--ts-widget-chamfer: 0px;
--ts-widget-chamfer-bl: 12px;
--ts-widget-chamfer-rise: 1.732;
--ts-widget-cut-stroke: #34497a;
```

Panels and widgets are clipped, so the cut works over any page background, a texture
included, and nothing that hangs out of a panel (a dropdown) is cut off. The line takes
a literal `#hex`, `rgb()` or `hsl()`, not a `var(--p-*)`. On a widget the line is drawn over
the frame bars, so a bar ends under it; a corner with no cut is left exactly as it is, so the
bars that jut out of it are never trimmed. (`--ts-panel-cut-fill` from
earlier versions is still accepted and ignored.) `--ts-panel-radius-*` and `--ts-widget-radius-*` also work on
elements LibreNMS rounds with a Tailwind class (the device page header).

**Hover.** A panel or widget is raised above its neighbours while the pointer is over it (or
while it holds an open menu), so a hover card or dropdown opened inside it is not trapped under
the next panel. There is nothing to set; it is `z-index: 1035`, above the sticky navbar and
below modals.

**Widgets.** Dashboard widgets take the same eight slots as panels under the
names `--ts-widget-frame-tl` ... `--ts-widget-frame-left`, plus
`--ts-widget-radius-tl` and so on. They stay inside the widget's edge (no
overhang). A widget's title bar covers its top edge; paint the bar itself through
`--ts-widget-bar-bg`, which takes layered gradients.

**A drifting page layer.** One fixed layer behind the whole page can scroll a tile of your
texture downward, for rain, snow, a star field:

```css
--tx-rain: url("textures/rain.png");
--ts-rain-image: var(--tx-rain);
--ts-rain-tile: 256px;      /* the tile's size: whole px, 64px to 512px */
--ts-rain-period: 36s;      /* one tile passes in this long: 2s to 60s */
```

Make the tile seamless top to bottom (its last row must continue its first) or a join will
scroll past. The layer sits behind every panel, so it shows where the page does: leave some of
the panel background translucent if you want it to show through. It can't be clicked, it stops
for visitors whose system asks for reduced motion, and a skin that doesn't set a period has no
layer at all.

## graph.conf

Optional. Graph images are drawn by the server, so this is separate from the
CSS. Lines of `key=value`:

```
rrdgraph_def_text_dark=-c BACK#0f1a1f -c SHADEA#EEEEEE00 -c SHADEB#EEEEEE00 -c CANVAS#FFFFFF00 -c GRID#25404d -c MGRID#2c4a58 -c FRAME#5e5e5e -c ARROW#5e5e5e
rrdgraph_def_text_color_dark=c9dde3
graph_colours.greens=["9CF2B4","6FE08E","46C96B","34A552","26823E","1A6030"]
```

Only those shapes are accepted, and any other key is an error (the upload is refused):

* `rrdgraph_def_text_dark` (graphs drawn in dark mode) and `rrdgraph_def_text` (graphs drawn in
  light mode): `-c NAME#RRGGBB` or `-c NAME#RRGGBBAA` pairs (NAME one of `BACK CANVAS SHADEA SHADEB
  GRID MGRID FONT AXIS FRAME ARROW`, each at most once, at most 12 pairs, 400 characters).
* `rrdgraph_def_text_color_dark` and `rrdgraph_def_text_color`: six hex digits.
* `graph_colours.<name>` (lowercase letters and underscores, up to 30): a JSON list of up to 40
  six-digit hex colours.

**Port traffic graphs.** `graph_colours.port_in` and `graph_colours.port_out` (at least three
six-digit colours each, palest first: the 95th-percentile fill, the area fill, the outline)
recolour the in and out series of `port_bits` and 19 other graph types. LibreNMS hard-codes those
colours, so the plugin rewrites them just before the graph is drawn (see `docs/PLUGIN.md`); a skin
that sets neither draws them in LibreNMS's stock green and lavender.

`skins/zerg/graph.conf` is a full example. Each user's own graphs use their skin's palette for the
mode the graph is drawn in. **A skin's palette applies only in the mode the skin is written for**: a
light skin sets the light chrome (`rrdgraph_def_text`, `rrdgraph_def_text_color`) and ramps that read
on a light ground, a dark skin the `_dark` pair, and a dark skin used in the light slot leaves light
graphs as LibreNMS draws them. The series ramps (`graph_colours.*`) are the same keys in both modes.
`examples/minimal-light/graph.conf` is a light example.

## What you'll see when it's wrong

`lnms theme-selector:validate` (and the upload page) list every problem at
once, with the line for CSS errors, for example:

```
- --ts-panel-before-content (line 12): is a structural token ... that only bundled skins may set
- value of --ts-navbar-bg-image (line 20): uses the function url(), which is not allowed
- fonts/a.woff2: declares a different size than the file has (extra data appended?)
```

Installing a skin doesn't change what anyone sees. Pick it under "Your skin" to
see it previewed on a sample page (nothing to supply for that: the preview uses
your stylesheet and graph palette as installed), then apply it to try it, and use `?theme-selector=off` on any page's address if it goes wrong.
See `docs/SECURITY.md` for why the rules are what they are.
