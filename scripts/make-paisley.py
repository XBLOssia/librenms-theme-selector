"""Generate the Paisley skins: skins/paisley-dawn/ (light) and skins/paisley-velvet/ (dark).

    python scripts/make-paisley.py             # rewrite both skins' files and their paisley tile
    python scripts/make-paisley.py --check     # exit 1 if the files on disk are not what this would write
    python scripts/make-paisley.py --preview D # also write a 3 x 3 repetition of each tile into directory D

One template, two palettes, so the pair stays one design. The look is purple-led mid-80s pop: royal and
lilac purples, white, with peach and gold as the small accents. Panels are soft-cornered (two long curves,
two short, the curve of a paisley's tail) with a double rule; each corner carries a gold flourish, each panel
heading has a lace scallop under it and a ribbon of violet, peach and gold beside it, the navbar has a
ribbon along its top and a string of pearls along its foot, and behind the page is a faint paisley print.
Dawn is the light face (lilac-white and peach, ink-purple type); Velvet is the dark one (deep aubergine,
white type, gold trim).

Nothing here is named after, or copied from, anyone's artwork. The print is computed from fixed numbers:
a paisley (boteh) is a run of discs along an arc whose radius shrinks to a point, drawn as an outline, an
inner outline, a row of beads down its spine and a ring round its head. There is no source art to credit,
and running the script again writes the same file. Every shape wraps round the tile's edges, so it has no
seam.

Everything the skins set is an ordinary token an uploaded skin may set, so either could be uploaded as a
bundle (without features.json, which only bundled skins have). The type is Playfair Display for headings
and Libre Baskerville for text (SIL OFL, bundled in each skin's fonts/), and the system's serifs if they
fail to load.

Needs numpy and Pillow to write the textures (development only; the plugin never runs this); --check needs
neither.
"""
import math
import os
import sys
from string import Template


ROOT = os.path.join(os.path.dirname(os.path.abspath(__file__)), '..')

PALETTES = {
    'dawn': dict(
        id='paisley-dawn', name='Paisley Dawn', wrap='html:not(.dark)', mode='light',
        description='Lilac and white with peach and gold: paisley on pale violet, ink-purple type. The daytime face of the Paisley family.',
        mood='lilac and white, with peach and gold trim',
        bg='#ede3f6', surface='#fcf9fe', raised='#f3eafa', hover='#e5d6f4', line='#cdb8e6', line_strong='#8a5cc6',
        text='#2b1646', dim='#4a3170', mute='#5b4783', bright='#140824',
        violet='#7a3fc4', violet_hi='#a875ea', peach='#f2b98f', gold='#b8862a', gold_hi='#e0b04a', gold_dim='#d9c28a',
        link='#6a2bb8', link_hover='#4b1a8a', accent='#6a2fb0', highlight='#d9803f',
        success='#2b7444', warning='#8a5a00', danger='#b02a47', info='#3a56b0', danger_text='#9c2038',
        label_success='#2b7444', label_danger='#b02a47', label_warning='#e2a032', label_info='#3a56b0',
        on_label='#fffaf5', on_warn='#2a1405',
        btn_primary='#f2b98f', btn_primary_border='#8a5cc6',
        glow='rgba(138, 92, 198, .35)', shade='rgba(60, 25, 100, .16)', shade_strong='rgba(60, 25, 100, .30)',
        wash='linear-gradient(180deg, rgba(255, 255, 255, .65) 0, rgba(255, 255, 255, 0) 55%)',
        lit='linear-gradient(180deg, rgba(242, 185, 143, .30) 0, rgba(242, 185, 143, .06) 100%)',
        page='radial-gradient(ellipse at 30% 0, rgba(255, 238, 224, .85) 0, rgba(255, 238, 224, 0) 60%), linear-gradient(180deg, #f1e8f9 0, #e6d9f2 100%)',
        navbar='linear-gradient(180deg, rgba(255, 255, 255, .70) 0, rgba(255, 255, 255, 0) 100%)',
        ink='122, 63, 196', ink_a=0.22, fill='242, 185, 143', fill_a=0.26, dot='184, 134, 42', dot_a=0.32,
        text_glow='none', breathe_low='.85', breathe_high='1', frame_breathe='', marker_breathe='',
        alert_low='rgba(176, 42, 71, .35)', alert_high='rgba(176, 42, 71, .75)',
        map_filter='none',
        ins=['D9C8F0', '9A6FD0', '5E2EA6'], outs=['F8DDBF', 'EDB07A', 'B9651A'],
        grid='line', ramps={
            'greens': ['D3E6C8', '9CC58A', '6AA35A', '4B843F', '37682E', '254A20'],
            'blues': ['D3DDF5', '9FB4E6', '6F8BD3', '4C69B8', '36509A', '253970'],
            'purples': ['E6D8F6', 'C9AEEB', 'A47ED8', '8456BE', '653C9C', '482A74'],
            'oranges': ['FBE3CC', 'F4C096', 'EA9C62', 'D27B3C', 'AC5E24', '7E4318'],
            'pinks': ['F6D3DC', 'EBA4B8', 'DB7592', 'BF4F6F', '99365A', '6F2542'],
            'default': ['5E2EA6', 'B9651A', '2F7A4A', '3A56B0', 'B02A47', '8A6A1E'],
        },
    ),
    'velvet': dict(
        id='paisley-velvet', name='Paisley Velvet', wrap='html.dark', mode='dark',
        description='Deep purple velvet with white type, peach and gold trim, and paisley in the dark. The evening face of the Paisley family.',
        mood='deep purple velvet, white type, peach and gold trim',
        bg='#0b0614', surface='#1a0e2e', raised='#261548', hover='#36206a', line='#4b2f82', line_strong='#8a5ad6',
        text='#f1e9fa', dim='#cdbbe8', mute='#ab97cf', bright='#ffffff',
        violet='#8a5ad6', violet_hi='#b184ff', peach='#ffc9a8', gold='#e2b24a', gold_hi='#ffd97a', gold_dim='#6b5320',
        link='#ffc9a8', link_hover='#ffe3d2', accent='#c9a0ff', highlight='#ffb98a',
        success='#7ee0a8', warning='#ffc65a', danger='#ff7a8e', info='#9bb8ff', danger_text='#ff9cab',
        label_success='#27774c', label_danger='#b8304a', label_warning='#d99a1c', label_info='#4a63c8',
        on_label='#fbf7ff', on_warn='#1a0e2e',
        btn_primary='#6a2fc0', btn_primary_border='#e2b24a',
        glow='rgba(177, 132, 255, .50)', shade='rgba(5, 0, 14, .50)', shade_strong='rgba(5, 0, 14, .70)',
        wash='linear-gradient(180deg, rgba(190, 140, 255, .09) 0, rgba(190, 140, 255, 0) 60%)',
        lit='linear-gradient(180deg, rgba(150, 95, 235, .34) 0, rgba(150, 95, 235, .08) 100%)',
        page='radial-gradient(ellipse at 50% -10%, rgba(140, 80, 230, .38) 0, rgba(140, 80, 230, 0) 62%), linear-gradient(180deg, #170a2e 0, #0b0614 100%)',
        navbar='linear-gradient(180deg, rgba(150, 95, 235, .32) 0, rgba(150, 95, 235, .06) 100%)',
        ink='177, 132, 255', ink_a=0.30, fill='122, 46, 170', fill_a=0.34, dot='226, 178, 74', dot_a=0.38,
        text_glow='0 0 10px rgba(177, 132, 255, .55)', breathe_low='.75', breathe_high='1', frame_breathe='9s', marker_breathe='6s',
        alert_low='rgba(184, 48, 74, .35)', alert_high='rgba(184, 48, 74, .80)',
        map_filter='invert(1) hue-rotate(240deg) brightness(.72) contrast(1.05) saturate(.6)',
        ins=['E0D0FF', 'B590FF', '9466F0'], outs=['FFEBC9', 'FFCB8A', 'F0A046'],
        grid='hover', ramps={
            'greens': ['D3F0DD', 'A6E0BC', '74D49A', '4DB57A', '36915F', '246A44'],
            'blues': ['DCE6FF', 'B5C9FF', '8FAAF5', '6B88DC', '4F6AB8', '374C8A'],
            'purples': ['EADDFF', 'D0B8FF', 'B590FF', '9466F0', '7440CC', '552C9A'],
            'oranges': ['FFEBC9', 'FFD59E', 'FFB870', 'F0964A', 'CC7430', '9A5420'],
            'pinks': ['FFD9E0', 'FFB0C0', 'FF8499', 'E65C78', 'BC4260', '8C2F48'],
            'default': ['B590FF', 'FFCB8A', '7EE0A8', '9BB8FF', 'FF7A8E', 'E2B24A'],
        },
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
  "family": "Paisley"
}
''')

FEATURES_JSON = '{\n  "ornaments": true,\n  "effects": [\n    "eye-of-providence"\n  ]\n}\n'

CSS = Template(r'''/*
 * $name ($mood).
 *
 * One of two skins that share this design (scripts/make-paisley.py writes both from one template): the
 * $mode-mode face of the Paisley family. Purple-led, with white, peach and gold as the small accents. Panels
 * are soft-cornered (two long curves, two short, like the tail of a paisley) with a double rule and a gold
 * flourish at each corner; each panel heading has a lace scallop under it and a ribbon of violet, peach and
 * gold beside it; the navbar has a ribbon along its top and a string of pearls along its foot; and behind
 * the page is a faint paisley print.
 *
 * Every token here is one an uploaded skin may set (the same file validates as an upload), plus the
 * texture textures/paisley.png, computed by the script from fixed numbers. The type is Playfair Display
 * for headings and Libre Baskerville for text (both SIL OFL, bundled in fonts/), and the system's serifs
 * if they fail to load. Playfair Display's figures are old-style (a 0 looks like an o, a 1 like an l),
 * which is wrong for hostnames and counters, so the display face is Playfair for everything but the
 * digits 0-9, which come from Libre Baskerville Bold (two @font-face blocks, split by unicode-range).
 */
@font-face {
  font-family: "Paisley Display";
  src: url("fonts/PlayfairDisplay-Bold.woff2") format("woff2");
  font-weight: 700;
  font-style: normal;
  font-display: swap;
  unicode-range: U+0000-002F, U+003A-10FFFF;
}
@font-face {
  font-family: "Paisley Display";
  src: url("fonts/LibreBaskerville-Bold.woff2") format("woff2");
  font-weight: 700;
  font-style: normal;
  font-display: swap;
  unicode-range: U+0030-0039;
}
@font-face {
  font-family: "Paisley Text";
  src: url("fonts/LibreBaskerville-Regular.woff2") format("woff2");
  font-weight: 400;
  font-style: normal;
  font-display: swap;
}
@font-face {
  font-family: "Paisley Text";
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
  --p-violet: $violet;
  --p-violet-hi: $violet_hi;
  --p-peach: $peach;
  --p-gold: $gold;
  --p-gold-hi: $gold_hi;
  --p-gold-dim: $gold_dim;
  --p-link: $link;
  --p-link-hover: $link_hover;
  --p-accent: $accent;
  --p-highlight: $highlight;
  --p-danger: $danger;
  --p-glow: $glow;
  --p-shade: $shade;
  --p-shade-strong: $shade_strong;
  --p-display: "Paisley Display", "Playfair Display", "Palatino Linotype", "Book Antiqua", Palatino, Georgia, "Times New Roman", serif;
  --p-serif: "Paisley Text", "Libre Baskerville", "Palatino Linotype", "Book Antiqua", Palatino, Georgia, "Times New Roman", serif;
  --p-mono: "Courier Prime", "Courier New", Courier, ui-monospace, monospace;
  --p-wash: $wash;
  --p-lit: $lit;
  --p-page: $page;
  --p-ribbon: linear-gradient(180deg, var(--p-gold-hi) 0, var(--p-peach) 50%, var(--p-violet-hi) 100%);
  --tx-paisley: url("textures/paisley.png");

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
  --ts-radius-sm: 3px;
  --ts-radius-md: 5px;
  --ts-radius-lg: 10px;

  /* The page: a pool of light and a faint paisley print over the ground. */
  --ts-map-tile-filter: $map_filter;
  --ts-body-bg-image: var(--tx-paisley), var(--p-page);
  --ts-body-bg-size: 256px 256px, 100% 100%;
  --ts-body-bg-repeat: repeat, no-repeat;

  /* Type: a serif throughout, with the display face for headings and chrome */
  --ts-root-font-family: var(--p-serif);
  --ts-body-font-family: var(--p-serif);
  --ts-input-font-family: var(--p-serif);
  --ts-tab-font-family: var(--p-display);
  --ts-navbar-brand-font-weight: 700;
  --ts-navbar-brand-letter-spacing: .05em;
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
  --ts-navbar-brand-text-shadow: $text_glow;
  --ts-panel-heading-text-shadow: $text_glow;

  /* Surfaces: a double rule of violet round every panel, and corners with two long curves and two short
     (the tail of a paisley). The recess and bevel are quiet. */
  --ts-shadow: 0 1px 3px var(--p-shade);
  --ts-recess: inset 0 1px 2px var(--p-shade);
  --ts-bevel: inset 0 1px 0 rgba(255, 255, 255, .12);
  --ts-panel-bg-image: var(--p-wash);
  --ts-widget-bg-image: var(--p-wash);
  --ts-panel-heading-bg-image: var(--p-lit);
  --ts-widget-title-bg-image: var(--p-lit);
  --ts-panel-border: 1px solid var(--p-line-strong);
  --ts-widget-border: 1px solid var(--p-line-strong);
  --ts-panel-shadow: inset 0 0 0 3px var(--p-surface), inset 0 0 0 4px var(--p-line), 0 2px 10px var(--p-shade);
  --ts-widget-shadow: inset 0 0 0 3px var(--p-surface), inset 0 0 0 4px var(--p-line), 0 2px 10px var(--p-shade);
  --ts-panel-heading-border-bottom: 1px solid var(--p-line);
  --ts-widget-title-border-bottom: 1px solid var(--p-line);
  --ts-panel-radius-tl: 4px;
  --ts-panel-radius-tr: 18px;
  --ts-panel-radius-br: 4px;
  --ts-panel-radius-bl: 18px;
  --ts-widget-radius-tl: 4px;
  --ts-widget-radius-tr: 18px;
  --ts-widget-radius-br: 4px;
  --ts-widget-radius-bl: 18px;
  --ts-navbar-bg-image: $navbar;
  --ts-navbar-border-bottom: 1px solid var(--p-line-strong);
  --ts-navbar-shadow: 0 2px 8px var(--p-shade);
  --ts-navbar-link-hover-shadow: inset 0 -2px 0 0 var(--p-peach);
  --ts-modal-border: 1px solid var(--p-line-strong);
  --ts-modal-border-top: 3px double var(--p-gold);
  --ts-modal-shadow: 0 6px 24px var(--p-shade-strong);
  --ts-navbar-dropdown-border: 1px solid var(--p-line-strong);
  --ts-navbar-dropdown-border-top: 2px solid var(--p-gold);
  --ts-navbar-dropdown-shadow: 0 4px 14px var(--p-shade);
  --ts-alert-badge-border-radius: 9px;
  --ts-well-border-radius: 5px;
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
  --ts-progress: var(--p-violet-hi);
  --ts-progress-glow-shadow: 0 0 8px var(--p-glow);

  /* Ornaments. At each corner of a panel (a gold arc and a peach one outside each long curve, a gold bead in
     a peach ring at each short corner), a ribbon of gold, peach and violet beside every panel heading with a lace scallop
     under it, a ribbon along the top of the navbar and a string of pearls along its foot. The glow
     and the slow fade, where there are any, are the light settling; every period is over 2s and
     nothing flashes. */
  --p-corner-tl: $corner_tl;
  --p-corner-tr: $corner_tr;
  --p-corner-bl: $corner_bl;
  --p-corner-br: $corner_br;
  --ts-frame-tl: var(--p-corner-tl);
  --ts-frame-tr: var(--p-corner-tr);
  --ts-frame-bl: var(--p-corner-bl);
  --ts-frame-br: var(--p-corner-br);
  --ts-widget-frame-tl: var(--p-corner-tl);
  --ts-widget-frame-tr: var(--p-corner-tr);
  --ts-widget-frame-bl: var(--p-corner-bl);
  --ts-widget-frame-br: var(--p-corner-br);
  --ts-frame-glow: $glow;
$frame_breathe_decl
  --ts-heading-marker: var(--p-ribbon);
  --ts-heading-marker-size: 5px 100%;
  --ts-heading-marker-position: left top;
  --ts-heading-marker-glow: $glow;
$marker_breathe_decl
  --ts-heading-strip: $lace;
  --ts-heading-strip-size: 14px 9px;
  --ts-heading-strip-position: left bottom;
  --ts-heading-strip-repeat: repeat-x;
  --ts-heading-strip-opacity: .6;
  --ts-navbar-strip-top: linear-gradient(90deg, var(--p-violet) 0, var(--p-violet-hi) 22%, var(--p-peach) 50%, var(--p-gold-hi) 62%, var(--p-peach) 76%, var(--p-violet-hi) 100%);
  --ts-navbar-strip-top-size: 100% 2px;
  --ts-navbar-strip-bottom: $pearls;
  --ts-navbar-strip-bottom-size: 14px 12px;
  --ts-navbar-strip-bottom-repeat: repeat-x;
  --ts-navbar-strip-bottom-opacity: .95;
  --ts-breathe-low: $breathe_low;
  --ts-breathe-high: $breathe_high;
  --ts-alert-glow-period: 3s;
  --ts-alert-glow-low: $alert_low;
  --ts-alert-glow-high: $alert_high;

  /* Tables and controls */
  --ts-table-border: var(--p-line);
  --ts-table-head-bg: var(--p-raised);
  --ts-table-head-border-bottom: 2px solid var(--p-line-strong);
  --ts-table-stripe-odd-bg: var(--p-surface);
  --ts-table-stripe-even-bg: var(--p-raised);
  --ts-table-row-hover-bg: var(--p-hover);
  --ts-table-row-hover-shadow: inset 3px 0 0 0 var(--p-peach);
  --ts-table-bg: var(--p-surface);
  --ts-input-bg: var(--p-surface);
  --ts-input-border: 1px solid var(--p-line-strong);
  --ts-input-border-top-color: var(--p-line-strong);
  --ts-input-shadow: var(--ts-recess);
  --ts-input-focus-shadow: 0 0 0 2px var(--p-violet-hi);
  --ts-addon-border: var(--p-line-strong);
  --ts-btn-default-border: var(--p-line-strong);
  --ts-btn-default-hover-shadow: 0 0 8px var(--p-glow);
  --ts-btn-primary-bg: $btn_primary;
  --ts-btn-primary-border: $btn_primary_border;
  --ts-btn-primary-shadow: var(--ts-bevel);
  --ts-lnms-btn-primary-bg: $btn_primary;
  --ts-lnms-btn-primary-border: $btn_primary_border;
  --ts-btn-hover-filter: brightness(1.1);
  --ts-pagemenu-active-shadow: inset 0 -2px 0 0 var(--p-peach);
  --ts-tab-active-border: var(--p-line-strong);
  --ts-tab-active-border-top: 3px double var(--p-gold);
  --ts-tab-active-border-bottom-color: var(--p-surface);
}
''')

FONTS_MD = Template(r'''# Fonts — Paisley ($name)

**The fonts ship with the skin. There is nothing to install.**

They sit inside the skin folder, so nothing is fetched at run time: no Google Fonts request, nothing for
the end user to do. (The folder alone does not apply a skin: it needs the plugin and `base/base.css`, see
the README.) Both Paisley skins carry the same three files, the ones the Clock Tower skins use.

## The two voices

| Token | Face | Role | Applied to |
|---|---|---|---|
| `--p-display` | Playfair Display 700 | A high-contrast display serif, with the swagger of a fashion masthead | Navbar, panel and widget headings, table headings, tabs |
| `--p-serif` | Libre Baskerville 400/700 | A sturdy, open book serif that holds up at 13-14px | Everything else: table cells, labels, inputs, text |

Both are named in `skin.css` by private family names (`Paisley Display`, `Paisley Text`), with the real
names and then the system serifs (Palatino, Book Antiqua, Georgia) behind them, so a failed load degrades
to a serif and never to a sans. Code and `pre` use the system's Courier.

Playfair Display is only used at header sizes. Its hairlines are too fine for a dense table, and its
figures are old-style (a 0 reads as an o, a 1 as an l), which is wrong for hostnames and counters: so the
display face is Playfair for every character except the digits 0-9, which come from Libre Baskerville Bold
(two `@font-face` blocks for `Paisley Display`, split by `unicode-range`).

## What ships

```
skins/$id/fonts/
  PlayfairDisplay-Bold.woff2       22.7 KB
  LibreBaskerville-Regular.woff2   19.6 KB
  LibreBaskerville-Bold.woff2      20.0 KB
  OFL-PlayfairDisplay.txt
  OFL-LibreBaskerville.txt
```

Only the `latin` subset is bundled, as Google Fonts serves it. `scripts/fetch-fonts.ps1` regenerates them
reproducibly:

```
powershell -ExecutionPolicy Bypass -File scripts/fetch-fonts.ps1 -Skins paisley-dawn,paisley-velvet
```

## Licences

Both faces are SIL Open Font License 1.1, which permits redistribution with the notice. The notices are in
`fonts/`. The OFL's reserved font names are "Playfair Display" and "Libre Baskerville"; the files are
served unmodified, and the private family names above are CSS aliases, not renamed fonts.

## Swapping a face

Change the `src` of the `@font-face` blocks in `skin.css` (and the file in `fonts/`), or edit the template
in `scripts/make-paisley.py` and regenerate: both skins are written from it.
''')

TEXTURES_MD = Template(r'''# Textures — Paisley

`textures/paisley.png` is a faint print of paisley (boteh) motifs in three sizes, tiled over the page
background behind a pool of light. It is a seamless 256 x 256 indexed PNG in three inks ($ink_name, $fill_name and
$dot_name) with seven levels of transparency each, and nothing else.

It is **computed, not drawn, photographed or traced**. `scripts/make-paisley.py` writes it (and both Paisley
skins' `skin.css`, `skin.json` and `graph.conf`, from one template) from fixed numbers: a paisley is a run of
discs along an arc, their radius shrinking to a point; it is drawn as an outline, an inner outline, a row of
beads down its spine and a ring round its head, and a few small florets and dots fill the gaps. There is no
image source to credit or license, and running the script again gives the same file. Every shape wraps
round the tile's edges, so it has no seam.

```
python scripts/make-paisley.py                      # rewrite both skins
python scripts/make-paisley.py --check              # fail if the files differ from what the script writes
python scripts/make-paisley.py --preview some/dir   # and a 3 x 3 repetition of each tile there
```

(Needs numpy and Pillow to write the textures; `--check` needs only Python. The unit run calls it. Put the
preview somewhere else: the plugin refuses a second PNG in a skin's `textures/`.) `skin.css` declares the
texture like any skin:

```css
--tx-paisley: url("textures/paisley.png");
--ts-body-bg-image: var(--tx-paisley), var(--p-page);
```

When the plugin publishes the skin it reads the PNG, checks it again, and embeds it in the published
`skin.css` as a `data:` URL, so no image file is served.
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
            if fg in ('success', 'warning', 'danger', 'info') and bg in ('bg', 'hover'):
                continue
            r = ratio(p[fg], p[bg])
            if r < 4.5:
                bad.append(f'{fg} on {bg}: {r:.2f}')
    for fg, bg in (('on_label', 'label_success'), ('on_label', 'label_danger'), ('on_label', 'label_info'),
                   ('on_warn', 'label_warning'), ('bright', 'btn_primary'), ('link', 'hover'), ('link_hover', 'surface')):
        r = ratio(p[fg], p[bg])
        if r < 4.5:
            bad.append(f'{fg} on {bg}: {r:.2f}')
    return bad


# ---------------------------------------------------------------------------------------------------
# The gradients: beads, lace and pearls. Gradients only, so an upload could use every one of them.

def bead(cx, cy, r, colour):
    return f'radial-gradient(circle at {cx:g}px {cy:g}px, {colour} 0, {colour} {r:g}px, transparent {r + 0.5:g}px)'


def corner(corner):
    """One gradient for a corner slot. (A slot has to be exactly one gradient: base.css gives the eight slots
    one size and one position each, so a slot that held several would push the later ones into the wrong
    corners.) The slot is a 32 x 32 box and the panel's corner is 8px in from the two near sides; only the
    outer 8px is outside the panel, which is the part that shows. At a long curve (radius 18) it is a gold arc
    and a thinner peach one, concentric with the corner, 4px and 7px out; at a short corner it is a gold bead
    in a thin peach ring."""
    gold, peach = 'var(--p-gold-hi)', 'var(--p-peach)'
    if corner in ('tr', 'bl'):
        cx, cy = (6, 26) if corner == 'tr' else (26, 6)
        r = 18
        return (f'radial-gradient(circle at {cx}px {cy}px, transparent {r + 3.5:g}px, {gold} {r + 4}px, {gold} {r + 5.5:g}px, '
                f'transparent {r + 6}px, transparent {r + 7.2:g}px, {peach} {r + 7.5:g}px, {peach} {r + 8.2:g}px, transparent {r + 8.5:g}px)')
    cx = 4.5 if corner[1] == 'l' else 27.5
    cy = 4.5 if corner[0] == 't' else 27.5
    return (f'radial-gradient(circle at {cx:g}px {cy:g}px, {gold} 0, {gold} 2.4px, transparent 2.9px, transparent 4px, '
            f'{peach} 4.3px, {peach} 5.1px, transparent 5.5px)')


def lace():
    """A row of scallops hanging from a line, with a bead under each: the lace on a shirt-front."""
    arc = 'radial-gradient(circle at 50% 0, transparent 5.1px, var(--p-violet-hi) 5.6px, var(--p-violet-hi) 6.6px, transparent 7.1px)'
    return arc + ', ' + bead(7, 7.2, 1.4, 'var(--p-gold-hi)').replace('circle at 7px', 'circle at 50%')


def pearls():
    """A string of pearls: a bead with a small bright one on its shoulder, over a hairline of violet."""
    return ', '.join([
        'radial-gradient(circle at 50% 5px, var(--p-gold-hi) 0, var(--p-gold) 2.4px, transparent 3px)',
        'linear-gradient(0deg, transparent 0, transparent 9px, var(--p-violet) 9px, var(--p-violet) 10px, transparent 10px)',
    ])


def decls(p):
    """The slow-fade periods, as whole declarations, or nothing when a skin sets none (Dawn does not breathe)."""
    out = {
        'corner_tl': corner('tl'), 'corner_tr': corner('tr'), 'corner_bl': corner('bl'), 'corner_br': corner('br'),
        'lace': lace(), 'pearls': pearls(),
        'frame_breathe_decl': f"  --ts-frame-breathe: {p['frame_breathe']};" if p['frame_breathe'] else '  /* the frame does not breathe by day */',
        'marker_breathe_decl': f"  --ts-heading-marker-breathe: {p['marker_breathe']};" if p['marker_breathe'] else '  /* nor the heading ribbon */',
    }
    names = {'ink': 'violet', 'fill': 'peach', 'dot': 'gold'}
    out.update({f'{k}_name': v for k, v in names.items()})
    return out


# ---------------------------------------------------------------------------------------------------
# The texture

def paisley_tile(p, preview=None):
    """A 256 x 256 tile of paisley: three sizes, outlined twice, a row of beads down each spine, a ring round
    each head, and florets and dots between. Every shape is measured modulo the tile, so it wraps."""
    import numpy as np  # imported here so --check needs nothing but the standard library
    from PIL import Image

    N, S = 256, 3
    M = N * S
    yy, xx = (np.mgrid[0:M, 0:M].astype(np.float64) + 0.5) / S

    def wrap(d):
        return (d + N / 2) % N - N / 2

    def dist(cx, cy):
        return np.hypot(wrap(xx - cx), wrap(yy - cy))

    ink = np.zeros((M, M), dtype=bool)
    fill = np.zeros((M, M), dtype=bool)
    dot = np.zeros((M, M), dtype=bool)

    def paisley(cx, cy, r0, rot, mirror):
        """The head's centre is (cx, cy); the tail curls up and over, then the whole is turned by rot degrees."""
        theta = 1.12 * math.pi
        rc = 1.55 * r0
        a = math.radians(rot)
        ca, sa = math.cos(a), math.sin(a)

        def place(lx, ly):
            if mirror:
                lx = -lx
            return cx + lx * ca - ly * sa, cy + lx * sa + ly * ca

        steps = 64
        sdf = np.full((M, M), 1e9)
        spine = []
        for k in range(steps + 1):
            t = k / steps
            ang = math.pi + t * theta
            lx, ly = rc * (math.cos(ang) + 1), rc * math.sin(ang)
            r = r0 * (1 - t) ** 0.8 * (1 + 0.22 * math.sin(math.pi * min(1, t * 1.25)))
            px, py = place(lx, ly)
            if r > 0.3:
                sdf = np.minimum(sdf, dist(px, py) - r)
            spine.append((px, py, r, t))
        w = 0.85
        inset = max(3.4, 0.2 * r0)
        ink[:] |= np.abs(sdf) < w
        ink[:] |= (np.abs(sdf + inset) < w * 0.8) & (sdf < 0)
        fill[:] |= sdf < -(inset + 1.2)
        # beads down the spine, and a ring of them round the head
        for px, py, r, t in spine[6:46:5]:
            dot[:] |= dist(px, py) < max(1.0, 0.2 * r)
        hx, hy = place(0, 0)
        for k in range(10):
            b = 2 * math.pi * k / 10
            dot[:] |= dist(hx + 0.55 * r0 * math.cos(b), hy + 0.55 * r0 * math.sin(b)) < max(0.9, 0.07 * r0)
        ink[:] |= np.abs(dist(hx, hy) - 0.3 * r0) < w * 0.8

    def floret(cx, cy, r):
        for k in range(6):
            b = 2 * math.pi * k / 6
            ink[:] |= np.abs(dist(cx + r * math.cos(b), cy + r * math.sin(b)) - 0.55 * r) < 0.7
        dot[:] |= dist(cx, cy) < max(1.2, 0.3 * r)

    paisley(72, 150, 25, -35, False)
    paisley(196, 66, 19, 150, True)
    paisley(206, 214, 12.5, 62, False)
    paisley(34, 36, 10.5, 215, True)
    floret(146, 128, 7)
    floret(240, 140, 5)
    floret(112, 28, 5.5)
    floret(36, 236, 6)
    for x, y in ((150, 190), (122, 214), (252, 36), (170, 20), (104, 98), (14, 112), (182, 128), (236, 96), (62, 80)):
        dot[:] |= dist(x, y) < 1.3

    cov = {k: m.reshape(N, S, N, S).mean(axis=(1, 3)) for k, m in (('fill', fill), ('ink', ink), ('dot', dot))}
    L = 8
    inks = [[int(v) for v in p[k].split(',')] for k in ('fill', 'ink', 'dot')]
    tops = [int(round(p[k + '_a'] * 255)) for k in ('fill', 'ink', 'dot')]
    weights = np.stack([cov['fill'] * 0.8, cov['ink'], cov['dot'] * 1.2])
    cls = weights.argmax(axis=0)
    c = np.take_along_axis(np.stack([cov['fill'], cov['ink'], cov['dot']]), cls[None], axis=0)[0]
    level = np.clip(np.rint(c * (L - 1)), 0, L - 1).astype(np.uint8)
    idx = np.where(level > 0, cls * L + level, 0).astype(np.uint8)
    img = Image.fromarray(idx, 'P')
    pal = []
    for rgb in inks:
        pal += rgb * L
    img.putpalette(pal)
    img.info['transparency'] = bytes(int(round(i / (L - 1) * tops[k])) for k in range(3) for i in range(L))
    if preview:
        bg = tuple(int(p['bg'][i:i + 2], 16) for i in (1, 3, 5))
        sheet = Image.new('RGBA', (N * 3, N * 3), bg + (255,))
        rgba = img.convert('RGBA')
        for i in range(3):
            for j in range(3):
                sheet.alpha_composite(rgba, (i * N, j * N))
        os.makedirs(preview, exist_ok=True)
        sheet.convert('RGB').save(os.path.join(preview, p['id'] + '-preview.png'))
    return img


# ---------------------------------------------------------------------------------------------------

def graph_conf(p):
    light = p['mode'] == 'light'
    if light:
        chrome = f"rrdgraph_def_text=-c BACK{p['surface'].upper()} -c SHADEA#EEEEEE00 -c SHADEB#EEEEEE00 -c CANVAS#FFFFFF00 -c GRID{p['line'].upper()} -c MGRID{p['line'].upper()} -c FRAME{p['line_strong'].upper()} -c ARROW{p['line_strong'].upper()}"
        font = f"rrdgraph_def_text_color={p['text'][1:].upper()}"
    else:
        chrome = f"rrdgraph_def_text_dark=-c BACK{p['surface'].upper()} -c SHADEA#EEEEEE00 -c SHADEB#EEEEEE00 -c CANVAS#FFFFFF00 -c GRID{p['hover'].upper()} -c MGRID{p['line'].upper()} -c FRAME{p['line'].upper()} -c ARROW{p['gold_hi'].upper()}"
        font = f"rrdgraph_def_text_color_dark={p['text'][1:].upper()}"
    lines = [
        f"# RRDtool graph colours for the {p['name']} skin.",
        '#',
        '# Graph interiors are drawn on the server, so CSS cannot reach them; see skins/organic/graph.conf for the long',
        '# explanation of the two kinds of key. A skin\'s palette applies in the mode the skin is written for, so this',
        f"# one sets the {'light' if light else 'dark'} chrome. Inbound is violet and outbound is peach, which differ in hue and in",
        '# lightness, so they stay apart for anyone who cannot tell colours apart.',
        '',
        chrome,
        font,
    ]
    for name, ramp in p['ramps'].items():
        lines.append(f'graph_colours.{name}=[' + ','.join(f'"{c}"' for c in ramp) + ']')
    lines += ['', '# Port traffic series: palest first (the 95th-percentile fill, the area fill, the outline).',
              'graph_colours.port_in=[' + ','.join(f'"{c}"' for c in p['ins']) + ']',
              'graph_colours.port_out=[' + ','.join(f'"{c}"' for c in p['outs']) + ']', '']
    return '\n'.join(lines)


def outputs():
    out = {}
    for p in PALETTES.values():
        d = os.path.join('skins', p['id'])
        extra = decls(p)
        out[os.path.join(d, 'skin.json')] = SKIN_JSON.substitute(p)
        out[os.path.join(d, 'features.json')] = FEATURES_JSON
        out[os.path.join(d, 'FONTS.md')] = FONTS_MD.substitute(p)
        out[os.path.join(d, 'TEXTURES.md')] = TEXTURES_MD.substitute(dict(p, **extra))
        out[os.path.join(d, 'skin.css')] = CSS.substitute(dict(p, **extra))
        out[os.path.join(d, 'graph.conf')] = graph_conf(p)
    return out


def main():
    check = '--check' in sys.argv
    preview = sys.argv[sys.argv.index('--preview') + 1] if '--preview' in sys.argv else None
    failed = False
    for p in PALETTES.values():
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
        for p in PALETTES.values():
            tex = os.path.join('skins', p['id'], 'textures', 'paisley.png')
            os.makedirs(os.path.dirname(tex), exist_ok=True)
            image = paisley_tile(p, preview)
            image.save(tex, 'PNG', optimize=True, transparency=image.info['transparency'])
            print(f'wrote {tex}: {os.path.getsize(tex)} bytes')
    sys.exit(1 if failed else 0)


if __name__ == '__main__':
    main()
