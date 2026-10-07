# Security: uploaded skins

Admins can install skins from a zip. That makes the plugin a place where
attacker-controlled bytes enter a system that serves web pages and runs PHP, so
this page says what is defended, how, how each defence is tested, and what is
not defended.

Report a problem by opening an issue, or privately to the address on the
maintainer's GitHub profile.

---

## Threat model

**What is being protected:** the LibreNMS host and its users. A skin must not
be able to run code, read or write files it shouldn't, make browsers load
anything from elsewhere, or take over parts of the page.

**Who can attack:**

| Actor | What they can do | Why it matters |
|---|---|---|
| Someone who convinces an admin to install a skin they made | Supply any bytes | The realistic case: skins get shared |
| An attacker with a stolen admin session | Upload, delete, change the default | Can already do far more in LibreNMS itself, but should not gain code execution through this plugin |
| A CSRF attack on a logged-in admin | Make the admin's browser POST | The upload and delete routes must not be forgeable |
| A non-admin | Reach the endpoints | Must be refused |
| A malicious user choosing a skin | Choose only from what is installed | Must not be able to name a file or path |

**Not in scope:** an attacker who already has code execution on the host, or a
malicious LibreNMS admin acting through LibreNMS's own features.

**Assets and trust boundaries.** The bundle is untrusted from the moment it
arrives until the validator has regenerated it. Everything after that
(`CompiledSkin`) is trusted output of our own code. The web root is a place
that runs PHP, so what goes there is restricted to text we generated.

---

## The design in one paragraph

**Nothing that was uploaded is ever served.** The zip is parsed by a strict
reader (no extraction), its metadata and stylesheet are validated against
allowlists, and the stylesheet is *regenerated* from the parsed result. Fonts
are embedded in that stylesheet as base64 `data:` URLs, so no uploaded byte is
ever a file in the web root. The only files written for an uploaded skin are
`skin.css` (for the mode it is written for) and `skin.mirror.css` (the same rules for the
other mode, made by swapping one selector), each of printable ASCII, and each is checked
again by a second, independent guard before it is written.

---

## Controls, and the test that would notice each one breaking

`php tests/run.php` runs 2,421 checks; `sh tests/mutate.sh` breaks each defence
on a scratch copy and requires a failing test (175 flaws caught, 9 documented as
redundant layers, 0 missed); `dev/test-upload.sh` drives the real endpoints.
(Counts as of 2026-10-05; `sh dev/test.sh all` prints the current ones.)
Run all of it with `sh dev/test.sh all`.

### The archive

| Attack | Control | Tested by |
|---|---|---|
| Zip-slip (`../evil.php`, absolute paths, backslashes, drive letters, NUL) | Nothing is extracted and no path is built from an entry name. Entry names must match an exact allowlist (`skin.json`, `skin.css`, `graph.conf`, `LICENSE.txt`, `fonts/<slug>.woff2\|woff`, `textures/<slug>.png`), matched with `\z` so a trailing newline can't slip through | `ZipTest` (47 hostile names), mutation "match with `$`" |
| A `.php`, `.htaccess`, `.svg`, nested zip or anything else in the bundle | Any entry outside the allowlist rejects the *whole* bundle, not just that entry | `ZipTest`, `evil-php-entry`, `evil-htaccess`, `evil-nested-zip` |
| Symlink or device entries | Unix mode bits checked; only regular files and directories | `ZipTest`, mutation "accept symlink entries" |
| Decompression bomb | Inflation counts output as it is produced and stops at the declared size; declared sizes are capped per file and in total | `ZipTest` (40 MB bomb stopped with < 20 MB memory), mutation "no cap while inflating" |
| An entry that shows different content to different readers | The central directory and each local header are cross-checked (name, method, sizes, CRC); one end record only; nothing after it; overlapping entries refused; CRC verified (bytes in a gap between entries are never read) | `ZipTest`, three mutations |
| Encryption, ZIP64, multi-part, exotic compression | Refused | `ZipTest` |
| Too many entries or fonts, huge files | Limits in `Limits.php` | `ZipTest` |
| Reliance on a zip library's path handling | The reader is our own and needs only ext-zlib (which LibreNMS requires), not ext-zip | by construction |

### The stylesheet

| Attack | Control | Tested by |
|---|---|---|
| Loading something from elsewhere (`url()`, `@import`, `image-set`, `element()`, `src()`, `attr()`, `env()`) | A value is tokenised and every token must be on a short allowlist; functions are an explicit list without any of those. `@import` and every other at-rule are refused | `CssTest` (well over a hundred cases) and the `test_values` group, which tests the value validator on its own; mutation "allow url() as a function" |
| Breaking out of a value (`}` `;` `{`, comments, `!important`) | The file is scanned by a character-level parser, not a regex; `{` in a value is an error; `/*` in a value is an error | `CssTest`, mutations |
| Backslash escapes (`u\72l(`) | `\` is not in the allowed character set | `CssTest` (`test_values`) |
| Styling arbitrary elements | Only one root block, `html.dark { ... }` or `html:not(.dark) { ... }` for the mode the skin is written for, and `@font-face` blocks are accepted, and inside them only custom properties. No other selector, no other property | `CssTest`, mutation "accept any selector" |
| Overwriting core's variables | Only known `--ts-*` tokens, the skin's own `--p-*` palette and its `--tx-<name>` texture declarations; `--tw-*` and everything else is refused | `CssTest`, mutation "accept unknown tokens" |
| Fake UI: overlay text, invisible click targets, oversized boxes | The 31 **structural** tokens (position, sizes, offsets, `z-index`, `pointer-events`, `content`, `clip-path`, animation, margin) can't be set by an upload. The list is derived from how `base.css` uses each token, and anything unrecognised is structural (deny by default) | `CssTest`, `MiscTest` (catalog vs `base.css`), mutation "let uploads set structural tokens" |
| Huge values to cover the page (giant shadow blur, enormous padding) | Numeric bounds per token kind (shadows 100 px, borders 24 px, lengths 64 px, everything else 800 px; filters, durations and percentages bounded) | `CssTest` (`test_values`), mutations |
| Getting round a token's cap through the palette | A palette entry used by a token is checked against that token's cap, transitively | `CssTest`, mutation "let the palette bypass a token's cap" |
| Filters that hide controls (`blur`, `opacity`, `drop-shadow`) | Only `brightness contrast saturate sepia hue-rotate invert grayscale`, each with one bounded argument | `CssTest` |
| Anything the parser lets through by mistake | `OutputGuard` re-checks the finished text with no knowledge of how it was produced: ASCII only, exactly one root block of the mode it is told (and none of the other), one `@font-face` per font, exactly one `url(` per font and each a base64 `data:` font URL (plus the `data:image/png` URLs of textures, below), and none of `<` `>` `\`, `@import`, `@charset`, `@namespace`, `@media`, `@keyframes`, `expression`, `javascript:`, `vbscript:`, `behavior`, `binding`, `image-set`, `element(`, `paint(`, `attr(` | `CssTest` (guard cases), mutation "guard: ignore stray url(" |
| The output as a whole | A mutation **fuzzer** (thousands of corrupted and injected variants per run) checks that whatever is accepted satisfies the invariants above | `FuzzTest` |

### Textures

A texture is a PNG a skin tiles in a background. It follows the font model: read
from its bytes with no image library, checked to the last byte, re-written as a
clean canonical PNG, and embedded in the generated stylesheet as a base64 `data:`
URL. No uploaded image is ever a file in the web root, and no `url()` in a skin
can point anywhere else.

| Attack | Control | Tested by |
|---|---|---|
| A script, SVG, PHP or other file renamed `.png` | The signature must be a PNG's, and every chunk, CRC and the pixel data must parse | `TextureTest` (signature cases, text, GIF, JPEG, SVG, PHP), `evil-texture-svg` |
| A real PNG with a payload appended (polyglot) | Nothing is allowed after `IEND`; the IEND chunk must be empty; the file is re-written, so the original bytes are not served | `TextureTest`, mutation "accept data after IEND", `evil-texture-php` |
| A payload hidden in the compressed stream, or in a chunk | The pixel data must inflate to exactly the size the header implies with nothing after the stream's end, and is **re-compressed**; every ancillary chunk (text, EXIF, colour profile, time) is dropped | `TextureTest`, mutations |
| A decompression bomb (a tiny stream that expands to gigabytes) | Inflation is fed in 512-byte slices and stops as soon as it passes the expected size; refusing a 64 MB bomb costs under 8 MB of memory; a header that declares more than 256 x 256 is refused before any inflation | `TextureTest` (bombs, memory bound), mutation "do not stop a decompression bomb early", `evil-texture-bomb`, `evil-texture-huge` |
| A decoder bug in the visitor's browser (the libwebp class of bug) | Only PNG (the simplest format), non-interlaced, 8 bits or less, not animated; the browser is shown only a file this plugin built, with the minimum chunks | by construction; `TextureTest` |
| A remote image (tracking beacon, mixed content) | The only `url()` a skin may write is `--tx-<name>: url("textures/<name>.png")` naming a file in the bundle; the output guard allows only `data:image/png` URLs that decode to a clean PNG | `TextureTest` (remote, data: and traversal cases), `evil-texture-remote`, mutations on the guard |
| A texture used to cover content or to fake UI | Image tokens are all backgrounds (behind content); textures are refused in every other kind of token; none can reach the panel corner overlay (whose values are plain colours) | `TextureTest` (colour, font and palette-route cases), mutation "allow a texture in a non-image token" |
| A long text or message drawn into a tile | Not preventable by parsing. Tiles are at most 256 x 256 and repeat behind content, never above it; the admin page lists each texture's name and size, and the admin who installs a skin is the control | documented limit |
| Cost (stylesheet size, page weight) | 64 KB per cleaned texture, 4 per skin, 128 KB in all; the stylesheet is cached by the browser and busted by its `?v=` | `TextureTest`, mutation |

### Fonts

| Attack | Control | Tested by |
|---|---|---|
| A script, SVG or HTML renamed `.woff2` | Signature must match the extension | `MiscTest`, mutation "skip the signature check" |
| A real font with a payload appended (polyglot) | WOFF and WOFF2 declare their own length; it must equal the file size | `MiscTest`, mutation |
| PHP or script inside the font bytes | Scanned for `<?php`, `<?=`, `<? `, `<script`. (`<%`, an ASP tag nothing here executes, is not scanned: two bytes occur by chance in real compressed fonts.) | `MiscTest`, mutation |
| A font file executed through a path-info trick (`.../font.woff2/x.php`) | There are no font files: fonts exist only inside the generated CSS as base64, which contains no `<`. Verified against the running server | `test-upload.sh` ("path-info trick") |

### Ornaments

The 31 structural tokens stay closed to uploads. Installed skins decorate panels
through a fixed layer whose mechanics live in `base.css` and whose only inputs
are paint (gradients), so the attacks the structural rule exists for (a fake
message over the page, an invisible box over a control) have no input to use.
Full description and roadmap: [ORNAMENTS.md](ORNAMENTS.md).

| Attack | Control | Tested by |
|---|---|---|
| A raised panel covering something | A panel (or widget) under the pointer, or holding an open menu, is raised to a fixed `z-index: 1035` written in `base.css`, so a card opened inside it is not trapped under the next panel; no token reaches `z-index`, and it stays below modals (1040+) | `OrnamentTest`, mutations "a raised panel that still sits under the sticky navbar", "that covers modals", "not raised when hovered" |
| Covering data with decoration | The layer is at `z-index: -1` inside an isolated stacking context: painted under the panel's content, so opaque content hides it. No token reaches `z-index` | `OrnamentTest` (rule pinned declaration by declaration), mutation "raise the layer above content" |
| Reaching into the panel | `clip-path` ring: 24px inside the edge, 8px outside, written in `base.css` | `OrnamentTest`, mutation "drop the safe-zone ring" |
| An invisible click target | `pointer-events: none` | `OrnamentTest`, mutation "let the layer take clicks" |
| Fake text | `content: ""`, fixed | `OrnamentTest`, mutation "give the layer text" |
| A large overhang over neighbours | `inset: -8px`, fixed | `OrnamentTest`, mutation "overhang by 80px" |
| A skin reading a structural token through the layer | The layer may read only the eight paint slots; any other `var()` fails the test | `OrnamentTest`, mutation "read a structural token in the layer" |
| Loading an image through a slot | Slots are image-kind tokens: gradients only, no `url()`, `image-set()` or escapes (same validator as every other value) | `OrnamentTest`, `evil-frames-url` |
| The same attacks through the heading, navbar and widget layers | Same fixed mechanics, same pinning: negative z-index in an isolated context, `pointer-events: none`, empty `content`, constant bands (8px / 12px, 8px overhang at the navbar's bottom). Widget frames are the widget's own background layers, so they can't leave it | `OrnamentTest`, five more mutations (heading marker raised, heading strip clickable, navbar strip with text, navbar bottom strip hanging 80px, a 300px widget slot) |
| Hiding content with a cut corner (`clip-path`) | `clip-path` stays structural except in one polygon written in `base.css`; the classifier recognises only that exact template. A skin supplies sizes: px only (no `%`, `em`, `calc()`, `var()`, bare `0`), at most 10px (buttons) or 6px (labels, badges), so each cut is a small triangle that can't reach the text. The steepness is a plain number from 0.5 to 2 (vertical legs at most twice the cap). Unset, the declaration is invalid and there is no clip | `OrnamentTest` (polygon written out independently; a dozen refused sizes), four mutations (accept `%`/`em`, no button cap, a cut that scales with width, clip by default) |
| A glow that paints over the page (a colour carrying a second, huge shadow) | The glow tokens take one literal colour by pattern: no list, no `var()` (a palette value could hold the comma), no function nesting. The radius (8px; 7px to 16px for the alert pulse) is fixed in `base.css` | `OrnamentTest` (glow colours, the palette route, a closed-function trick), mutations "a glow colour carrying a second shadow", "160px alert glow", "80px frame glow" |
| Flashing or fast motion | Periods take only `2s` to `60s` by pattern (the generic 5s ceiling doesn't apply, and a minimum is needed); only opacity and a shadow animate, at fixed easing; a reduced-motion rule turns all of it off | `OrnamentTest` (17 refused periods, the reduced-motion rule covers every animated selector inside `@media`), mutations "a period under 2s", "no reduced-motion rule" |
| Reaching the bundled skins' raw animation tokens | `animation` and `filter` are structural except in the fixed shapes; `--ts-navbar-after-animation` and the like stay closed | `OrnamentTest` |
| A cut that hides content on a panel or widget | Panel and widget cuts are one fixed slit-notch polygon written in `base.css` and recognised by the classifier; a skin supplies only sizes: px only (no `%`, `em`, `var()`, bare `0`), at most 12px, vertical legs at most twice that. Each removes only a small triangle at a corner, so it can't reach text. The edge line is the panel's own `::after` (inert, `pointer-events: none`, sized by the same cuts, falling back to `0px`) | `OrnamentTest` (both polygons and the overlay written out independently, catalog caps, refused sizes and colours), mutations "panel overlay takes clicks / extends 40px", "drop the size fallback", "widget clip cuts off what hangs out", "panel clip cuts off a dropdown", "no cap on panel cuts" |
| Dropdowns or dialogs cut off by a clip | The clip margin is 10000px, so nothing that hangs out of a panel or widget is clipped; only the corner triangles are | `OrnamentTest` (the margin), mutations on the margin; a browser check that a fixed child and a menu 150px below a clipped panel still receive clicks |
| The layer leaking into bundled skins | Rules are keyed on `data-ts-orn`, which uploaded skins' links carry and a bundled skin's carry only if its `features.json` (read from the package) asks | `test-upload.sh` ("a bundled skin's links are not"), `OrnamentTest`, `EffectsTest` |
| A skin that reaches the other mode, or a stylesheet that is for both | A skin is written for one mode: its root block uses that mode's one selector (`html.dark` or `html:not(.dark)`, exactly), the manifest's `mode` must agree, and a block for the other mode, a list, a descendant or any other selector is refused with the line. `OutputGuard` re-checks the finished stylesheet for exactly one root block of the mode it was told, and refuses an unknown mode | `ModesTest` (eight hostile light selectors, the mismatches both ways), `CssTest`, `test-upload.sh` (five hostile mode bundles over HTTP), mutations under "modes" |
| The mirror (a skin served for the other mode) carrying something the original didn't | It is made from the validated stylesheet by swapping one selector (`^html.dark {` or `^html:not(.dark) {` at the start of a line) and checked by `OutputGuard` as the stylesheet it is; a stylesheet that is not clearly for one mode has none and the skin then applies only in its own mode. An upload's two files are written, read back and hashed before being renamed into place | `ModesTest` (mirror round trip, both-wrapper and neither cases), `InstallerTest`, `test-upload.sh` (two files, mirror equals original with one selector swapped) |
| The light base or `base/light.css` carrying more than intended | The light twin is `base.css` with two substitutions, minus the blocks fenced `ts:dark-only` (the dark map's black attribution bar), and nothing else (a test undoes the substitutions and compares), and `light.css` is pinned: custom properties (`--tw-color-*` and the map filter default) in one block plus exactly two rules, with no position, size, display, content, z-index or url | `ModesTest` (`test_light_css`), mutations "the light base is the dark base", "published without the light-only mapping" |
| Picking the wrong skin for a graph, or one mode's choice changing the other | The slot is the request's own `style` (else the session's), each mode has its own preference and default, and a bad value in one field changes nothing (all fields are checked before any is saved) | `test-picker.sh`, `test-graphs.sh` (light and dark graphs, defaults, leaks) |
| A page layer that covers or blocks the page (the drifting "rain") | One fixed rule in `base.css`: `z-index: -1`, `pointer-events: none`, empty `content`, `position: fixed`, moved by `transform` only; no token reaches any of it. A skin gives an image (a gradient or a declared texture), a tile size (whole px, 64 to 512: no `%`, `em`, `var()`) and a period (2s to 60s). No background on `<html>`, so the layer sits on the canvas | `OrnamentTest` (rule pinned declaration by declaration, 16 refused tile sizes, 9 refused periods), mutations "raise the layer above content", "let the layer take clicks", "let the layer scroll with the page", "give the layer text", "accept a tile larger than 512px", "accept a tile in % or em", "keep moving under reduced motion", "paint `<html>` too" |
| A skin asking for more than it may (uploads) | `features.json` is read only for bundled skins, from the package: an uploaded skin's manifest refuses `ornaments` and `effects`, its zip refuses a `features.json`, and the file's own parser honours two keys and one effect name and ignores the rest | `EffectsTest`, `ZipTest`, mutations under "features:" |
| Page markup added by an effect (the white rabbit) | The markup is a reviewed file in the package, not skin data: no script, no URL, no link, `aria-hidden`, `pointer-events: none`, fixed to a corner below modals, plays once and fades to nothing, hidden under reduced motion. The chance and the page are decided in PHP, from a short allowlist of effect names | `EffectsTest` (markup checks, one roll in ten, device pages only), mutations under "effects:" |

### Licence notices

`LICENSE.txt` is the one free-text field in a bundle, so it is treated as
hostile text: it is stored in the database, never written to the web root, never
copied into the stylesheet, and shown only as escaped text inside `<pre>`.

| Attack | Control | Tested by |
|---|---|---|
| Script or markup in the notice (stored XSS) | Rendered with Blade's escaping in a `<pre>`; the notice is never echoed as HTML and never placed in CSS. Verified against the running server with a `<script>` payload | `test-upload.sh` ("shown escaped, never as markup") |
| Hiding or disguising text (bidi overrides, zero-width characters, terminal escapes, NUL) | Only letters, marks, digits, punctuation, symbols, spaces, tab and newline are accepted; control, format, private-use and unassigned characters and invalid UTF-8 are errors | `LicenseTest` (18 refused cases), mutation "accept control, invisible and spoofing characters" |
| A notice used to deliver a file (`license.php`, `LICENSE.txt.php`, `fonts/LICENSE.txt`) | Exact-name allowlist. The notice is data in the database, so there is nothing to execute or fetch | `LicenseTest` (near-miss names), `evil-license-name`, mutation |
| An oversized notice | 20 KB cap, checked on the declared size and while inflating | `LicenseTest`, mutations |
| Empty notices, or a notice that silently replaces a good one | Empty text is an error; a refused bundle changes nothing | `LicenseTest`, `test-upload.sh` |

### Graph settings

`graph.conf` values reach LibreNMS config and RRDtool's command line, so they
are held to exact shapes: `-c NAME#RRGGBB[AA]` pairs with `NAME` from
RRDtool's fixed set; six hex digits; JSON arrays of six-digit hex colours. Any
other key or shape is rejected. The same validator re-checks a palette read
back from the database before it is written to config. (`MiscTest`, mutation
"accept unknown RRDtool colour tags".)

### Installation and removal

| Attack or failure | Control | Tested by |
|---|---|---|
| A half-written skin | Written to a staging directory, read back and hashed, then renamed into place. Replacing is a rename first and a removal second, with rollback if the database write fails | `InstallerTest`, `test-upload.sh`, mutations |
| Overwriting or removing a bundled skin | Bundled ids can't be taken or removed; a later bundled skin never touches an uploaded skin's directory | `InstallerTest`, `test-upload.sh`, mutation "allow bundled ids" |
| Steering removal outside the skins directory | Ids are a strict slug (re-checked in the installer and constrained in the route); removal deletes links, never follows them, and refuses paths that resolve outside; verified with symlinked directories and symlinks inside a skin | `InstallerTest`, `test-upload.sh`, mutation "drop all three link protections" |
| A directory that turns up in the web root some other way becoming a skin | A skin is bundled or has a registry row; the row is what makes a directory a skin | `test-upload.sh` ("a directory nobody registered") |
| Two installs at once | A lock file serialises installs and removals | by construction (can't be observed without concurrency; marked redundant in the mutation check) |
| An update's publish deleting or overwriting the wrong thing | The list of skins to remove after an update comes from a file in the web root (`.bundled.json`), so each name in it must be a valid skin id (a slug, never a path) before anything is removed; uploaded ids are compared as strings (an all-digit id is an integer key in PHP); a bundled skin never writes into an uploaded skin's directory | `StatusTest` (a marker naming `..`, `../..`, a path; an all-digit id), mutations "the marker may name any path to remove", "an all-digit id is not recognised" |
| A skin uploaded before the light/dark update having no mirror | On the first request after an update the publisher makes it from the installed stylesheet, only if neither the link nor the file is a symlink, only if the stylesheet and its mirror both pass `OutputGuard` for their modes, and only if no mirror has appeared since (it takes no lock: a re-upload in the same instant could have its mirror replaced by one made from the skin it replaced, which is still validated CSS) | `ModesTest` (made; left alone if there; refused if it would not pass the installer), three mutations |
| Too many uploaded skins | At most 50 at once; replacing one is always allowed | `InstallerTest`, mutation "no limit on uploaded skins" |

### Access and request handling

| Attack | Control | Tested by |
|---|---|---|
| A non-admin uploading or deleting | Routes are behind LibreNMS's `admin` role: 403 | `test-upload.sh` |
| CSRF | Routes are POST-only in the `web` group; missing or forged tokens get 419 | `test-upload.sh` |
| Upload floods | `throttle:12,1` on upload, `throttle:30,1` on delete | `test-upload.sh` |
| Trusting the client's claims | The uploaded file's name and declared type are never used; the content is read from PHP's own temp file (by the path PHP gives, never the client's name) and never moved | `test-upload.sh` (uploaded as `evil.php`, `image/png`: installs, nothing named that exists) |
| Injection through displayed text | Names, descriptions and authors are restricted to plain printable text *and* escaped on output; anything from the bundle that appears in an error message goes through `Report::quote` | `MiscTest`, `test-upload.sh` |
| The preview page showing something other than what was asked, or leaking | `GET plugin/theme-selector/preview/{id}` is behind `web` + `auth`, `id` is a slug that must be an installed skin or `none` (anything else is 404). The skin is named to the injector by a request attribute the controller sets after that check, never by a query string, and the visitor's own preference is never read or written. The page is sample content on LibreNMS's real layout (so the navbar shows the signed-in user's own name and menus, nothing else of theirs; no database rows), its graph is built from numbers and hex-validated colours, and effects are never added. `?mode=` is `light` or anything else for dark, and `?theme-selector=off` shows stock, as on any page | `test-picker.sh` (every bundled skin, seven refused ids, query-string cannot steer, own skin unchanged), `PreviewTest`, mutations under "preview" |
| A skin that makes pages unusable | Installing changes nobody's view; a user (or admin) opts in. `?theme-selector=off` on any page shows it with no skin | `test-upload.sh` |
| Untraceable changes | A rejected upload (one the validator refuses), an install, a replacement, a removal, a change of the instance defaults and each package update (`ThemeSelector: updated from ... to ...`) are logged at warning level (LibreNMS's default, so it needs no configuration) to `logs/librenms.log`, with the user and IP where there is one, and the bundle's SHA-256 for uploads. **Not logged:** a refusal by the installer after validation (the id belongs to a bundled skin, the 50-skin limit, an unwritable directory), a refused removal, and the SHA-256 of a removed skin | `test-upload.sh` (reads the log of a stock instance), `dev/test-update.sh` (the update line) |

---

## What is *not* defended

Say these plainly rather than imply they are handled.

1. **A valid skin can still be misleading.** Within the allowed tokens a skin
   controls colour, so it could make a "down" status look green, or make text
   hard to read. It also controls, within bounds, type size and spacing (a
   font size of `0px` blanks table text), decoration, filters on buttons and the
   map, motion within the ornament rules, and graph colours: a palette can be
   chosen that makes graphs hard to read, and as the instance default it is
   written to LibreNMS's config and used by every graph, alert emails included
   (only `graph_colours.*` and four chrome keys are reachable, never any other
   setting). That is inherent to letting someone choose colours. What
   limits it: nothing changes for anyone until they choose the skin or an admin
   makes it the default; the escape hatch; easy removal; the audit log. There
   is no automatic contrast check on uploads yet, so review a skin in the
   picker before making it the default. Treat installing a skin like installing
   any third-party code you have not read.
2. **Structural features are bundled-only.** That is a security decision. The
   31 structural tokens (position, size, offsets, `z-index`, generated text,
   arbitrary `clip-path` and `animation`), `features.json` and page effects (the
   white rabbit) are closed to uploads. Uploads do get the *vetted* decoration
   (frame brackets, cut corners, glow and breathing motion, a drifting page
   layer: [ORNAMENTS.md](ORNAMENTS.md)), each with its mechanics fixed in
   `base.css`; what is still bundled-only is listed in ROADMAP.md.
3. **Font parsing happens in the browser.** A malformed font is handled by the
   browser's own font sanitiser, reachable only through an inert `data:` URL.
   The checks here reduce what reaches it; they don't parse font tables.
4. **The zip reader is our own code.** That trades a library's history for
   code we can read end to end, and it is tested against hostile archives,
   fuzzed and mutation-checked, but a parser bug is possible. Its failure mode
   is rejecting a good bundle or accepting a bad one that the later stages then
   still refuse; it never writes anything itself.
5. **Bundled skins are trusted.** They are reviewed source in this repository
   and go through the same parser in bundled mode (and the tests check that
   they do), but they may use structural tokens.
6. **Denial of service is bounded, not prevented.** An admin can upload up to
   the rate limit, each bundle is capped, and the number of uploaded skins is
   capped; a determined admin can still use tens of megabytes of disk.
7. **Web-server configuration is not ours.** The design doesn't depend on
   PHP being unable to run in the web root (nothing uploaded is executable
   text there), but a host that serves `html/css/custom/` with unusual rules is
   outside what this plugin can check. Keep `cgi.fix_pathinfo=0` as LibreNMS
   recommends.
8. **LibreNMS core is trusted** for authentication, sessions and CSRF.
9. **Automatic updates trust the update source.** Installed as `dev-main`, every
   host that follows it runs, after the next nightly `daily.sh`, whatever is on
   `main` of the GitHub repository: PHP executed by the web server and the
   console, with no review on the host. Whoever can push to that branch, or
   takes over the account, controls every such host. What limits it: protect the
   account (two-factor authentication) and the branch (require a pull request,
   no force pushes); follow release tags (`plugin:add ... '^1.0'`) or pin a
   version (`plugin:add ... 1.2.0`) on hosts that should only take what someone
   there has chosen; read `ThemeSelector: updated from ... to ...` in the log.
   The plugin itself never runs Composer, `git` or any network request: updating
   is LibreNMS's `daily.sh` and `lnms plugin:add`, both started by an
   administrator or the scheduler.
10. **A failed nightly `composer require` removes the plugin until it is put back.**
    It needs Composer to have no cached copy of the repository (or the API-mode
    entry, or a bad commit on `main`); with the `no-api` entry and a warm cache an
    unreachable GitHub is survived. `scripts/ensure-installed.sh` is the optional
    safety net; the mechanism is in [DEPLOYMENT.md](DEPLOYMENT.md#updates).
    Availability, not security: pages go stock and per-user graph colours go. The
    safety net runs as the unprivileged `librenms` user from a root-owned copy, does
    nothing unless the plugin is listed in `composer.plugins.json`, and runs only
    the commands an administrator would.
11. **Framing.** The plugin sets no `X-Frame-Options` or CSP, and neither does
    LibreNMS at the version checked, so its pages, the picker's admin forms
    included, can be framed by another site unless the web server adds headers.
    Browsers' `SameSite=Lax` default keeps the session cookie out of a
    cross-site frame, and every state change needs a CSRF token. If you add
    `X-Frame-Options: DENY` at a proxy, the picker's own preview frames go
    blank: use `SAMEORIGIN` (or `frame-ancestors 'self'`).
12. **The skin registry fails open.** If the database can't be read on the
    first request after an update, the publisher sees no uploaded skins, so the
    guard that stops a bundled skin writing into an uploaded skin's directory
    (a later package version that ships a skin whose id an admin has already
    uploaded) doesn't apply for that one run. An id that is bundled *now* is refused
    at install; this is about one a later release adds.
13. **Not transactional.** Saving a choice or a default validates every field
    first, then writes them one after another; a database failure between two
    writes leaves the first made.

---

## Privacy

What this plugin collects, stores and sends, in full:

- **Nothing leaves the host, and no visitor's browser contacts anyone else.** The
  stylesheets have no `@import` and no remote `url()` (fonts and textures are bundled, or
  embedded as `data:` URLs); the views load no script, image or font from elsewhere; the
  preview is a same-origin frame. The plugin does not change where the map's tiles
  come from (LibreNMS's own setting does that). Developer tooling that fetches fonts
  (`scripts/fetch-fonts.ps1`) is run by a developer, once, not by a host.
- **Per user:** two preferences in `users_prefs`, `theme_selector.skin` and
  `theme_selector.skin_light`, holding a skin id. Nothing else is stored about
  a person, and neither is shown to anyone else.
- **Per uploaded skin:** the numeric id of the admin who installed it (`installed_by`,
  never displayed) and timestamps.
- **The log:** an admin's username (or id) and IP address on each upload, install,
  replacement, removal and change of the defaults, at warning level in
  `logs/librenms.log`, kept as long as LibreNMS's log rotation keeps it. The IP is the
  proxy's unless LibreNMS is configured to trust forwarded headers. This is
  personal data about administrators; the retention is the log's.
- **Removing it:** the uninstall steps in [DEPLOYMENT.md](DEPLOYMENT.md#uninstall) delete
  the preferences, tables and rows. Log lines age out with the log.
- **This repository is public.** Skins' screenshots are captures of an offline mockup
  with invented hostnames, interfaces, sites and numbers; the documents name no
  deployment. The commit history carries whatever author address each commit
  was made with: use GitHub's `noreply` address (Settings, Emails, "Keep my email
  addresses private" and "Block command line pushes that expose my email"), and
  remember that merges made in GitHub's web interface use the account's address
  unless that setting is on.

---

## Operating it

- `html/css/custom/theme-selector/` must be writable by the web server user (the plugin
  republishes it on web requests after an update); `lnms theme-selector:publish` checks that
  the user running it can write there, which on a standard install is the same `librenms`
  user php-fpm runs as.
- Check a bundle without installing it: `lnms theme-selector:validate my-skin.zip`.
- Check that the plugin is installed, current and will survive `daily.sh`: `lnms theme-selector:status`.
- To remove everything the plugin added: see DEPLOYMENT.md, "Uninstall".
- Look for `ThemeSelector:` lines in `/opt/librenms/logs/librenms.log`.
- Limits are constants in `src/Skin/Limits.php`; the bundle format is
  `docs/AUTHORING.md`.
