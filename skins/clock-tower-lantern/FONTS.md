# Fonts — Clock Tower (Clock Tower Lantern)

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
skins/clock-tower-lantern/fonts/
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
