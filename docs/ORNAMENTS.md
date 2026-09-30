# Ornaments for installed skins

The three bundled skins decorate the page with things an upload is not allowed
to do: corner brackets, accent bars, rivet rows, glowing strips under the navbar,
cut corners, animation. They do it through the 36 *structural* tokens (position,
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

## Roadmap to parity with the bundled skins

| Bundled skin does this | With | Status |
|---|---|---|
| Corner brackets on panels (Protoss) | frame corner slots | Phase A. Behind the content, so a bracket over the heading is hidden by it; brackets drawn outside the frame or on the border show |
| Bars that jut past a frame | frame corner and edge slots | Phase A |
| Different radius per corner | `--ts-panel-radius-*` | Phase A |
| Accent bar on a panel heading (all three) | a heading marker slot: fixed 8px band on the heading's left edge, paint only | Phase B |
| Rivet / sheen row in a heading (Terran) | a heading strip slot inside the heading, behind its text | Phase B |
| Glow or rivet strips on the navbar's top and bottom edge (all three) | navbar strip slots: fixed 8px bands, top and bottom | Phase B |
| Frames on dashboard widgets | the same slots, inside the widget's own edge (no overhang: LibreNMS gives widgets uneven gutters and scrolls their contents) | Phase B |
| Cut (chamfered) corners on buttons, labels, badges (Protoss) | one length per element, `--ts-btn-chamfer` and so on, bounded to ~10px, used in a fixed `clip-path` polygon that base.css writes. A cut is a triangle at most 10px across: too small to hide text | Phase C |
| Animation: a breathing glow, pulse, LED blink (Zerg, Protoss, Terran) | a short list of animations written in base.css, selectable by name, running only on ornament layers, never shorter than 2s, off under `prefers-reduced-motion` | Phase D |
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
