# Fonts — Zerg

**The fonts ship with the skin. There is nothing to install.**

Copy the `zerg/` directory and the typography works. No system fonts to chase,
no Google Fonts request, nothing for the end user to do.

---

## The three voices

| Token | Face | Role | Applied to |
|---|---|---|---|
| `--p-font-chrome` | Metamorphous 400 | Gnarled organic display — bone grown into letterforms | Navbar, panel headers, table **headers**, buttons, tabs |
| `--p-font-data` | Chakra Petch 500/700 | Angular carapace-plate sans | Device hostnames, table **body cells**, labels, badges, inputs |
| `--p-font-code` | Space Mono 400 | Monospace | `pre` / `code` only |

Metamorphous has rough, irregular stroke terminals that read as bone or chitin
rather than type — exactly the "grown, not drawn" quality the rest of the skin
is after. It is a display face, so it is used only at header sizes and never in
a table cell.

Chakra Petch carries the data. Its letterforms have clipped diagonal corners
that echo carapace plating, but it was designed for interfaces and stays
readable at 13px in a dense device list. That balance is the whole reason it
was chosen over something more overtly alien.

---

## What ships

```
skins/zerg/fonts/
  Metamorphous-Regular.woff2   22.6 KB
  ChakraPetch-Medium.woff2      9.7 KB
  ChakraPetch-Bold.woff2        9.7 KB
  SpaceMono-Regular.woff2      16.1 KB
  OFL-Metamorphous.txt
  OFL-ChakraPetch.txt
  OFL-SpaceMono.txt
```

**~58 KB total** — the lightest of the three skins. Latin unicode-range subsets
only, as served by Google Fonts. All three faces are **SIL Open Font License
1.1**, which explicitly permits redistribution; the notices ship alongside them
as the licence requires.

Regenerate reproducibly with `scripts/fetch-fonts.ps1`.

---

## The one deliberate restraint

Zerg is the skin most tempting to overdo, and the temptation is typographic.
There are fonts that look far more Zerg than Metamorphous —
[Nosifer](https://fonts.google.com/specimen/Nosifer) drips,
[Eater](https://fonts.google.com/specimen/Eater) is visibly fungal,
[Creepster](https://fonts.google.com/specimen/Creepster) is pure B-movie.

None of them survive contact with a monitoring tool. They are unreadable below
about 20px, and a NOC display is mostly 12–14px text that someone reads for
eight hours. Using one would make a great screenshot and an unusable interface.

So the organic weirdness lives in the **geometry** instead — asymmetric radii,
uneven border widths, mottled creep, the slow breathe on the navbar vent — and
the type stays legible. If you disagree and want the drip, it is one variable:

```css
html.dark { --p-font-chrome: "Eater", cursive; }
```

Drop the `.woff2` into `fonts/`, point a `@font-face` at it, and nothing below
section 1b needs to change — no rule in the skin names a font directly. Just
leave `--p-font-data` alone.

---

## Why bundled rather than `@import`

A Google Fonts `@import` is one line and would have worked on a laptop. It is
the wrong call for a monitoring box: a NOC is frequently air-gapped or
egress-filtered, so the skin would silently degrade to fallbacks precisely
where that is least convenient to debug; it leaks a third-party request per
operator per page load; and it makes render depend on someone else's CDN inside
the tool you use to find out whether the network is down.

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
