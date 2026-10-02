# Textures — Digital Rain

`textures/rain.png` is the falling glyphs: a seamless 256 x 256 tile, mostly transparent,
that the page layer (`--ts-rain-image`, [docs/ORNAMENTS.md](../../docs/ORNAMENTS.md), "Phase F")
scrolls down by exactly one tile every 36 seconds.

It is **computed, not drawn, photographed or typeset**. `scripts/make-rain.py` makes it from a
fixed random seed. Every glyph is a few random strokes (bars, diagonals, dots) on a 5 x 7 grid,
so the "writing" is not any script or font and has no licence history to credit. Each column
has a drop: a bright head with a fading trail above it, some columns have a second, shorter
drop, and a few dim strays fill the gaps. Rows and columns wrap, so the tile has no seam in
either direction, and running the script again gives the same file.

```
python scripts/make-rain.py skins/digital-rain/textures/rain.png            # rewrite the tile
python scripts/make-rain.py skins/digital-rain/textures/rain.png --preview  # and a 3 x 3 preview
```

(Needs numpy and Pillow; development only. Put the preview somewhere else: the plugin refuses
a second PNG in a skin's `textures/`.) The tile is saved as an indexed PNG of at most 96 colours
with no metadata, which is the form the plugin requires of a texture that ships in this
package. `skin.css` declares it the way any skin does:

```css
--tx-rain: url("textures/rain.png");
--ts-rain-image: var(--tx-rain);
--ts-rain-tile: 256px;
--ts-rain-period: 36s;
```

When the plugin publishes the skin it reads the PNG, checks it again, and embeds it in the
published `skin.css` as a `data:` URL, so no image file is served.
