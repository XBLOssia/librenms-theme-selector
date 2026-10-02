"""Generate the two Clock Tower skins: skins/clock-tower-daylight/ (light) and skins/clock-tower-lantern/ (dark).

    python scripts/make-clock-tower.py            # rewrite both skins' skin.css and textures/gears.png
    python scripts/make-clock-tower.py --check    # exit 1 if the files on disk are not what this would write

One template, two palettes, so the family stays one design: the same shapes (a double rule round every
panel, gilt corner brackets with a rivet, leaf-shaped corners, a brass strip and a row of dentils on the
navbar, a block of brass beside each heading) in two moods. Daylight is natural light on parchment and
walnut; Lantern is the same room at night, lit from inside: umber and candle cream, with an amber glow.

Everything the skins set is an ordinary token an uploaded skin may set, so either could be uploaded as a
bundle (without features.json, which only bundled skins have). The one image in each, a faint tile of
cogwheels, is computed here from fixed numbers: no source art, nothing to credit. The type is Playfair
Display for headings and Libre Baskerville for text, bundled in each skin's fonts/ with their OFL notices
(scripts/fetch-fonts.ps1 fetches them), and the system's serifs if they fail to load.

Needs numpy and Pillow to write the textures (development only; the plugin never runs this); --check needs
neither.
"""
import math
import os
import sys
from string import Template


ROOT = os.path.join(os.path.dirname(os.path.abspath(__file__)), '..')

PALETTES = {
    'daylight': dict(
        id='clock-tower-daylight', name='Clock Tower Daylight', wrap='html:not(.dark)', mode='light',
        description='Natural light on parchment and walnut, with brass fittings. The daytime face of the Clock Tower family.',
        mood='natural light: parchment surfaces, walnut text, brass fittings',
        bg='#ece0c8', surface='#f8f1e1', raised='#efe3cb', hover='#e4d3ae', line='#c9ad7c', line_strong='#a37b3a',
        text='#3b2a1c', dim='#58422d', mute='#664d34', bright='#1f130a',
        brass='#9a6a1c', brass_hi='#c99a3c', brass_dim='#c9ad7c',
        link='#7a3412', link_hover='#5a2208', accent='#8a5a14', highlight='#b4801e',
        success='#3d6a28', warning='#8c5900', danger='#a02e1e', info='#2b5f7c', danger_text='#8e2418',
        label_success='#3d6a28', label_danger='#a02e1e', label_warning='#c78a14', label_info='#2b5f7c', label_default='#7a6446',
        on_label='#fff8e8', on_warn='#2a1a05',
        btn_primary='#c99a3c', on_primary='#1f130a',
        glow='rgba(154, 106, 28, .35)', shade='rgba(70, 45, 15, .18)', shade_strong='rgba(70, 45, 15, .30)',
        wash='linear-gradient(180deg, rgba(255, 252, 240, .55) 0, rgba(255, 252, 240, 0) 55%)',
        page='radial-gradient(ellipse at 30% 0, rgba(255, 244, 214, .85) 0, rgba(255, 244, 214, 0) 60%)',
        gear='154, 106, 28', gear_alpha=0.11, head_glow='none',
        breathe_low='.85', breathe_high='1', frame_breathe='', marker_breathe='',
        navbar_bg='#f3e7cf', navbar_text='#3b2a1c',
    ),
    'lantern': dict(
        id='clock-tower-lantern', name='Clock Tower Lantern', wrap='html.dark', mode='dark',
        description='The same room at night, lit from inside: umber and candle cream with an amber glow. The evening face of the Clock Tower family.',
        mood='lit from inside: umber surfaces, candle-cream text, an amber glow',
        bg='#150e07', surface='#221710', raised='#2f200f', hover='#41301a', line='#6b4a1e', line_strong='#a06f26',
        text='#ecd9b0', dim='#cdb27c', mute='#b79b68', bright='#fff3d6',
        brass='#d4a23a', brass_hi='#f4c75e', brass_dim='#6b4a1e',
        link='#f0c36a', link_hover='#ffe19a', accent='#e0a13a', highlight='#ffb347',
        success='#8fbf5a', warning='#f0b429', danger='#e0634a', info='#7db4cf', danger_text='#ff9a80',
        label_success='#4d7a2f', label_danger='#b8412c', label_warning='#8f5d08', label_info='#2f6f8f', label_default='#6b4a1e',
        on_label='#fff3d6', on_warn='#fff3d6',
        btn_primary='#8a5a14', on_primary='#fff3d6',
        glow='rgba(255, 179, 71, .55)', shade='rgba(0, 0, 0, .35)', shade_strong='rgba(0, 0, 0, .55)',
        wash='radial-gradient(ellipse at 50% -12%, rgba(255, 179, 71, .16) 0, rgba(255, 179, 71, 0) 68%)',
        page='radial-gradient(ellipse at 50% 0, rgba(255, 150, 40, .10) 0, rgba(255, 150, 40, 0) 70%)',
        gear='212, 162, 58', gear_alpha=0.075, head_glow='0 0 8px rgba(255, 179, 71, .45)',
        breathe_low='.7', breathe_high='1', frame_breathe='9s', marker_breathe='6s',
        navbar_bg='#1b120a', navbar_text='#ecd9b0',
    ),
}

SKIN_JSON = Template('''{
  "id": "$id",
  "name": "$name",
  "description": "$description",
  "author": "XBLOssia",
  "version": "1.0.0",
  "license": "MIT",
  "mode": "$mode",
  "family": "Clock Tower"
}
''')

FEATURES_JSON = '{\n  "ornaments": true\n}\n'

CSS = Template(r'''/*
 * Clock Tower ($mood).
 *
 * One of two skins that share this design (scripts/make-clock-tower.py writes both from one
 * template): the $mode-mode face of the Clock Tower family. A double rule round every panel, gilt
 * corner brackets with a rivet, leaf-shaped corners, a strip of brass and a row of dentils on the
 * navbar, a block of brass beside each heading, and a faint tile of cogwheels behind the page.
 *
 * Every token here is one an uploaded skin may set (the same file validates as an upload), plus the
 * texture textures/gears.png, computed by the script. The type is Playfair Display for headings and
 * Libre Baskerville for text (both SIL OFL, bundled in fonts/), and the system's serifs if they fail to load.
 * Playfair Display's figures are old-style (a 0 looks like an o, a 1 like an l), which is wrong for
 * hostnames and counters, so the display face is Playfair for everything but the digits 0-9, which come
 * from Libre Baskerville Bold (two @font-face blocks, split by unicode-range).
 */
@font-face {
  font-family: "Clock Tower Display";
  src: url("fonts/PlayfairDisplay-Bold.woff2") format("woff2");
  font-weight: 700;
  font-style: normal;
  font-display: swap;
  unicode-range: U+0000-002F, U+003A-10FFFF;
}
@font-face {
  font-family: "Clock Tower Display";
  src: url("fonts/LibreBaskerville-Bold.woff2") format("woff2");
  font-weight: 700;
  font-style: normal;
  font-display: swap;
  unicode-range: U+0030-0039;
}
@font-face {
  font-family: "Clock Tower Text";
  src: url("fonts/LibreBaskerville-Regular.woff2") format("woff2");
  font-weight: 400;
  font-style: normal;
  font-display: swap;
}
@font-face {
  font-family: "Clock Tower Text";
  src: url("fonts/LibreBaskerville-Bold.woff2") format("woff2");
  font-weight: 700;
  font-style: normal;
  font-display: swap;
}
$wrap {
  /* Palette */
  --p-bg: $bg;
  --p-surface: $surface;
  --p-raised: $raised;
  --p-hover: $hover;
  --p-line: $line;
  --p-line-strong: $line_strong;
  --p-text: $text;
  --p-dim: $dim;
  --p-mute: $mute;
  --p-bright: $bright;
  --p-brass: $brass;
  --p-brass-hi: $brass_hi;
  --p-brass-dim: $brass_dim;
  --p-link: $link;
  --p-link-hover: $link_hover;
  --p-accent: $accent;
  --p-highlight: $highlight;
  --p-danger: $danger;
  --p-glow: $glow;
  --p-shade: $shade;
  --p-shade-strong: $shade_strong;
  --p-display: "Clock Tower Display", "Playfair Display", "Palatino Linotype", "Book Antiqua", Palatino, Georgia, "Times New Roman", serif;
  --p-serif: "Clock Tower Text", "Libre Baskerville", "Palatino Linotype", "Book Antiqua", Palatino, Georgia, "Times New Roman", serif;
  --p-mono: "Courier Prime", "Courier New", Courier, ui-monospace, monospace;
  --p-wash: $wash;
  --p-page: $page;
  --tx-gears: url("textures/gears.png");

  /* Core roles */
  --ts-bg: var(--p-bg);
  --ts-surface: var(--p-surface);
  --ts-surface-raised: var(--p-raised);
  --ts-surface-hover: var(--p-hover);
  --ts-border: var(--p-line);
  --ts-text: var(--p-text);
  --ts-text-dim: var(--p-dim);
  --ts-text-mute: var(--p-mute);
  --ts-text-bright: var(--p-bright);
  --ts-accent: var(--p-accent);
  --ts-link: var(--p-link);
  --ts-highlight: var(--p-highlight);
  --ts-success: $success;
  --ts-warning: $warning;
  --ts-danger: $danger;
  --ts-info: $info;
  --ts-danger-text: $danger_text;
  --ts-font-display: var(--p-display);
  --ts-font-mono: var(--p-mono);
  --ts-font-code: var(--p-mono);
  --ts-radius-sm: 2px;
  --ts-radius-md: 4px;
  --ts-radius-lg: 8px;

  /* The page: a pool of light, and a faint tile of cogwheels over the ground. */
  --ts-body-bg-image: var(--tx-gears), var(--p-page);
  --ts-body-bg-size: 256px 256px, 100% 100%;
  --ts-body-bg-repeat: repeat, no-repeat;

  /* Type: a serif throughout, with the display face for headings and chrome */
  --ts-root-font-family: var(--p-serif);
  --ts-body-font-family: var(--p-serif);
  --ts-input-font-family: var(--p-serif);
  --ts-tab-font-family: var(--p-display);
  --ts-navbar-brand-font-weight: 700;
  --ts-navbar-brand-letter-spacing: .04em;
  --ts-navbar-link-font-size: 15px;
  --ts-navbar-link-letter-spacing: .02em;
  --ts-panel-heading-font-weight: 700;
  --ts-panel-heading-letter-spacing: .05em;
  --ts-widget-title-font-weight: 700;
  --ts-widget-title-letter-spacing: .05em;
  --ts-table-head-font-weight: 700;
  --ts-table-head-letter-spacing: .06em;
  --ts-table-cell-font-size: 14px;
  --ts-tab-font-size: 14px;
  --ts-tab-letter-spacing: .04em;
  --ts-btn-letter-spacing: .03em;
  --ts-btn-font-size: 13px;
  --ts-dropdown-item-font-weight: 400;
  --ts-navbar-brand-text-shadow: $head_glow;
  --ts-panel-heading-text-shadow: $head_glow;

  /* Surfaces: a double rule of brass round every panel, leaf-shaped corners (two long curves, two short),
     and a drop shadow. The recess and bevel are quiet. */
  --ts-shadow: 0 1px 3px var(--p-shade);
  --ts-recess: inset 0 1px 2px var(--p-shade);
  --ts-bevel: inset 0 1px 0 rgba(255, 255, 255, .10);
  --ts-panel-bg-image: var(--p-wash);
  --ts-widget-bg-image: var(--p-wash);
  --ts-panel-border: 1px solid var(--p-line-strong);
  --ts-widget-border: 1px solid var(--p-line-strong);
  --ts-panel-shadow: inset 0 0 0 3px var(--p-surface), inset 0 0 0 4px var(--p-line), 0 2px 8px var(--p-shade);
  --ts-widget-shadow: inset 0 0 0 3px var(--p-surface), inset 0 0 0 4px var(--p-line), 0 2px 8px var(--p-shade);
  --ts-panel-heading-border-bottom: 1px solid var(--p-line);
  --ts-widget-title-border-bottom: 1px solid var(--p-line);
  --ts-panel-radius-tl: 16px;
  --ts-panel-radius-tr: 3px;
  --ts-panel-radius-br: 16px;
  --ts-panel-radius-bl: 3px;
  --ts-widget-radius-tl: 16px;
  --ts-widget-radius-tr: 3px;
  --ts-widget-radius-br: 16px;
  --ts-widget-radius-bl: 3px;
  --ts-navbar-bg-image: var(--p-wash);
  --ts-navbar-border-bottom: 1px solid var(--p-line-strong);
  --ts-navbar-shadow: 0 2px 6px var(--p-shade);
  --ts-navbar-link-hover-shadow: inset 0 -2px 0 0 var(--p-brass);
  --ts-modal-border: 1px solid var(--p-line-strong);
  --ts-modal-border-top: 3px double var(--p-brass);
  --ts-modal-shadow: 0 6px 24px var(--p-shade-strong);
  --ts-navbar-dropdown-border: 1px solid var(--p-line-strong);
  --ts-navbar-dropdown-border-top: 2px solid var(--p-brass);
  --ts-navbar-dropdown-shadow: 0 4px 14px var(--p-shade);
  --ts-alert-badge-border-radius: 8px;
  --ts-well-border-radius: 3px;
  --ts-well-border: 1px solid var(--p-line);
  --ts-alert-border-width: 1px 1px 1px 5px;
  --ts-label-success-bg: $label_success;
  --ts-label-success-fg: $on_label;
  --ts-label-danger-bg: $label_danger;
  --ts-label-danger-fg: $on_label;
  --ts-label-warning-bg: $label_warning;
  --ts-label-warning-fg: $on_warn;
  --ts-label-info-bg: $label_info;
  --ts-label-info-fg: $on_label;
  --ts-label-default-fg: $on_label;
  --ts-badge-alert: $label_danger;
  --ts-btn-success-bg: $label_success;
  --ts-btn-success-fg: $on_label;
  --ts-btn-warning-bg: $label_warning;
  --ts-btn-warning-fg: $on_warn;
  --ts-btn-danger-bg: $label_danger;
  --ts-pre-bg: var(--p-raised);
  --ts-pre-border: 1px solid var(--p-line);
  --ts-code-text: var(--p-text);
  --ts-progress: var(--p-brass);
  --ts-progress-glow-shadow: 0 0 6px var(--p-glow);

  /* Ornaments. Gilt brackets at the corners (two rules and a rivet), a block of brass beside each
     panel heading with a double hairline under it, a thin line of brass along the top of the navbar
     and a row of dentils along its foot. The glow and the slow fade are the lamp settling; every
     period is over 2s and nothing flashes. */
  --p-bracket-tl: radial-gradient(circle at 12px 12px, var(--p-brass-hi) 0, var(--p-brass-hi) 2.5px, transparent 3px), linear-gradient(180deg, var(--p-brass) 0, var(--p-brass) 3px, transparent 3px), linear-gradient(90deg, var(--p-brass) 0, var(--p-brass) 3px, transparent 3px), linear-gradient(180deg, transparent 6px, var(--p-brass-dim) 6px, var(--p-brass-dim) 7px, transparent 7px), linear-gradient(90deg, transparent 6px, var(--p-brass-dim) 6px, var(--p-brass-dim) 7px, transparent 7px);
  --ts-frame-tl: var(--p-bracket-tl);
  --ts-widget-frame-tl: var(--p-bracket-tl);
  --p-bracket-br: radial-gradient(circle at 20px 20px, var(--p-brass-hi) 0, var(--p-brass-hi) 2.5px, transparent 3px), linear-gradient(0deg, var(--p-brass) 0, var(--p-brass) 3px, transparent 3px), linear-gradient(270deg, var(--p-brass) 0, var(--p-brass) 3px, transparent 3px), linear-gradient(0deg, transparent 6px, var(--p-brass-dim) 6px, var(--p-brass-dim) 7px, transparent 7px), linear-gradient(270deg, transparent 6px, var(--p-brass-dim) 6px, var(--p-brass-dim) 7px, transparent 7px);
  --ts-frame-br: var(--p-bracket-br);
  --ts-widget-frame-br: var(--p-bracket-br);
  --ts-frame-glow: $glow;
$frame_breathe_decl
  --ts-heading-marker: linear-gradient(180deg, var(--p-brass-hi) 0, var(--p-brass) 100%);
  --ts-heading-marker-size: 5px 100%;
  --ts-heading-marker-position: left top;
  --ts-heading-marker-glow: $glow;
$marker_breathe_decl
  --ts-heading-strip: linear-gradient(180deg, transparent 0, transparent 100%);
  --ts-heading-strip-opacity: 0;
  --ts-navbar-strip-top: linear-gradient(90deg, var(--p-brass) 0, var(--p-brass-hi) 50%, var(--p-brass) 100%);
  --ts-navbar-strip-top-size: 100% 2px;
  --ts-navbar-strip-bottom: repeating-linear-gradient(90deg, var(--p-brass) 0, var(--p-brass) 5px, transparent 5px, transparent 12px);
  --ts-navbar-strip-bottom-size: 100% 3px;
  --ts-navbar-strip-bottom-opacity: .8;
  --ts-breathe-low: $breathe_low;
  --ts-breathe-high: $breathe_high;
  --ts-alert-glow-period: 3s;
  --ts-alert-glow-low: rgba(184, 65, 44, .35);
  --ts-alert-glow-high: rgba(184, 65, 44, .75);

  /* Tables and controls */
  --ts-table-border: var(--p-line);
  --ts-table-head-bg: var(--p-raised);
  --ts-table-head-border-bottom: 2px solid var(--p-line-strong);
  --ts-table-stripe-odd-bg: var(--p-surface);
  --ts-table-stripe-even-bg: var(--p-raised);
  --ts-table-row-hover-bg: var(--p-hover);
  --ts-table-row-hover-shadow: inset 3px 0 0 0 var(--p-brass);
  --ts-table-bg: var(--p-surface);
  --ts-input-bg: var(--p-surface);
  --ts-input-border: 1px solid var(--p-line-strong);
  --ts-input-border-top-color: var(--p-line-strong);
  --ts-input-shadow: var(--ts-recess);
  --ts-input-focus-shadow: 0 0 0 2px var(--p-brass);
  --ts-addon-border: var(--p-line-strong);
  --ts-btn-default-border: var(--p-line-strong);
  --ts-btn-default-hover-shadow: 0 0 6px var(--p-glow);
  --ts-btn-primary-bg: $btn_primary;
  --ts-btn-primary-border: var(--p-brass);
  --ts-btn-primary-shadow: var(--ts-bevel);
  --ts-lnms-btn-primary-bg: $btn_primary;
  --ts-lnms-btn-primary-border: var(--p-brass);
  --ts-btn-hover-filter: brightness(1.08);
  --ts-pagemenu-active-shadow: inset 0 -2px 0 0 var(--p-brass);
  --ts-tab-active-border: var(--p-line-strong);
  --ts-tab-active-border-top: 3px double var(--p-brass);
  --ts-tab-active-border-bottom-color: var(--p-surface);
}
''')


FONTS_MD = Template(r'''# Fonts — Clock Tower ($name)

**The fonts ship with the skin. There is nothing to install.**

They sit inside the skin folder, so nothing is fetched at run time: no Google Fonts request, nothing for
the end user to do. (The folder alone does not apply a skin: it needs the plugin and `base/base.css`, see
the README.) Both Clock Tower skins carry the same three files.

## The two voices

| Token | Face | Role | Applied to |
|---|---|---|---|
| `--p-display` | Playfair Display 700 | A high-contrast Victorian display serif: the clock face | Navbar, panel and widget headings, table headings, tabs |
| `--p-serif` | Libre Baskerville 400/700 | A sturdy, open book serif that holds up at 13-14px | Everything else: table cells, labels, inputs, text |

Both are named in `skin.css` by private family names (`Clock Tower Display`, `Clock Tower Text`), with the
real names and then the system serifs (Palatino, Book Antiqua, Georgia) behind them, so a failed load
degrades to a serif and never to a sans. Code and `pre` use the system's Courier.

Playfair Display is only used at header sizes. Its hairlines are too fine for a dense table, and its figures
are old-style (a 0 reads as an o, a 1 as an l), which is wrong for hostnames and counters: so the display face
is Playfair for every character except the digits 0-9, which come from Libre Baskerville Bold (two
`@font-face` blocks for `Clock Tower Display`, split by `unicode-range`).

## What ships

```
skins/$id/fonts/
  PlayfairDisplay-Bold.woff2       22.7 KB
  LibreBaskerville-Regular.woff2   19.6 KB
  LibreBaskerville-Bold.woff2      20.0 KB
  OFL-PlayfairDisplay.txt
  OFL-LibreBaskerville.txt
```

Only the `latin` subset is bundled, as Google Fonts serves it (about 15-25 KB a file instead of several
hundred). `scripts/fetch-fonts.ps1` regenerates them reproducibly:

```
powershell -ExecutionPolicy Bypass -File scripts/fetch-fonts.ps1 -Skins clock-tower-daylight,clock-tower-lantern
```

## Licences

Both faces are SIL Open Font License 1.1, which permits redistribution with the notice. The notices
are in `fonts/`. The OFL's reserved font names are "Playfair Display" and "Libre Baskerville"; the
files are served unmodified, and the private family names above are CSS aliases, not renamed fonts.

## Swapping a face

Change the `src` of the `@font-face` blocks in `skin.css` (and the file in `fonts/`), or edit the
template in `scripts/make-clock-tower.py` and regenerate: both skins are written from it.
''')

def lum(h):
    c = [int(h[i:i + 2], 16) / 255 for i in (1, 3, 5)]
    c = [v / 12.92 if v <= 0.03928 else ((v + 0.055) / 1.055) ** 2.4 for v in c]
    return 0.2126 * c[0] + 0.7152 * c[1] + 0.0722 * c[2]


def ratio(a, b):
    x, y = lum(a), lum(b)
    return (max(x, y) + 0.05) / (min(x, y) + 0.05)


def contrast_problems(p):
    """Every text colour on every surface it is set on must be at least 4.5:1 (AA)."""
    bad = []
    grounds = ['bg', 'surface', 'raised', 'hover']
    for fg in ('text', 'dim', 'mute', 'bright', 'link', 'accent', 'danger_text', 'success', 'warning', 'danger', 'info'):
        for bg in grounds:
            if fg in ('success', 'warning', 'danger', 'info', 'accent') and bg in ('bg', 'hover'):
                continue
            r = ratio(p[fg], p[bg])
            if r < 4.5:
                bad.append(f'{fg} on {bg}: {r:.2f}')
    for fg, bg in (('on_label', 'label_success'), ('on_label', 'label_danger'), ('on_label', 'label_info'), ('on_label', 'label_default'),
                   ('on_warn', 'label_warning'), ('bright', 'btn_primary')):
        r = ratio(p[fg], p[bg])
        if r < 4.5:
            bad.append(f'{fg} on {bg}: {r:.2f}')
    return bad


def gears(p):
    """A 256 x 256 tile of two cogwheel outlines, faint, in the skin's brass. Nothing touches the edge, so it tiles."""
    import numpy as np  # imported here so --check needs nothing but the standard library
    from PIL import Image

    N, S = 256, 4
    yy, xx = np.mgrid[0:N * S, 0:N * S].astype(np.float64) / S
    cover = np.zeros((N * S, N * S), dtype=bool)

    def ring(cx, cy, r_out, teeth, depth, spokes, width):
        dx, dy = xx - cx, yy - cy
        r = np.hypot(dx, dy)
        th = np.arctan2(dy, dx)
        # a tooth is a rounded-square wave in the angle
        wave = np.tanh(3.0 * np.sin(teeth * th)) * 0.5 + 0.5
        edge = r_out + depth * wave
        c = np.abs(r - edge) < width
        c |= np.abs(r - r_out * 0.62) < width * 0.9
        c |= r < 2.2
        c |= np.abs(r - r_out * 0.18) < width * 0.9
        for k in range(spokes):
            a = 2 * math.pi * k / spokes
            # distance from the spoke line, only between the hub and the rim
            along = dx * math.cos(a) + dy * math.sin(a)
            across = np.abs(-dx * math.sin(a) + dy * math.cos(a))
            c |= (across < width * 0.8) & (along > r_out * 0.18) & (along < r_out * 0.62)
        return c

    cover |= ring(100, 98, 56, 20, 9, 6, 0.9)
    cover |= ring(205, 196, 28, 11, 6, 5, 0.8)
    # a ring of hour ticks round the small gear's corner of the tile, like a dial
    cx, cy = 52, 206
    r = np.hypot(xx - cx, yy - cy)
    th = np.arctan2(yy - cy, xx - cx)
    tick = (np.abs(r - 30) < 0.9) | ((r > 26) & (r < 34) & (np.abs(np.sin(12 * th / 2)) > 0.985))
    cover |= tick
    cover = cover.reshape(N, S, N, S).mean(axis=(1, 3))
    # An indexed PNG with one colour (the brass) and sixteen levels of transparency: small, exact, and
    # the form the plugin requires of a texture that ships in this package.
    top = int(round(p['gear_alpha'] * 255))
    levels = 16
    idx = np.clip(np.rint(cover * (levels - 1)), 0, levels - 1).astype(np.uint8)
    img = Image.fromarray(idx, 'P')
    rgb = [int(v) for v in p['gear'].split(',')]
    img.putpalette(rgb * levels)
    img.info['transparency'] = bytes(int(round(i / (levels - 1) * top)) for i in range(levels))
    return img


def outputs():
    out = {}
    for key, p in PALETTES.items():
        d = os.path.join('skins', p['id'])
        out[os.path.join(d, 'skin.json')] = SKIN_JSON.substitute(p)
        out[os.path.join(d, 'features.json')] = FEATURES_JSON
        out[os.path.join(d, 'FONTS.md')] = FONTS_MD.substitute(p)
        out[os.path.join(d, 'skin.css')] = CSS.substitute(dict(p, **decls(p)))
        out[os.path.join(d, 'graph.conf')] = graph_conf(p)
    return out


def decls(p):
    """The slow-fade periods, as whole declarations, or nothing when a skin sets none (Daylight does not breathe)."""
    return {
        'frame_breathe_decl': f"  --ts-frame-breathe: {p['frame_breathe']};" if p['frame_breathe'] else '  /* the frame does not breathe in daylight */',
        'marker_breathe_decl': f"  --ts-heading-marker-breathe: {p['marker_breathe']};" if p['marker_breathe'] else '  /* nor the heading marker */',
    }


def graph_conf(p):
    light = p['mode'] == 'light'
    if light:
        chrome = f"rrdgraph_def_text=-c BACK{p['surface'].upper()} -c SHADEA#EEEEEE00 -c SHADEB#EEEEEE00 -c CANVAS#FFFFFF00 -c GRID{p['line'].upper()} -c MGRID{p['brass_dim'].upper()} -c FRAME{p['line_strong'].upper()} -c ARROW{p['line_strong'].upper()}"
        font = f"rrdgraph_def_text_color={p['text'][1:].upper()}"
        ins = ['CFE0B4', '7BA24A', '3D6A28']
        outs = ['E8C98A', 'C99A3C', '8A5A14']
        ramps = {
            'greens': ['D5E4BC', '9CBF6A', '6F9A3C', '4F7A2A', '3D6A28', '2A4A1A'],
            'blues': ['CFE0EA', '8FB8CE', '5C93B2', '3F7696', '2B5F7C', '1D4358'],
            'purples': ['E4D3C0', 'C9A98A', 'A8805E', '8A6240', '6E4A2C', '523620'],
            'oranges': ['F2D9A8', 'E2B25C', 'C98A2A', 'A56A14', '8A5A14', '66410C'],
            'pinks': ['EBCFC4', 'D6A28E', 'BC7B63', '9E5A44', '8A432E', '672F1F'],
            'default': ['3D6A28', '2B5F7C', '9A6200', 'A02E1E', '664D34', '8A5A14'],
        }
    else:
        chrome = f"rrdgraph_def_text_dark=-c BACK{p['surface'].upper()} -c SHADEA#EEEEEE00 -c SHADEB#EEEEEE00 -c CANVAS#FFFFFF00 -c GRID{p['hover'].upper()} -c MGRID{p['line'].upper()} -c FRAME{p['line'].upper()} -c ARROW{p['brass'].upper()}"
        font = f"rrdgraph_def_text_color_dark={p['text'][1:].upper()}"
        ins = ['D6E8B4', '9BCB62', '7DB04A']
        outs = ['FBE3A0', 'F4C75E', 'D4A23A']
        ramps = {
            'greens': ['D6E8B4', 'B4D88A', '8FBF5A', '6FA03E', '55802E', '3E6022'],
            'blues': ['CFE6F2', '9CCDE4', '7DB4CF', '5C97B5', '437A98', '2F5F7A'],
            'purples': ['F1DDC0', 'E0BC8A', 'CC9A5E', 'B27C42', '906030', '6E4822'],
            'oranges': ['FBE3A0', 'F4C75E', 'F0A93A', 'D68A1E', 'B36E12', '8A520C'],
            'pinks': ['F6D2C8', 'EDA897', 'E0836C', 'C5624A', 'A44A36', '7E3626'],
            'default': ['8FBF5A', '7DB4CF', 'F0B429', 'E0634A', 'CDB27C', 'D4A23A'],
        }
    lines = [
        f"# RRDtool graph colours for the {p['name']} skin.",
        '#',
        '# Graph interiors are drawn on the server, so CSS cannot reach them; see skins/zerg/graph.conf for the long',
        '# explanation of the two kinds of key. A skin\'s palette applies in the mode the skin is written for, so this',
        f"# one sets the {'light' if light else 'dark'} chrome. Inbound is green and outbound is brass, which differ in hue and in",
        '# lightness, so they stay apart for anyone who cannot tell colours apart.',
        '',
        chrome.replace('#', '#').replace(' -c ', ' -c ').replace('BACK#', 'BACK#'),
        font,
    ]
    for name, ramp in ramps.items():
        lines.append(f'graph_colours.{name}=[' + ','.join(f'"{c}"' for c in ramp) + ']')
    lines += ['', '# Port traffic series: palest first (the 95th-percentile fill, the area fill, the outline).',
              'graph_colours.port_in=[' + ','.join(f'"{c}"' for c in ins) + ']',
              'graph_colours.port_out=[' + ','.join(f'"{c}"' for c in outs) + ']', '']
    return '\n'.join(lines)


def main():
    check = '--check' in sys.argv
    failed = False
    for key, p in PALETTES.items():
        bad = contrast_problems(p)
        if bad:
            print(f'{p["id"]}: contrast below 4.5:1: ' + '; '.join(bad))
            failed = True
    files = outputs()
    os.chdir(ROOT)
    for path, text in files.items():
        if check:
            on_disk = open(path, encoding='utf-8', newline='').read() if os.path.exists(path) else None
            if on_disk != text:
                print(f'out of date: {path}')
                failed = True
        else:
            os.makedirs(os.path.dirname(path), exist_ok=True)
            open(path, 'w', encoding='utf-8', newline='').write(text)
    if not check:
        for key, p in PALETTES.items():
            tex = os.path.join('skins', p['id'], 'textures', 'gears.png')
            os.makedirs(os.path.dirname(tex), exist_ok=True)
            gears(p).save(tex, 'PNG', optimize=True, transparency=gears(p).info['transparency'])
            print(f'wrote {tex}: {os.path.getsize(tex)} bytes')
    sys.exit(1 if failed else 0)


if __name__ == '__main__':
    main()
