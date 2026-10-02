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

# Each mutation is independent (its own scratch copy, its own PHP process), so they run
# a few at a time: MUTATE_JOBS (default 8) at once, in batches. The results are collected
# and printed in the order the mutations are listed below, whatever order they finish in.
JOBS="${MUTATE_JOBS:-8}"
IDX=0
RUNNING=0
mkdir -p "$WORK/res"

one_mutation() { # index, file, sed expression, description [redundant]; writes $WORK/res/<index>
  idx="$1"; file="$2"; expr="$3"; desc="$4"; flag="${5:-}"
  dir="$WORK/t$idx"
  rm -rf "$dir"; mkdir -p "$dir"
  cp -r "$ROOT/src" "$ROOT/tests" "$ROOT/resources" "$ROOT/examples" "$ROOT/skins" "$ROOT/base" "$dir/"
  before="$(md5sum "$dir/$file" | cut -d' ' -f1)"
  sed -i "$expr" "$dir/$file"
  after="$(md5sum "$dir/$file" | cut -d' ' -f1)"
  if [ "$before" = "$after" ]; then
    verdict=UNAPPLIED
    line="$(printf '  ??    %-62s (mutation did not apply)' "$desc")"
  else
    out="$(php "$dir/tests/run.php" 2>&1)"
    failed="$(printf '%s' "$out" | sed -n 's/^[0-9]* passed, \([0-9]*\) failed$/\1/p')"
    if [ -z "$failed" ]; then failed="crash"; fi
    if [ "$failed" = "0" ] && [ "$flag" = "redundant" ]; then
      verdict=REDUNDANT; line="$(printf '  redundant %-58s (another layer stops it)' "$desc")"
    elif [ "$failed" = "0" ]; then
      verdict=MISSED; line="$(printf '  MISSED %-61s' "$desc")"
    elif [ "$flag" = "redundant" ]; then
      verdict=MISSED; line="$(printf '  MISLABELLED %-56s (%s failing: not redundant after all)' "$desc" "$failed")"
    else
      verdict=CAUGHT; line="$(printf '  caught %-61s (%s failing)' "$desc" "$failed")"
    fi
  fi
  printf '%s\n%s\n' "$verdict" "$line" > "$WORK/res/$idx"
  rm -rf "$dir"
}

run_with() { # file, sed expression, description [redundant]
  IDX=$((IDX + 1))
  one_mutation "$IDX" "$@" &
  RUNNING=$((RUNNING + 1))
  if [ "$RUNNING" -ge "$JOBS" ]; then wait; RUNNING=0; fi
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
run_with $B "$RW s/32px 32px, 32px 32px/300px 300px, 300px 300px/" "ornaments: make a widget corner slot 300px"

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
RZ='/data-ts-orn\]) \.panel:is(:hover, :has(\.open)) {/,/^}/'
run_with $B "$RZ s/z-index: 1035/z-index: 1/" "cards: a raised panel that still sits under the sticky navbar"
run_with $B "$RZ s/z-index: 1035/z-index: 1045/" "cards: a raised panel that covers modals"
run_with $B 's/\.panel:is(:hover, :has(\.open)) {/.panel:is(:has(.open)) {/' "cards: a panel that is not raised when hovered (its hover card goes under the next panel)"
run_with $B 's/\.grid-stack \.grid-stack-item:hover {/.grid-stack .grid-stack-itemx:hover {/' "cards: a widget that is not raised when hovered"
PS=src/Graph/PortSeries.php
PU=src/Graph/PortSeriesSupport.php
run_with $PS 's# || strtoupper($m\[4\]) !== self::STOCK\[$direction\]\[$role\]##' "port colours: recolour any colour, not only the stock literal"
run_with $PS "s#if (\$m\[1\] === 'LINE' && \$max) {#if (false) {#" "port colours: recolour the outline of the _max series"
run_with $PS "s#(\$max ? 0 : 1)#(\$max ? 1 : 0)#" "port colours: swap the max fill and the area fill"
run_with $PS 's#/^\[0-9A-Fa-f\]{6}$/#/./#' "port colours: accept any text as a colour"
run_with $PS 's#if (count($palette) < 3) {#if (false) {#' "port colours: accept a palette with fewer than three tones"
run_with $PS "s#'~^(AREA|LINE)#'~(AREA|LINE)#" "port colours: match an option that does not start with AREA/LINE"
run_with $PU "s#return \$return instanceof ReflectionNamedType && \$return->getName() === 'string' && ! \$return->allowsNull();#return true;#" "port store guard: ignore the return type of graph()"
run_with $PU 's#\$graph->isFinal() || ##' "port store guard: subclass a final graph()"
run_with $PU 's#if ($constructor !== null && $constructor->getNumberOfRequiredParameters() > 0) {#if (false) {#' "port store guard: subclass a store whose constructor needs arguments"
run_with $B "$RC s#min(calc(var(--ts-panel-chamfer-[a-z]*, var(--ts-panel-chamfer)) \* 1000), 2px)#0px#g" "cuts: a clip notch whose edge lies exactly on the box edge (hairlines at fractional zoom)"
run_with $B "$RC s#min(calc(var(--ts-panel-chamfer-[a-z]*, var(--ts-panel-chamfer)) \* 1000), 2px)#2px#g" "cuts: a corner with no cut still notches its outside (bites the bars that jut out of it)"
run_with $B "$RG s#min(calc(var(--ts-widget-chamfer-[a-z]*, var(--ts-widget-chamfer)) \* 1000), 2px)#2px#g" "cuts: the same on widgets"
run_with $B "$RG s#background-image: linear-gradient(to top right#background-image: var(--ts-widget-frame-tl), linear-gradient(to top right#" "cuts: a frame bar drawn above the edge line of a cut (it pokes out of the cut)"
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

RR='/^html\.dark body::before {/,/^}/'
run_with $B "$RR s/z-index: -1;/z-index: 2;/" "rain: raise the layer above content"
run_with $B "$RR s/pointer-events: none;/pointer-events: auto;/" "rain: let the layer take clicks"
run_with $B "$RR s/position: fixed;/position: absolute;/" "rain: let the layer scroll with the page"
run_with $B "$RR s/content: \"\";/content: \"Session expired\";/" "rain: give the layer text"
run_with $B 's/transform: translateY(0);/top: 0;/' "rain: animate layout instead of transform"
run_with $B 's/^    animation: none;/    animation: ts-rain 36s linear infinite;/' "rain: keep moving under reduced motion"
run_with $B 's/^  font-family: var(--ts-root-font-family);/&\n  background-color: var(--ts-bg);/' "rain: paint <html> too, over the layer"
run_with src/Skin/TokenFile.php 's#51\[0-2\]#5[1-9][0-9]#' "rain: accept a tile larger than 512px"
run_with src/Skin/TokenFile.php "s#if (in_array('tile', \$this->catalog->kinds(\$name), true)#if (false \&\& in_array('tile', \$this->catalog->kinds(\$name), true)#" "rain: accept a tile in % or em"
run_with resources/token-catalog.json '/"--ts-rain-tile": {/,/}/ s/"maxPx": 512/"maxPx": 800/' "rain: cap the tile at 800px in the catalog"
run_with src/Effects.php 's#\$roll(self::RABBIT_ONE_IN) === 1#$roll(self::RABBIT_ONE_IN) >= 1#' "effects: show the rabbit on every roll"
run_with src/Effects.php 's#RABBIT_ONE_IN = 10#RABBIT_ONE_IN = 2#' "effects: one in two, not one in ten"
run_with src/Effects.php 's#\$devicePage \&\& ##' "effects: show the rabbit on every page"
run_with src/Effects.php "s#in_array('white-rabbit', \$effects, true) \&\& ##" "effects: show the rabbit to skins that did not ask"
run_with src/Features.php "s#\['ornaments'\] ?? false) === true#['ornaments'] ?? false) == true#" "features: read ornaments 1 as true"
run_with src/Features.php 's#strlen(\$json) > 2048#strlen($json) > 2048000#' "features: no size limit"
run_with src/Features.php 's#json_decode(\$json, true, 4)#json_decode($json, true, 512)#' "features: no nesting limit"
run_with src/Features.php "s#array_values(array_intersect(self::EFFECTS, array_filter(\$effects, 'is_string')))#array_values(array_filter(\$effects, 'is_string'))#" "features: honour any effect name"
run_with src/SkinRepository.php 's#isUploaded(\$id) || #isUploaded($id) \&\& #' "features: an upload needs a features file to get ornaments"
run_with src/SkinRepository.php 's#|| ! \$this->isBundled(\$id)) {#) {#' "features: read a features file from a directory that is not a skin"
run_with src/SkinRepository.php 's#if (! self::isValidId(\$id) || #if (#' "features: do not check the id's shape first" redundant

wait
n=1
while [ "$n" -le "$IDX" ]; do
  f="$WORK/res/$n"
  if [ -f "$f" ]; then
    verdict="$(sed -n 1p "$f")"
    sed -n 2p "$f"
  else
    verdict=MISSED
    echo "  MISSED (mutation $n produced no result: its process died)"
  fi
  case "$verdict" in
    CAUGHT) CAUGHT=$((CAUGHT + 1)) ;;
    REDUNDANT) REDUNDANT=$((REDUNDANT + 1)) ;;
    UNAPPLIED) UNAPPLIED=$((UNAPPLIED + 1)) ;;
    *) MISSED=$((MISSED + 1)) ;;
  esac
  n=$((n + 1))
done

echo
echo "caught $CAUGHT, redundant $REDUNDANT, missed $MISSED, not applied $UNAPPLIED"
[ "$MISSED" = 0 ] && [ "$UNAPPLIED" = 0 ]
