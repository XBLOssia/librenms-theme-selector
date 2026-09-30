# Textures — Zerg

`textures/creep.png` is the creep: a seamless 256 x 256 tile of cellular tissue in
plum and magenta, tiled across the page background behind the soft blotches that
break up its repetition.

It is **computed, not drawn or photographed**. `scripts/make-creep.py` makes it
from a fixed random seed (periodic noise for the blotches, a cellular field for the
cells and their membranes, the zero-contours of a warped field for the veins, and a
scatter of small bright nodes), so there is no image source to credit or license:
the picture is the output of the script in this repository, and running the script
again gives the same file. Every layer is periodic, so the tile has no seam.

```
python scripts/make-creep.py skins/zerg/textures/creep.png            # rewrite the tile
python scripts/make-creep.py skins/zerg/textures/creep.png --preview  # and a 3 x 3 preview
```

(Needs numpy and Pillow; development only.) The tile is saved as an indexed PNG with
no metadata, which is the form the plugin requires of a texture that ships in this
package. `skin.css` declares it the way any skin does:

```css
--tx-creep: url("textures/creep.png");
--ts-body-bg-image: var(--tx-creep), var(--p-creep), linear-gradient(...);
```

When the plugin publishes the skin it reads the PNG, checks it again, and embeds it
in the published `skin.css` as a `data:` URL, so no image file is served.
