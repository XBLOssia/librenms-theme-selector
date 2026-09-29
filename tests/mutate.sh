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
run_with $G "s/if (substr_count(strtolower(\$css), 'url(') !== \$fonts) {/if (false) {/" "guard: ignore stray url("
run_with $G "s/foreach (\['<', '>', /foreach ([/" "guard: allow angle brackets"
run_with $M "s/} elseif (in_array(\$id, self::RESERVED_IDS, true)) {/} elseif (false) {/" "allow reserved skin ids"
run_with $M "s/if (\$modes !== \['dark'\]) {/if (false) {/" "allow non-dark modes"
run_with $C "s/|| ! in_array(\$m\[1\], self::COLOUR_TAGS, true)//" "accept unknown RRDtool colour tags"

I=src/SkinInstaller.php
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

echo
echo "caught $CAUGHT, redundant $REDUNDANT, missed $MISSED, not applied $UNAPPLIED"
[ "$MISSED" = 0 ] && [ "$UNAPPLIED" = 0 ]
