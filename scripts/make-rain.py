"""Generate skins/digital-rain/textures/rain.png: a seamless tile of falling "digital rain".

    python scripts/make-rain.py [out.png] [--preview]

Nothing here is a font, a photograph or a copy of anything: every glyph is a few random strokes
(bars, diagonals, dots) on a 5 x 7 grid, so the "writing" is not any script and has no licence
history. The tile is computed from a fixed seed, so the same command writes the same file, and it
repeats without a seam in both axes (rows and columns wrap), which is what lets the page layer
(--ts-rain-image, docs/ORNAMENTS.md "Phase F") scroll it by exactly one tile and loop.

A tile is 16 x 16 cells of 16 px. Each column has a drop: a bright head, with a trail of cells
above it that fade. Some columns have a second, shorter drop, and a few stray dim glyphs fill the
gaps. It is mostly transparent (alpha under 0.6 at the head, under 0.4 in the trail, so text on a
panel never sits on it, and the dashboard gaps show it quietly).

Needs numpy and Pillow (development only; the plugin never runs this).
"""
import sys

import numpy as np
from PIL import Image

N = 256
CELL = 16
CELLS = N // CELL
SEED = 1999
GW, GH = 5, 7          # glyph grid
SCALE = 2              # one glyph pixel is 2 x 2 tile pixels, so a glyph is 10 x 14

# the palette: a pale mint head, then phosphor greens down to a deep one
HEAD = np.array([214, 255, 224], dtype=np.float64)
BRIGHT = np.array([0, 255, 65], dtype=np.float64)
DEEP = np.array([0, 110, 28], dtype=np.float64)


def glyph(rng):
    """A random 5 x 7 bitmap made of two to four strokes."""
    g = np.zeros((GH, GW), dtype=bool)
    for _ in range(int(rng.integers(2, 5))):
        kind = int(rng.integers(0, 5))
        if kind == 0:      # horizontal bar
            r = int(rng.integers(0, GH))
            length = int(rng.integers(3, GW + 1))
            c0 = int(rng.integers(0, GW - length + 1))
            g[r, c0:c0 + length] = True
        elif kind == 1:    # vertical bar
            c = int(rng.integers(0, GW))
            length = int(rng.integers(3, GH + 1))
            r0 = int(rng.integers(0, GH - length + 1))
            g[r0:r0 + length, c] = True
        elif kind in (2, 3):   # diagonal
            length = int(rng.integers(3, 5))
            r0 = int(rng.integers(0, GH - length + 1))
            c0 = int(rng.integers(0, GW - length + 1))
            for i in range(length):
                c = c0 + i if kind == 2 else c0 + length - 1 - i
                g[r0 + i, c] = True
        else:              # dot pair
            g[int(rng.integers(0, GH)), int(rng.integers(0, GW))] = True
            g[int(rng.integers(0, GH)), int(rng.integers(0, GW))] = True
    if rng.random() < 0.5:
        g = g[:, ::-1]
    return g


def blit(mask, cell_row, cell_col, alpha_img, colour_img, alpha, colour):
    big = np.kron(mask.astype(np.float64), np.ones((SCALE, SCALE)))      # 14 x 10
    h, w = big.shape
    y0 = cell_row * CELL + (CELL - h) // 2
    x0 = cell_col * CELL + (CELL - w) // 2
    for dy in range(h):
        for dx in range(w):
            if big[dy, dx] > 0:
                y, x = (y0 + dy) % N, (x0 + dx) % N
                if alpha > alpha_img[y, x]:
                    alpha_img[y, x] = alpha
                    colour_img[y, x] = colour


def build():
    rng = np.random.default_rng(SEED)
    alpha = np.zeros((N, N))
    colour = np.zeros((N, N, 3))
    head_alpha = np.zeros((N, N))

    for col in range(CELLS):
        drops = [(int(rng.integers(0, CELLS)), int(rng.integers(8, 15)))]
        if rng.random() < 0.45:
            drops.append((int(rng.integers(0, CELLS)), int(rng.integers(4, 8))))
        for head, length in drops:
            for d in range(length + 1):
                row = (head - d) % CELLS
                if d == 0:
                    a, c = 0.60, HEAD
                else:
                    t = d / length
                    a = 0.40 * np.exp(-2.6 * t) + 0.02
                    c = BRIGHT * (1 - t) + DEEP * t
                blit(glyph(rng), row, col, alpha, colour, a, c)
                if d == 0:
                    blit(glyph(rng), row, col, head_alpha, np.zeros((N, N, 3)), 0.60, c)
        # a few stray, dim glyphs
        for row in range(CELLS):
            if alpha[row * CELL:(row + 1) * CELL, col * CELL:(col + 1) * CELL].max() == 0 and rng.random() < 0.12:
                blit(glyph(rng), row, col, alpha, colour, 0.07, DEEP)

    # a little bloom around the heads (periodic: np.roll wraps)
    bloom = np.zeros_like(alpha)
    for dy in range(-2, 3):
        for dx in range(-2, 3):
            bloom += np.roll(np.roll(head_alpha, dy, axis=0), dx, axis=1) * (1.0 / (1 + dx * dx + dy * dy))
    bloom = np.clip(bloom * 0.09, 0, 0.22)
    new_alpha = np.maximum(alpha, bloom)
    glow = (new_alpha > alpha) & (bloom > 0)
    colour[glow] = BRIGHT
    rgba = np.zeros((N, N, 4), dtype=np.uint8)
    rgba[..., :3] = np.clip(colour, 0, 255).astype(np.uint8)
    rgba[..., 3] = np.clip(new_alpha * 255 + 0.5, 0, 255).astype(np.uint8)
    return Image.fromarray(rgba, 'RGBA')


def main():
    args = [a for a in sys.argv[1:] if not a.startswith('--')]
    out = args[0] if args else 'skins/digital-rain/textures/rain.png'
    img = build()
    pal = img.quantize(colors=96, method=Image.Quantize.FASTOCTREE, dither=Image.Dither.NONE)
    pal.save(out, 'PNG', optimize=True)
    print(f'wrote {out}: {N}x{N}, {len(open(out, "rb").read())} bytes')
    if '--preview' in sys.argv:
        bg = Image.new('RGBA', (N * 3, N * 3), (2, 8, 3, 255))
        for i in range(3):
            for j in range(3):
                bg.alpha_composite(img, (i * N, j * N))
        bg.convert('RGB').save(out.replace('.png', '-preview.png'))


if __name__ == '__main__':
    main()
