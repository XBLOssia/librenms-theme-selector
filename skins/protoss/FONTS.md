# Fonts — Protoss

**The fonts ship with the skin. There is nothing to install.**

Copy the `protoss/` directory and the typography works. No system fonts to
chase, no Google Fonts request, nothing for the end user to do.

This matters more here than it does for Terran — see [Why this one
needed it](#why-this-one-needed-it).

---

## The three voices

| Token | Face | Role | Applied to |
|---|---|---|---|
| `--p-font-chrome` | Cinzel 600 | Carved ceremonial capitals — the Khalai-inscription voice | Navbar, panel headers, table **headers**, buttons, tabs |
| `--p-font-data` | Rajdhani 500/700 | Futuristic but readable | Device hostnames, table **body cells**, labels, badges, inputs |
| `--p-font-code` | Space Mono 400 | Monospace | `pre` / `code` only |

The design intent is a deliberate collision: Protoss are simultaneously ancient
and hyper-advanced, so the frame is carved-stone Roman capitals and the data is
clean technical sans. That contrast *is* the skin.

---

## What ships

```
skins/protoss/fonts/
  Cinzel-SemiBold.woff2     14.8 KB
  Rajdhani-Medium.woff2     14.7 KB
  Rajdhani-Bold.woff2       15.3 KB
  SpaceMono-Regular.woff2   16.1 KB
  OFL-Cinzel.txt
  OFL-Rajdhani.txt
  OFL-SpaceMono.txt
```

**~61 KB total.** Latin unicode-range subsets only, as served by Google Fonts —
U+0000–00FF plus the punctuation the UI actually uses. All three faces are
**SIL Open Font License 1.1**, which explicitly permits redistribution; the
notices ship alongside them as the licence requires.

Regenerate reproducibly with `scripts/fetch-fonts.ps1`.

---

## Why this one needed it

Before bundling, this skin was measurably *not* the skin it was designed to be.
Probing each stack with and without its first choice — identical rendered
widths mean the font is absent — showed:

| Declared | Fell through to | Effect |
|---|---|---|
| `Cinzel` | `Georgia` | Acceptable. Serif caps still read ceremonial, just less carved. |
| `Rajdhani` | `Segoe UI` | **Fatal.** An ordinary UI sans. All the futurism gone. |
| `Space Mono` | generic mono | Fine. Small surface. |

The frame survived; the data did not. On fallbacks alone Protoss was a blue and
gold repaint of the default theme. Bundling is what makes it the skin.

Early screenshots in this repo's history were captured before the fonts were
bundled and show that degraded state.

---

## Why bundled rather than `@import`

A Google Fonts `@import` is one line and would have worked on a laptop. It is
the wrong call for a monitoring box:

- A NOC is frequently air-gapped or egress-filtered. The skin would silently
  degrade to the state described above, precisely where it is least convenient.
- It leaks a third-party request per operator per page load.
- It makes page render depend on someone else's CDN — inside the tool you use
  to find out whether the network is broken.

---

## Why it works from `html/css/custom/`

LibreNMS's catch-all rewrite is guarded:

```apache
RewriteCond %{REQUEST_FILENAME} !-f
RewriteRule ^(.*)$ index.php
```

A file that exists on disk fails `!-f`, so the rewrite never fires and the file
is served directly. That directory is gitignored upstream, so the fonts survive
`./daily.sh`. Paths in `url()` resolve relative to the **stylesheet**, so the
skin directory can live anywhere under the webroot.

---

## Going further

If you want more esoteric and will trade legibility for it:

- **Chrome:** [Cormorant SC](https://fonts.google.com/specimen/Cormorant+SC) —
  higher contrast, more arcane. Gets fragile below 13px.
- **Data:** [Orbitron](https://fonts.google.com/specimen/Orbitron) — maximum
  sci-fi, genuinely hard to read as a dense device list.

Resist putting a display face in table cells. Scope it to the frame instead:

```css
html.dark { --p-font-chrome: "Orbitron", sans-serif; }
```

Drop the new `.woff2` into `fonts/`, point its `@font-face` at it, and nothing
below section 1b needs to change — no rule in the skin names a font directly.
