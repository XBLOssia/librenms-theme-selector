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

`php tests/run.php` runs 1,085 checks; `sh tests/mutate.sh` breaks each defence
on a scratch copy and requires a failing test (39 flaws caught, 7 documented as
redundant layers, 0 missed); `dev/test-upload.sh` drives the real endpoints.
Run all of it with `sh dev/test.sh all`.

### The archive

| Attack | Control | Tested by |
|---|---|---|
| Zip-slip (`../evil.php`, absolute paths, backslashes, drive letters, NUL) | Nothing is extracted and no path is built from an entry name. Entry names must match an exact allowlist (`skin.json`, `skin.css`, `graph.conf`, `fonts/<slug>.woff2\|woff`), matched with `\z` so a trailing newline can't slip through | `ZipTest` (48 hostile names), mutation "match with `$`" |
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
| Fake UI: overlay text, invisible click targets, oversized boxes | The 36 **structural** tokens (position, sizes, offsets, `z-index`, `pointer-events`, `content`, `clip-path`, animation, margin) can't be set by an upload. The list is derived from how `base.css` uses each token, and anything unrecognised is structural (deny by default) | `CssTest`, `MiscTest` (catalog vs `base.css`), mutation "let uploads set structural tokens" |
| Huge values to cover the page (giant shadow blur, enormous padding) | Numeric bounds per token kind (shadows 100 px, borders 24 px, lengths 64 px, everything else 800 px; filters, durations and percentages bounded) | `CssTest` (`test_values`), mutations |
| Getting round a token's cap through the palette | A palette entry used by a token is checked against that token's cap, transitively | `CssTest`, mutation "let the palette bypass a token's cap" |
| Filters that hide controls (`blur`, `opacity`, `drop-shadow`) | Only `brightness contrast saturate sepia hue-rotate invert grayscale`, each with one bounded argument | `CssTest` |
| Anything the parser lets through by mistake | `OutputGuard` re-checks the finished text with no knowledge of how it was produced: ASCII only, exactly one `html.dark` block, one `@font-face` per font, exactly one `url(` per font and each a base64 `data:` font URL, no angle brackets, backslashes or scriptable schemes | `CssTest` (guard cases), mutation "guard: ignore stray url(" |
| The output as a whole | A mutation **fuzzer** (thousands of corrupted and injected variants per run) checks that whatever is accepted satisfies the invariants above | `FuzzTest` |

### Fonts

| Attack | Control | Tested by |
|---|---|---|
| A script, SVG or HTML renamed `.woff2` | Signature must match the extension | `MiscTest`, mutation "skip the signature check" |
| A real font with a payload appended (polyglot) | WOFF and WOFF2 declare their own length; it must equal the file size | `MiscTest`, mutation |
| PHP or script inside the font bytes | Scanned for `<?php`, `<?=`, `<? `, `<script`, `<%` | `MiscTest`, mutation |
| A font file executed through a path-info trick (`.../font.woff2/x.php`) | There are no font files: fonts exist only inside the generated CSS as base64, which contains no `<`. Verified against the running server | `test-upload.sh` ("path-info trick") |

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
| Untraceable changes | Every rejected upload, install, replacement and removal is logged with the user, IP and the bundle's SHA-256 | (log lines) |

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

- `html/css/custom/theme-selector/` must be writable by the web server user;
  `lnms theme-selector:publish` checks that.
- Check a bundle without installing it: `lnms theme-selector:validate my-skin.zip`.
- To remove everything the plugin added: see DEPLOYMENT.md, "Uninstall".
- Look for `ThemeSelector:` lines in `storage/logs/librenms.log`.
- Limits are constants in `src/Skin/Limits.php`; the bundle format is
  `docs/AUTHORING.md`.
