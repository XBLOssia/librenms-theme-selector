<?php

namespace Xblossia\ThemeSelector\Skin;

/**
 * Reads a skin bundle (a zip) into memory, strictly, or refuses it.
 *
 * This is the first code that touches hostile bytes, so it is written to trust
 * nothing and to fail closed:
 *
 * - It never extracts. Nothing is written to disk and no path is ever built
 *   from an entry name, so there is no zip-slip to get wrong. Entries are read
 *   into memory and handed on by their (already allowlisted) name.
 * - Names are matched against an exact allowlist of what a skin contains; any
 *   other entry, however harmless it looks, rejects the whole bundle.
 * - It parses the format itself instead of leaning on ext-zip, which LibreNMS
 *   does not require and which does its own path handling. Only ext-zlib is
 *   needed, and LibreNMS requires that.
 * - Every field a zip records twice (in the central directory and in each local
 *   header) is cross-checked, because disagreement between the two is how
 *   crafted archives show different content to different readers.
 * - Decompression is bounded by counting output as it is produced, never by
 *   trusting the declared size, so a small archive cannot expand into memory.
 *
 * Not supported, and refused: ZIP64, encryption, spanned archives, any
 * compression but stored and deflate, symlinks and other non-regular files.
 */
final class ZipBundleReader
{
    private const NAME = '#^(skin\.json|skin\.css|graph\.conf|LICENSE\.txt|fonts/[A-Za-z0-9][A-Za-z0-9_-]{0,63}\.(woff2|woff))\z#D';

    /**
     * @return array<string, string>|null  entry name => contents, or null (see $report)
     */
    public static function read(string $path, Report $report): ?array
    {
        $size = @filesize($path);
        if ($size === false || $size < 22) {
            $report->error('bundle', 'not a readable zip file');

            return null;
        }
        if ($size > Limits::ARCHIVE_BYTES) {
            $report->error('bundle', 'larger than ' . intdiv(Limits::ARCHIVE_BYTES, 1024 * 1024) . ' MB');

            return null;
        }
        $data = @file_get_contents($path);
        if ($data === false || strlen($data) !== $size) {
            $report->error('bundle', 'could not be read');

            return null;
        }

        return self::parse($data, $report);
    }

    /**
     * @return array<string, string>|null
     */
    public static function parse(string $data, Report $report): ?array
    {
        $len = strlen($data);

        if (substr($data, 0, 4) !== "PK\x03\x04") {
            $report->error('bundle', 'not a zip file (or an empty one)');

            return null;
        }
        if (str_contains($data, "PK\x06\x06") || str_contains($data, "PK\x06\x07")) {
            $report->error('bundle', 'ZIP64 archives are not supported');

            return null;
        }

        // The end-of-central-directory record must be the very last thing, and
        // there must be exactly one: two would mean two readers could disagree
        // about where the directory is.
        if (substr_count($data, "PK\x05\x06") !== 1) {
            $report->error('bundle', 'archive structure is ambiguous');

            return null;
        }
        $eocd = strrpos($data, "PK\x05\x06");
        if ($eocd === false || $eocd + 22 > $len) {
            $report->error('bundle', 'archive is truncated');

            return null;
        }
        $e = unpack('vdisk/vcdDisk/ventriesDisk/ventries/VcdSize/VcdOffset/vcommentLen', substr($data, $eocd + 4, 18));
        if ($e['disk'] !== 0 || $e['cdDisk'] !== 0 || $e['entriesDisk'] !== $e['entries']) {
            $report->error('bundle', 'multi-part archives are not supported');

            return null;
        }
        if ($eocd + 22 + $e['commentLen'] !== $len) {
            $report->error('bundle', 'unexpected data after the archive');

            return null;
        }
        if ($e['entries'] < 1 || $e['entries'] > Limits::ENTRIES) {
            $report->error('bundle', 'must contain between 1 and ' . Limits::ENTRIES . ' entries');

            return null;
        }
        // The directory must end exactly where the end record starts: no gap
        // to hide bytes in.
        if ($e['cdOffset'] + $e['cdSize'] !== $eocd || $e['cdOffset'] < 30) {
            $report->error('bundle', 'archive directory is inconsistent');

            return null;
        }

        // ---- central directory ------------------------------------------------
        $entries = [];
        $pos = $e['cdOffset'];
        for ($n = 0; $n < $e['entries']; $n++) {
            if ($pos + 46 > $eocd || substr($data, $pos, 4) !== "PK\x01\x02") {
                $report->error('bundle', 'archive directory is corrupt');

                return null;
            }
            $h = unpack('vmadeBy/vneed/vflags/vmethod/vtime/vdate/Vcrc/Vcsize/Vusize/vnameLen/vextraLen/vcommentLen/vdiskStart/vinternal/VexternalAttr/VlocalOffset', substr($data, $pos + 4, 42));
            $tail = $h['nameLen'] + $h['extraLen'] + $h['commentLen'];
            if ($pos + 46 + $tail > $eocd) {
                $report->error('bundle', 'archive directory is corrupt');

                return null;
            }
            $h['name'] = substr($data, $pos + 46, $h['nameLen']);
            $entries[] = $h;
            $pos += 46 + $tail;
        }
        if ($pos !== $eocd) {
            $report->error('bundle', 'archive directory is inconsistent');

            return null;
        }

        // ---- validate every entry before reading any data ---------------------
        $files = [];
        $seen = [];
        $total = 0;
        $fonts = 0;
        $ranges = [];
        foreach ($entries as $h) {
            $name = $h['name'];
            $label = 'bundle entry ' . Report::quote($name);

            $isDir = $name === 'fonts/';
            if (! $isDir && ! preg_match(self::NAME, $name)) {
                $report->error($label, 'is not allowed in a skin bundle (allowed: skin.json, skin.css, graph.conf, fonts/*.woff2 and fonts/*.woff)');

                return null;
            }
            $folded = strtolower($name);
            if (isset($seen[$folded])) {
                $report->error($label, 'appears more than once');

                return null;
            }
            $seen[$folded] = true;

            if ($h['flags'] & 0x0001 || $h['flags'] & 0x0040 || $h['flags'] & 0x2000) {
                $report->error($label, 'is encrypted');

                return null;
            }
            if ($h['method'] !== 0 && $h['method'] !== 8) {
                $report->error($label, 'uses an unsupported compression method');

                return null;
            }
            if ($h['csize'] === 0xFFFFFFFF || $h['usize'] === 0xFFFFFFFF || $h['localOffset'] === 0xFFFFFFFF || $h['diskStart'] !== 0) {
                $report->error($label, 'uses ZIP64 or multi-part fields, which are not supported');

                return null;
            }
            // Unix mode bits: only regular files and directories. A symlink
            // entry is how an archive points a later write somewhere else.
            $host = $h['madeBy'] >> 8;
            if ($host === 3) {
                $type = ($h['externalAttr'] >> 16) & 0170000;
                if ($type !== 0 && $type !== 0100000 && $type !== 0040000) {
                    $report->error($label, 'is not a regular file');

                    return null;
                }
            }
            if ($isDir) {
                if ($h['usize'] !== 0 || $h['csize'] > 2) {
                    $report->error($label, 'directory entry has content');

                    return null;
                }
                continue;
            }

            $limit = match (true) {
                $name === 'skin.json' => Limits::MANIFEST_BYTES,
                $name === 'skin.css' => Limits::CSS_BYTES,
                $name === 'graph.conf' => Limits::GRAPH_BYTES,
                $name === 'LICENSE.txt' => Limits::LICENSE_BYTES,
                default => Limits::FONT_BYTES,
            };
            if ($h['usize'] > $limit) {
                $report->error($label, 'is larger than ' . $limit . ' bytes');

                return null;
            }
            if (str_starts_with($name, 'fonts/') && ++$fonts > Limits::FONTS) {
                $report->error('bundle', 'has more than ' . Limits::FONTS . ' fonts');

                return null;
            }
            $total += $h['usize'];
            if ($total > Limits::UNCOMPRESSED_TOTAL) {
                $report->error('bundle', 'expands to more than ' . intdiv(Limits::UNCOMPRESSED_TOTAL, 1024 * 1024) . ' MB');

                return null;
            }
            if ($h['method'] === 0 && $h['csize'] !== $h['usize']) {
                $report->error($label, 'stored size does not match');

                return null;
            }

            // ---- local header, cross-checked against the directory -------------
            $lo = $h['localOffset'];
            if ($lo + 30 > $e['cdOffset'] || substr($data, $lo, 4) !== "PK\x03\x04") {
                $report->error($label, 'has a corrupt local header');

                return null;
            }
            $l = unpack('vneed/vflags/vmethod/vtime/vdate/Vcrc/Vcsize/Vusize/vnameLen/vextraLen', substr($data, $lo + 4, 26));
            $localName = substr($data, $lo + 30, $l['nameLen']);
            if ($localName !== $name || $l['method'] !== $h['method'] || ($l['flags'] & 0x0001)) {
                $report->error($label, 'local header disagrees with the directory');

                return null;
            }
            // Without a data descriptor the local header repeats the sizes and
            // checksum; with one (flag bit 3) they are zero there and the
            // directory is authoritative.
            if (! ($l['flags'] & 0x0008) && ($l['crc'] !== $h['crc'] || $l['csize'] !== $h['csize'] || $l['usize'] !== $h['usize'])) {
                $report->error($label, 'local header disagrees with the directory');

                return null;
            }
            $start = $lo + 30 + $l['nameLen'] + $l['extraLen'];
            $end = $start + $h['csize'];
            if ($end > $e['cdOffset']) {
                $report->error($label, 'data runs past the archive');

                return null;
            }
            $ranges[] = [$lo, $end, $name];

            // ---- contents ------------------------------------------------------
            $compressed = substr($data, $start, $h['csize']);
            $out = $h['method'] === 0 ? $compressed : self::inflate($compressed, $h['usize'], $label, $report);
            if ($out === null) {
                return null;
            }
            if (strlen($out) !== $h['usize']) {
                $report->error($label, 'size does not match the directory');

                return null;
            }
            if (hexdec(hash('crc32b', $out)) !== $h['crc']) {
                $report->error($label, 'checksum does not match');

                return null;
            }
            $files[$name] = $out;
        }

        // Entries may not share bytes: overlapping data is how a small archive
        // claims a large expansion.
        usort($ranges, fn ($a, $b) => $a[0] <=> $b[0]);
        for ($i = 1; $i < count($ranges); $i++) {
            if ($ranges[$i][0] < $ranges[$i - 1][1]) {
                $report->error('bundle', 'entries overlap');

                return null;
            }
        }

        return $files;
    }

    /**
     * Inflate raw deflate data, giving up the moment output passes $expected.
     * Input is fed in small pieces so a decompression bomb is stopped after at
     * most a few megabytes of output, not after it has been produced.
     */
    private static function inflate(string $compressed, int $expected, string $label, Report $report): ?string
    {
        $ctx = @inflate_init(ZLIB_ENCODING_RAW);
        if ($ctx === false) {
            $report->error($label, 'cannot be decompressed here (zlib unavailable)');

            return null;
        }
        $out = '';
        $len = strlen($compressed);
        for ($off = 0; $off < $len; $off += 2048) {
            $piece = @inflate_add($ctx, substr($compressed, $off, 2048), ZLIB_NO_FLUSH);
            if ($piece === false) {
                $report->error($label, 'is corrupt (bad compressed data)');

                return null;
            }
            $out .= $piece;
            if (strlen($out) > $expected) {
                $report->error($label, 'expands beyond its declared size');

                return null;
            }
            // Stop at the end of the stream. Feeding a finished stream more
            // input is an error, and anything left over is caught below.
            if (inflate_get_status($ctx) === ZLIB_STREAM_END) {
                break;
            }
        }
        if (inflate_get_status($ctx) !== ZLIB_STREAM_END) {
            $report->error($label, 'is corrupt (incomplete compressed data)');

            return null;
        }
        if (inflate_get_read_len($ctx) !== $len) {
            $report->error($label, 'has extra data after the compressed stream');

            return null;
        }

        return $out;
    }
}
