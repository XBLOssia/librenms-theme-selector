# Fonts — Clock Tower (Clock Tower Gotham)

**The fonts ship with the skin. There is nothing to install.**

They sit inside the skin folder, so nothing is fetched at run time: no Google Fonts request, nothing for
the end user to do. (The folder alone does not apply a skin: it needs the plugin and `base/base.css`, see
the README.)

## The two voices

| Token | Face | Role | Applied to |
|---|---|---|---|
| `--p-display` | Cinzel 600 | Carved Roman capitals, the lettering of an inscription: the numerals of a clock face | Navbar, panel and widget headings, table headings, tabs |
| `--p-serif` | Libre Baskerville 400/700 | A sturdy, open book serif that holds up at 13-14px | Everything else: table cells, labels, inputs, text |

Both are named in `skin.css` by private family names (`Clock Tower Display`, `Clock Tower Text`), with the
real names and then the system serifs (Palatino, Book Antiqua, Georgia) behind them, so a failed load
degrades to a serif and never to a sans. Code and `pre` use the system's Courier.

Cinzel is set only in capitals-style headings and chrome: its lower case is small capitals, and its
figures are lining, so hostnames and counters in headings read correctly.

## What ships

```
skins/clock-tower-gotham/fonts/
  Cinzel-SemiBold.woff2            14.8 KB
  LibreBaskerville-Regular.woff2   19.6 KB
  LibreBaskerville-Bold.woff2      20.0 KB
  OFL-Cinzel.txt
  OFL-LibreBaskerville.txt
```

Only the `latin` subset is bundled, as Google Fonts serves it (about 15-20 KB a file instead of several
hundred). `scripts/fetch-fonts.ps1` regenerates them reproducibly:

```
powershell -ExecutionPolicy Bypass -File scripts/fetch-fonts.ps1 -Skins clock-tower-gotham
```

## Licences

Both faces are SIL Open Font License 1.1, which permits redistribution with the notice. The notices
are in `fonts/`. The OFL's reserved font names are "Cinzel" and "Libre Baskerville"; the files are served
unmodified, and the private family names above are CSS aliases, not renamed fonts.

## Swapping a face

Change the `src` of the `@font-face` blocks in `skin.css` (and the file in `fonts/`), or edit the
template in `scripts/make-clock-tower.py` and regenerate.
