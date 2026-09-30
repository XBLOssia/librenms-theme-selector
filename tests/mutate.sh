#!/bin/sh
# Mutation check: is the test suite actually able to catch a broken defence?
#
#   sh tests/mutate.sh          (from the repo root, where PHP is available)
#
# For each entry below, apply one deliberate flaw to a scratch COPY of the
# validator (never the real source), run the suite, and require that it fails.
# A flaw the suite doesn't notice means a defence with no test behind it.
#
# Some flaws are REDUNDANT: the layer they remove sits in front of another layer
# that stops the same input anyway, so no test can tell the difference (that is
# what defence in depth means). Those are marked `redundant`; the check still
# fails if a redundant one is in fact caught (mislabelled) or a normal one missed.
set -u

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
WORK="$(mktemp -d)"
trap 'rm -rf "$WORK"' EXIT
export FUZZ_ROUNDS=600

run_with() { # file, sed expression, description [redundant]
  rm -rf "$WORK/t"; mkdir -p "$WORK/t"
  cp -r "$ROOT/src" "$ROOT/tests" "$ROOT/resources" "$ROOT/examples" "$ROOT/skins" "$ROOT/base" "$WORK/t/"
  before="$(md5sum "$WORK/t/$1" | cut -d' ' -f1)"
  sed -i "$2" "$WORK/t/$1"
  after="$(md5sum "$WORK/t/$1" | cut -d' ' -f1)"
  if [ "$before" = "$after" ]; then
    printf '  ??    %-62s (mutation did not apply)\n' "$3"; UNAPPLIED=$((UNAPPLIED + 1)); return
  fi
  out="$(php "$WORK/t/tests/run.php" 2>&1)"
  failed="$(printf '%s' "$out" | sed -n 's/^[0-9]* passed, \([0-9]*\) failed$/\1/p')"
  if [ -z "$failed" ]; then failed="crash"; fi
  if [ "$failed" = "0" ] && [ "${4:-}" = "redundant" ]; then
    printf '  redundant %-58s (another layer stops it)\n' "$3"; REDUNDANT=$((REDUNDANT + 1))
  elif [ "$failed" = "0" ]; then
    printf '  MISSED %-61s\n' "$3"; MISSED=$((MISSED + 1))
  elif [ "${4:-}" = "redundant" ]; then
    printf '  MISLABELLED %-56s (%s failing: not redundant after all)\n' "$3" "$failed"; MISSED=$((MISSED + 1))
  else
    printf '  caught %-61s (%s failing)\n' "$3" "$failed"; CAUGHT=$((CAUGHT + 1))
  fi
}

CAUGHT=0; MISSED=0; UNAPPLIED=0; REDUNDANT=0
echo "Baseline:"; php "$ROOT/tests/run.php" 2>&1 | tail -2 | head -1
echo
echo "Deliberate flaws:"

V=src/Skin/ValueValidator.php
Z=src/Skin/ZipBundleReader.php
T=src/Skin/TokenFile.php
F=src/Skin/FontFile.php
G=src/Skin/OutputGuard.php
M=src/Skin/Manifest.php
C=src/Skin/GraphConf.php

run_with $V "s/'var',\$/'var', 'url',/" "allow url() as a function"
run_with $V "s|if (preg_match('/\[^A-Za-z0-9 _.,#()|if (false \&\& preg_match('/[^A-Za-z0-9 _.,#()|" "skip the value character allowlist" redundant
run_with $V "s/'%' => \$num >= -1000/'%' => true || \$num >= -1000/" "unbound percentages"
run_with $V "s/'px' => abs(\$num) <= \$maxPx/'px' => true/" "unbound px lengths"
run_with $V "s/if (\$i < \$n && \$value\[\$i\] === '(') {/if (false) {/" "treat every function as a plain word"
run_with $V "s/if (! \$allowStrings && ! \$trusted) {/if (false) {/" "allow strings in every token"
run_with $V "s/if (str_contains(\$value, '\/\*') || str_contains(\$value, '\*\/')) {/if (false) {/" "allow comments inside values"
run_with $V "s/if (! \$trusted) {\$/if (false) {/" "allow arithmetic and bare groups in uploads"
run_with $Z "s/(skin\\\\.json|skin\\\\.css|graph\\\\.conf|fonts\/\[A-Za-z0-9\]\[A-Za-z0-9_-\]{0,63}\\\\.(woff2|woff))\\\\z#D/&/;s/\\\\z#D';/\$#';/" "match entry names with \$ instead of \\z"
run_with $Z "s/if (strlen(\$out) > \$expected) {/if (strlen(\$out) > 999999999) {/" "no cap while inflating (decompression bomb)"
run_with $Z "s/if (\$type !== 0 \&\& \$type !== 0100000 \&\& \$type !== 0040000) {/if (false) {/" "accept symlink entries"
run_with $Z "s/if (\$localName !== \$name/if (false \&\& \$localName !== \$name/" "skip the local-header name cross-check"
run_with $Z "s/if (hexdec(hash('crc32b', \$out)) !== \$h\['crc'\]) {/if (false) {/" "skip the CRC check"
run_with $Z "s/if (\$ranges\[\$i\]\[0\] < \$ranges\[\$i - 1\]\[1\]) {/if (false) {/" "skip the overlap check"
run_with $Z "s/if (substr_count(\$data, \"PK\\\\x05\\\\x06\") !== 1) {/if (false) {/" "allow two end-of-directory records"
run_with $Z "s/if (! \$isDir \&\& ! preg_match(self::NAME, \$name)) {/if (false) {/" "skip the entry-name allowlist"
run_with $T "s/if (\$upload \&\& \$this->catalog->isStructural(\$name)) {/if (false) {/" "let uploads set structural tokens"
run_with $T "s/if (\$selector !== 'html.dark') {/if (false) {/" "accept any selector"
run_with $T "s/if (! \$this->catalog->has(\$name)) {/if (false) {/" "accept unknown --ts tokens"
run_with $T "s/if ((\$private\[\$p\] ?? 0) > \$cap) {/if (false) {/" "let the palette bypass a token's px cap"
run_with $T "s/if (\$m\[0\] !== '@font-face') {/if (false) {/" "accept any at-rule"
run_with $T "s/if (\$this->mode === Mode::Upload \&\& ! OutputGuard::safe/if (false \&\& ! OutputGuard::safe/" "skip the final output guard" redundant
run_with $T "s/if (! isset(\$fonts\[\$file\])) {/if (false) {/" "allow fonts that aren't in the bundle"
run_with $F "s/if (\$h\['length'\] !== \$len) {/if (false) {/" "skip the font declared-length check"
run_with $F "s/if (substr(\$bytes, 0, 4) !== \$magic) {/if (false) {/" "skip the font signature check"
run_with $F "s/if (stripos(\$bytes, '<?php') !== false/if (false \&\& stripos(\$bytes, '<?php') !== false/" "skip the PHP-in-font scan"
run_with $G "s/if (substr_count(strtolower(\$css), 'url(') !== \$fonts + \$textures) {/if (false) {/" "guard: ignore stray url("
run_with $G "s/foreach (\['<', '>', /foreach ([/" "guard: allow angle brackets"
run_with $M "s/} elseif (in_array(\$id, self::RESERVED_IDS, true)) {/} elseif (false) {/" "allow reserved skin ids"
run_with $M "s/if (\$modes !== \['dark'\]) {/if (false) {/" "allow non-dark modes"
run_with $C "s/|| ! in_array(\$m\[1\], self::COLOUR_TAGS, true)//" "accept unknown RRDtool colour tags"

I=src/SkinInstaller.php
run_with $I 's#count($this->registry->all()) >= self::MAX_UPLOADED#false#' "install: no limit on uploaded skins"
run_with $I 's#if (is_link($target) || (file_exists($target) \&\& ! is_dir($target))) {#if (false) {#' "install: write through a symlink or over a file"
run_with $I 's#if (! SkinRepository::isValidId($id) || in_array($id, Manifest::RESERVED_IDS, true)) {#if (false) {#' "install: skip the id re-check"
run_with $I 's#if ($this->skins->isBundled($id)) {#if (false) {#' "install/remove: allow bundled ids"
run_with $I 's#$this->removeTree($skinsDir . ./. . $id);#;#' "install: keep the directory when saving the row fails"
run_with $I 's#rename($old, $skinsDir . ./. . $id);#;#' "install: do not restore the old skin after a failure"
run_with $I 's#$this->default->set(null);#;#' "remove: do not clear a default that is being deleted"
run_with $I 's#if ($this->registry->find($id) === null) {#if (false) {#' "remove: allow ids that were never uploaded"
run_with $I 's#if (@filemtime($leftover) < time() - 3600) {#if (true) {#' "sweep: delete in-progress staging directories too"
run_with $I 's#if (is_dir($child) \&\& ! is_link($child)) {#if (is_dir($child)) {#' "removal: descend into symlinked directories (entry check still unlinks them)" redundant
run_with $I 's#if (is_dir($child) \&\& ! is_link($child)) {#if (is_dir($child)) {#;s#if (is_link($path)) {#if (false) {#;s#! str_starts_with($real, $root . DIRECTORY_SEPARATOR)#false#' "removal: drop ALL THREE link protections (deletion would follow links out)"
run_with $I 's#if (hash_file(.sha256., $file) !== hash(.sha256., $skin->css)) {#if (false) {#' "install: skip the read-back check of the written file" redundant
run_with $I 's#! str_starts_with($real, $root . DIRECTORY_SEPARATOR)#false#' "removal: drop the containment check (links are handled first)" redundant
run_with $I 's#if (is_link($path)) {#if (false) {#' "removal: drop the top-level link check (remove() unlinks first)" redundant
run_with $I 's#|| ! flock($handle, LOCK_EX)#|| false#' "install: skip taking the lock (needs concurrency to observe)" redundant

L=src/Skin/LicenseText.php
run_with $L 's#if (preg_match(.*p{L}.*#if (false) {#' "licence: accept control, invisible and spoofing characters"
run_with $L 's#if (! mb_check_encoding($raw, .UTF-8.)) {#if (false) {#' "licence: accept invalid UTF-8"
run_with $L 's#if (strlen($raw) > Limits::LICENSE_BYTES) {#if (false) {#' "licence: no size limit"
run_with $L 's#if (trim($text) === ..) {#if (false) {#' "licence: accept an empty notice"
run_with src/Skin/ZipBundleReader.php 's#LICENSE..txt|##' "zip: LICENSE.txt is not an allowed entry"
run_with src/Skin/ZipBundleReader.php "s#\$name === 'LICENSE.txt' => Limits::LICENSE_BYTES,##" "zip: no LICENSE.txt size limit (falls back to the font limit)"
run_with src/Skin/SkinCompiler.php "s#if (isset(\$files\['LICENSE.txt'\])) {#if (false) {#" "compiler: ignore the LICENSE.txt entry"

B=base/base.css
R='/data-ts-orn\]) \.panel::before {/,/^}/'
run_with $B "$R s/z-index: -1;/z-index: 2;/" "ornaments: raise the layer above content"
run_with $B "$R s/pointer-events: none;/pointer-events: auto;/" "ornaments: let the layer take clicks"
run_with $B "$R s/inset: -8px;/inset: -80px;/" "ornaments: overhang by 80px"
run_with $B "$R s/content: \"\";/content: \"Session expired\";/" "ornaments: give the layer text"
run_with $B "$R{/clip-path:/d}" "ornaments: drop the safe-zone ring"
run_with $B "$R s/background-image: var(--ts-frame-tl)/background-image: var(--ts-panel-before-width), var(--ts-frame-tl)/" "ornaments: read a structural token in the layer"

RH='/data-ts-orn\]) \.panel > \.panel-heading::before {/,/^}/'
RA='/data-ts-orn\]) \.panel > \.panel-heading::after {/,/^}/'
RN='/data-ts-orn\]) \.navbar-default::before {/,/^}/'
RB='/data-ts-orn\]) \.navbar-default::after {/,/^}/'
RW='/data-ts-orn\]) \.grid-stack \.grid-stack-item-content {/,/^}/'
run_with $B "$RH s/z-index: -1;/z-index: 2;/" "ornaments: raise the heading marker above the heading text"
run_with $B "$RA s/pointer-events: none;/pointer-events: auto;/" "ornaments: let the heading strip take clicks"
run_with $B "$RN s/content: \"\";/content: \"Session expired\";/" "ornaments: give the navbar top strip text"
run_with $B "$RB s/bottom: -8px;/bottom: -80px;/" "ornaments: let the navbar bottom strip hang 80px"
run_with $B "$RW s/background-size: 32px 32px,/background-size: 300px 300px,/" "ornaments: make a widget corner slot 300px"

RC='/data-ts-orn\]) \.btn {/,/^}/'
run_with src/Skin/TokenFile.php "s#&& ! preg_match('/^\[0-9\]{1,2}#\&\& false \&\& ! preg_match('/^[0-9]{1,2}#" "chamfer: accept % and em sizes"
run_with resources/token-catalog.json '/"--ts-btn-chamfer": {/,/}/ s/"maxPx": 10/"maxPx": 800/' "chamfer: no cap on button cuts"
run_with $B "$RC s/calc(100% - var(--ts-btn-chamfer-tr/calc(50% - var(--ts-btn-chamfer-tr/" "chamfer: a button cut that scales with its width"
run_with src/Skin/TokenFile.php 's#&& ! preg_match(./^(0?#\&\& false \&\& ! preg_match(\x27/^(0?#' "chamfer: accept any steepness"
run_with $B 's/--ts-btn-chamfer-tl: initial;/--ts-btn-chamfer-tl: 0px;/' "chamfer: clip every button by default"

RM='/\.panel::before,$/,/^}/'
run_with src/Skin/TokenFile.php "s#\[2-9\]|\[1-5\]\[0-9\]#[0-9]|[1-5][0-9]#" "motion: allow a period under 2s"
run_with src/Skin/TokenFile.php "s#\[0-9.%, \\\\/\]{3,40}#[0-9a-z.%, \\\\/()-]{3,200}#" "motion: allow a glow colour to carry a second shadow"
run_with $B "$RM s/animation: none;/animation: ts-breathe 1s infinite;/" "motion: no reduced-motion rule for the ornament layers"
run_with $B 's/box-shadow: 0 0 16px var(--ts-alert-glow-high);/box-shadow: 0 0 160px var(--ts-alert-glow-high);/' "motion: a 160px alert glow"
run_with $B 's/filter: drop-shadow(0 0 8px var(--ts-frame-glow));/filter: drop-shadow(0 0 80px var(--ts-frame-glow));/' "motion: an 80px frame glow"

RP='/data-ts-orn\]) \.panel::after {/,/^}/'
RG='/data-ts-orn\]) \.grid-stack \.grid-stack-item-content {/,/^}/'
run_with $B "$RP s/pointer-events: none;/pointer-events: auto;/" "cuts: let the panel corner overlay take clicks"
run_with $B "$RP s/inset: -1px;/inset: -40px;/" "cuts: let the panel corner overlay extend 40px out"
run_with $B "$RP s/--ts-panel-chamfer, 0px))/--ts-panel, 0px))/" "cuts: drop the panel size fallback chain"
run_with $B "$RG s/calc(100% + 10000px) -10000px/calc(100% + 10000px) -8px/" "cuts: a widget clip that cuts off what hangs out of it"
RC='/data-ts-orn\]) \.panel {/,/^}/'
run_with $B "$RC s/calc(100% + 10000px) -10000px/calc(100% + 10px) -10px/" "cuts: a panel clip that cuts off a dropdown hanging out of it"
run_with $B "$RC s/calc(100% + 2px) calc(100% + 2px)/100% 100%/" "cuts: a clip notch whose edge lies exactly on the box edge (hairlines at fractional zoom)"
run_with resources/token-catalog.json '/"--ts-panel-chamfer-bl": {/,/}/ s/"maxPx": 12/"maxPx": 800/' "cuts: no cap on panel cuts"
run_with $B 's/html.dark .panel\[class\*="tw:rounded"\] {/html.dark .panel[class*="tw:roundedx"] {/' "cuts: radius tokens no longer reach tw:rounded panels"

X=src/Skin/PngTexture.php
run_with $X 's#if (\$crc !== (crc32(\$type \. \$data) \& 0xFFFFFFFF)) {#if (false) {#' "png: skip the chunk checksum"
run_with $X "s#if (\$h\['interlace'\] !== 0) {#if (false) {#" "png: accept interlaced images"
run_with $X 's#if (strlen(\$out) > Limits::TEXTURE_BYTES) {#if (false) {#' "png: no size limit once cleaned"
run_with $X 's#if (\$ended) {#if (false) {#' "png: accept data after IEND"
run_with $X 's#if (strlen(\$out) > \$want) {#if (false) {#' "png: do not stop a decompression bomb early"
run_with $X 's#if (\$strict) {#if (false) {#' "png: accept metadata in a strict (bundled) texture"
run_with $X "s#case 'acTL':#case 'acTLx':#" "png: accept an animated PNG"
run_with $X 's#\$w > self::MAX_SIDE || \$ht > self::MAX_SIDE#false#' "png: no size limit on the image"
run_with $X 's#if (\$type === 3 \&\& \$palette === null) {#if (false) {#' "png: accept a palette image with no palette"
run_with $X "s#if (ord(\$raw\[\$row \* (\$rowBytes + 1)\]) > 4) {#if (false) {#" "png: accept an invalid row filter"
run_with src/Skin/TokenFile.php 's#if (! isset(\$used\[\$tn\])) {#if (false) {#' "texture: allow a texture nothing uses"
run_with src/Skin/TokenFile.php "s#if (! in_array('image', \$this->catalog->kinds(\$name), true)) {#if (false) {#" "texture: allow a texture in a non-image token"
run_with src/Skin/TokenFile.php 's# || \$um\[2\] !== \$slug##' "texture: allow a name that differs from its file"
run_with src/Skin/OutputGuard.php "s#|| PngTexture::check('texture', \$png, new Report(), true) === null#|| false#" "guard: do not re-check the embedded PNG"
run_with src/Skin/ZipBundleReader.php 's#|textures/\[a-z0-9\]\[a-z0-9-\]{0,40}\\.png##' "zip: textures/*.png is not an allowed entry"
run_with src/Skin/Limits.php 's#TEXTURE_BYTES = 65_536#TEXTURE_BYTES = 6_553_600#' "texture: no limit on a cleaned texture's size"

echo
echo "caught $CAUGHT, redundant $REDUNDANT, missed $MISSED, not applied $UNAPPLIED"
[ "$MISSED" = 0 ] && [ "$UNAPPLIED" = 0 ]
