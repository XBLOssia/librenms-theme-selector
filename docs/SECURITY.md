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
ever a file in the web root. The only file written for an uploaded skin is one
`skin.css` of printable ASCII, and it is checked again by a second, independent
guard before it is written.

---

## Controls, and the test that would notice each one breaking

`php tests/run.php` runs 1,952 checks; `sh tests/mutate.sh` breaks each defence
on a scratch copy and requires a failing test (107 flaws caught, 7 documented as
redundant layers, 0 missed); `dev/test-upload.sh` drives the real endpoints.
(Counts as of 2026-10-02; `sh dev/test.sh all` prints the current ones.)
Run all of it with `sh dev/test.sh all`.

### The archive

| Attack | Control | Tested by |
|---|---|---|
| Zip-slip (`../evil.php`, absolute paths, backslashes, drive letters, NUL) | Nothing is extracted and no path is built from an entry name. Entry names must match an exact allowlist (`skin.json`, `skin.css`, `graph.conf`, `LICENSE.txt`, `fonts/<slug>.woff2\|woff`, `textures/<slug>.png`), matched with `\z` so a trailing newline can't slip through | `ZipTest` (46 hostile names), mutation "match with `$`" |
| A `.php`, `.htaccess`, `.svg`, nested zip or anything else in the bundle | Any entry outside the allowlist rejects the *whole* bundle, not just that entry | `ZipTest`, `evil-php-entry`, `evil-htaccess`, `evil-nested-zip` |
| Symlink or device entries | Unix mode bits checked; only regular files and directories | `ZipTest`, mutation "accept symlink entries" |
| Decompression bomb | Inflation counts output as it is produced and stops at the declared size; declared sizes are capped per file and in total | `ZipTest` (40 MB bomb stopped with < 20 MB memory), mutation "no cap while inflating" |
| An entry that shows different content to different readers | The central directory and each local header are cross-checked (name, method, sizes, CRC); one end record only; no gap or trailing data; overlapping entries refused; CRC verified | `ZipTest`, three mutations |
| Encryption, ZIP64, multi-part, exotic compression | Refused | `ZipTest` |
| Too many entries or fonts, huge files | Limits in `Limits.php` | `ZipTest` |
| Reliance on a zip library's path handling | The reader is our own and needs only ext-zlib (which LibreNMS requires), not ext-zip | by construction |

### The stylesheet

| Attack | Control | Tested by |
|---|---|---|
| Loading something from elsewhere (`url()`, `@import`, `image-set`, `element()`, `src()`, `attr()`, `env()`) | A value is tokenised and every token must be on a short allowlist; functions are an explicit list without any of those. `@import` and every other at-rule are refused | `CssTest` (well over a hundred cases) and the `test_values` group, which tests the value validator on its own; mutation "allow url() as a function" |
| Breaking out of a value (`}` `;` `{`, comments, `!important`) | The file is scanned by a character-level parser, not a regex; `{` in a value is an error; `/*` in a value is an error | `CssTest`, mutations |
| Backslash escapes (`u\72l(`) | `\` is not in the allowed character set | `CssTest` (`test_values`) |
| Styling arbitrary elements | Only `html.dark { ... }` and `@font-face` blocks are accepted, and inside them only custom properties. No other selector, no other property | `CssTest`, mutation "accept any selector" |
| Overwriting core's variables | Only known `--ts-*` tokens and the skin's own `--p-*` palette; `--tw-*` and everything else is refused | `CssTest`, mutation "accept unknown tokens" |
| Fake UI: overlay text, invisible click targets, oversized boxes | The 31 **structural** tokens (position, sizes, offsets, `z-index`, `pointer-events`, `content`, `clip-path`, animation, margin) can't be set by an upload. The list is derived from how `base.css` uses each token, and anything unrecognised is structural (deny by default) | `CssTest`, `MiscTest` (catalog vs `base.css`), mutation "let uploads set structural tokens" |
| Huge values to cover the page (giant shadow blur, enormous padding) | Numeric bounds per token kind (shadows 100 px, borders 24 px, lengths 64 px, everything else 800 px; filters, durations and percentages bounded) | `CssTest` (`test_values`), mutations |
| Getting round a token's cap through the palette | A palette entry used by a token is checked against that token's cap, transitively | `CssTest`, mutation "let the palette bypass a token's cap" |
| Filters that hide controls (`blur`, `opacity`, `drop-shadow`) | Only `brightness contrast saturate sepia hue-rotate invert grayscale`, each with one bounded argument | `CssTest` |
| Anything the parser lets through by mistake | `OutputGuard` re-checks the finished text with no knowledge of how it was produced: ASCII only, exactly one `html.dark` block, one `@font-face` per font, exactly one `url(` per font and each a base64 `data:` font URL (plus the `data:image/png` URLs of textures, below), no angle brackets, backslashes or scriptable schemes | `CssTest` (guard cases), mutation "guard: ignore stray url(" |
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
| The layer leaking into bundled skins | Rules are keyed on `data-ts-orn`, which only uploaded skins' links carry | `test-upload.sh` ("a bundled skin's links are not"), `OrnamentTest` |

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
| Two installs at once | A lock file serialises them | by construction (can't be observed without concurrency; marked redundant in the mutation check) |
| Too many uploaded skins | At most 50 at once; replacing one is always allowed | `InstallerTest`, mutation "no limit on uploaded skins" |

### Access and request handling

| Attack | Control | Tested by |
|---|---|---|
| A non-admin uploading or deleting | Routes are behind LibreNMS's `admin` role: 403 | `test-upload.sh` |
| CSRF | Routes are POST-only in the `web` group; missing or forged tokens get 419 | `test-upload.sh` |
| Upload floods | `throttle:12,1` on upload, `throttle:30,1` on delete | `test-upload.sh` |
| Trusting the client's claims | The uploaded file's name and declared type are never used; the content is read from PHP's temp file and never moved or opened by path | `test-upload.sh` (uploaded as `evil.php`, `image/png`: installs, nothing named that exists) |
| Injection through displayed text | Names, descriptions and authors are restricted to plain printable text *and* escaped on output; anything from the bundle that appears in an error message goes through `Report::quote` | `MiscTest`, `test-upload.sh` |
| A skin that makes pages unusable | Installing changes nobody's view; a user (or admin) opts in. `?theme-selector=off` on any page shows it with no skin | `test-upload.sh` |
| Untraceable changes | Every rejected upload, install, replacement and removal is logged at warning level (LibreNMS's default, so it needs no configuration) to `logs/librenms.log`, with the user, IP and the bundle's SHA-256 | `test-upload.sh` (reads the log of a stock instance) |

---

## What is *not* defended

Say these plainly rather than imply they are handled.

1. **A valid skin can still be misleading.** Within the allowed tokens a skin
   controls colour, so it could make a "down" status look green, or make text
   hard to read. That is inherent to letting someone choose colours. What
   limits it: nothing changes for anyone until they choose the skin or an admin
   makes it the default; the escape hatch; easy removal; the audit log. There
   is no automatic contrast check on uploads yet, so review a skin in the
   picker before making it the default. Treat installing a skin like installing
   any third-party code you have not read.
2. **Structural features are bundled-only.** That is a security decision, and
   it means uploaded skins can't have decorative corner brackets, animation or
   generated text. A future version could offer named, vetted features
   instead.
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

---

## Operating it

- `html/css/custom/theme-selector/` must be writable by the web server user (the plugin
  republishes it on web requests after an update); `lnms theme-selector:publish` checks that
  the user running it can write there, which on a standard install is the same `librenms`
  user php-fpm runs as.
- Check a bundle without installing it: `lnms theme-selector:validate my-skin.zip`.
- To remove everything the plugin added: see DEPLOYMENT.md, "Uninstall".
- Look for `ThemeSelector:` lines in `/opt/librenms/logs/librenms.log`.
- Limits are constants in `src/Skin/Limits.php`; the bundle format is
  `docs/AUTHORING.md`.
