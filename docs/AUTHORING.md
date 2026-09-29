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
```

Pack, check, upload:

```bash
python scripts/pack-skin.py my-skin -o my-skin.zip
lnms theme-selector:validate my-skin.zip     # the upload page's checks, without installing
```

A working starting point is `examples/minimal/` (20 values, nothing else).

## skin.json

```json
{
  "id": "slate-teal",
  "name": "Slate Teal",
  "description": "Cool slate surfaces with a teal accent.",
  "author": "You",
  "version": "1.0.0",
  "license": "MIT",
  "modes": ["dark"]
}
```

- `id` and `name` are required. `id` is 1-63 characters: lowercase letters,
  digits and hyphens, not starting with a hyphen. It can't be one of the
  bundled skins' ids (`terran`, `protoss`, `zerg`) or `none`, `default`, `base`
  and a few other reserved words. Uploading the same id again replaces the skin.
- Text fields are plain text (letters, digits, spaces and `. , ( ) ' + : / & -`),
  up to 60 characters (200 for `description`).
- `modes` must be `["dark"]`. Skins apply in dark mode; light-mode skins aren't
  supported yet.
- No other fields.

## skin.css

Exactly this shape, and nothing else:

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
- **Some tokens can't be set by an upload**: the 36 *structural* ones that
  move or size things or generate text (`docs/TOKENS.md` lists them, and why).
  If you need decoration, use gradients and shadows on the tokens that allow
  them.
- **Sizes are bounded**: shadows up to 100 px, borders 24 px, radii and spacing
  64 px, everything else 800 px; durations up to 5 s; filter arguments within
  sensible ranges. Out-of-range values are rejected, not clamped.
- Keep it under 96 KB.

### Fonts

Put the files in `fonts/` (`.woff2` or `.woff`, up to 400 KB each, at most 8)
and declare them:

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
(the bundled skins use SIL Open Font License faces) and say so in
`license`.

## graph.conf

Optional. Graph images are drawn by the server, so this is separate from the
CSS. Lines of `key=value`:

```
rrdgraph_def_text_dark=-c BACK#0f1a1f -c SHADEA#EEEEEE00 -c SHADEB#EEEEEE00 -c CANVAS#FFFFFF00 -c GRID#25404d -c MGRID#2c4a58 -c FRAME#5e5e5e -c ARROW#5e5e5e
rrdgraph_def_text_color_dark=c9dde3
graph_colours.greens=["9CF2B4","6FE08E","46C96B","34A552","26823E","1A6030"]
```

Only those three shapes are accepted: `-c NAME#RRGGBB` pairs (NAME one of `BACK
CANVAS SHADEA SHADEB GRID MGRID FONT AXIS FRAME ARROW`), six hex digits, and
`graph_colours.<name>` as a JSON list of up to 40 six-digit hex colours.
`skins/zerg/graph.conf` is a full example. Each user's own graphs use their
skin's palette.

## What you'll see when it's wrong

`lnms theme-selector:validate` (and the upload page) list every problem at
once, with the line for CSS errors, for example:

```
- --ts-panel-before-content (line 12): is a structural token ... that only bundled skins may set
- value of --ts-navbar-bg-image (line 20): uses the function url(), which is not allowed
- fonts/a.woff2: declares a different size than the file has (extra data appended?)
```

Installing a skin doesn't change what anyone sees. Pick it under "Your skin" to
try it, and use `?theme-selector=off` on any page's address if it goes wrong.
See `docs/SECURITY.md` for why the rules are what they are.
