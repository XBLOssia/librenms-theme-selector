# Fonts — Terran

**The fonts ship with the skin. There is nothing to install.**

Copy the `terran/` directory and the typography works. No system fonts to
chase, no Google Fonts request, nothing for the end user to do.

---

## The two voices

| Token | Face | Role | Applied to |
|---|---|---|---|
| `--p-font-chrome` | Saira Condensed 600/700 | Condensed caps — "stencilled on the hull" | Navbar, panel headers, table **headers**, buttons, tabs |
| `--p-font-data` | JetBrains Mono 400/700 | Monospace — "CRT terminal readout" | Device hostnames, table **body cells**, labels, badges, `pre`/`code` |

Table body cells and status bugs also get `font-variant-numeric: tabular-nums`,
so uptimes, counters and port numbers align into columns instead of drifting.

---

## What ships

```
skins/terran/fonts/
  SairaCondensed-SemiBold.woff2   17.6 KB
  SairaCondensed-Bold.woff2       17.4 KB
  JetBrainsMono-Regular.woff2     20.7 KB
  JetBrainsMono-Bold.woff2        21.4 KB
  OFL-SairaCondensed.txt
  OFL-JetBrainsMono.txt
```

**~77 KB total.** These are the Latin unicode-range subsets that Google Fonts
already serves — U+0000–00FF plus the punctuation the UI actually uses. The
full faces would be several hundred KB each; nothing in LibreNMS's interface
needs Devanagari or Cyrillic.

Both faces are **SIL Open Font License 1.1**, which explicitly permits
redistribution. The notices ship alongside them, as the licence requires.

Regenerate reproducibly with `scripts/fetch-fonts.ps1`.

---

## Why bundled rather than `@import`

A Google Fonts `@import` is one line and would have worked on a laptop. It is
the wrong call for a monitoring box:

- A NOC is frequently air-gapped or egress-filtered. The skin would silently
  degrade to fallbacks precisely where it is least convenient to debug.
- It leaks a third-party request per operator per page load.
- It makes page render depend on someone else's CDN — inside the tool you use
  to find out whether the network is broken.

Bundling is the same result with none of that.

---

## Why it works from `html/css/custom/`

Font files placed there are served, not swallowed by the router. LibreNMS's
catch-all rewrite is guarded:

```apache
RewriteCond %{REQUEST_FILENAME} !-f
RewriteRule ^(.*)$ index.php
```

A file that exists on disk fails the `!-f` condition, so the rewrite never
fires and the file is served directly. That directory is also gitignored
upstream, so the fonts survive `./daily.sh` alongside the stylesheet.

Paths in `url()` resolve relative to the **stylesheet**, not the page — so the
skin directory can live anywhere under the webroot and the fonts still load.

---

## Changing the faces

Everything is two variables at the top of `skins/terran/skin.css`, in the `html.dark` block. To swap a face, drop a
`.woff2` in `fonts/`, point the matching `@font-face` at it, and you are done —
no rule below section 1b mentions a font by name.

To go harder on the CRT look, [Share Tech Mono](https://fonts.google.com/specimen/Share+Tech+Mono)
or [VT323](https://fonts.google.com/specimen/VT323) will do it. Both are
considerably less legible at 12px, and a device list is something people stare
at for eight hours — consider scoping the novelty face to chrome only and
leaving table cells on a workhorse mono:

```css
html.dark { --p-font-chrome: "Share Tech Mono", ui-monospace, monospace; }
```
