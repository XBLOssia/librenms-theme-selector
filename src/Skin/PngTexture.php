<?php

namespace Xblossia\ThemeSelector\Skin;

/**
 * A texture is a small, repeating PNG a skin tiles in a background.
 *
 * It is treated like a font: never served as an uploaded file. It is parsed here
 * from its bytes, without a decoder, checked down to the last byte, and then
 * re-written as a canonical PNG that is embedded in the generated stylesheet as a
 * base64 data: URL. The browser is shown only a file this class built.
 *
 * What is accepted (what a texture tile normally is):
 *   - a PNG, non-interlaced, 8 bits per channel (or 1, 2, 4 or 8 for greyscale
 *     and palette images), not animated, at most MAX_SIDE px each way;
 *   - chunks in a legal order with correct CRCs, and nothing after IEND;
 *   - pixel data that inflates to exactly the size the header implies, with a
 *     legal filter byte on every row.
 *
 * What is dropped when re-writing (unless $strict): every ancillary chunk
 * except tRNS (colour profiles, gamma, text, EXIF, timestamps, pixel size), so a
 * texture exported by an image editor works, and whatever it carried doesn't
 * travel. An animated PNG (acTL) is refused, not flattened.
 *
 * $strict is for textures that ship in this package: they must already be clean.
 */
final class PngTexture
{
    public const MAX_SIDE = 256;
    private const SIGNATURE = "\x89PNG\r\n\x1a\n";
    private const CHANNELS = [0 => 1, 2 => 3, 3 => 1, 4 => 2, 6 => 4];
    private const DEPTHS = [0 => [1, 2, 4, 8], 2 => [8], 3 => [1, 2, 4, 8], 4 => [8], 6 => [8]];
    private const MAX_CHUNKS = 64;

    /**
     * @return array{png: string, width: int, height: int}|null  the canonical PNG and its size, or null (see $report)
     */
    public static function check(string $name, string $bytes, Report $report, bool $strict = false): ?array
    {
        $fail = function (string $why) use ($name, $report): null {
            $report->error($name, $why);

            return null;
        };

        if (strlen($bytes) > Limits::TEXTURE_INPUT_BYTES) {
            return $fail('is larger than ' . Limits::TEXTURE_INPUT_BYTES . ' bytes');
        }
        if (substr($bytes, 0, 8) !== self::SIGNATURE) {
            return $fail('is not a PNG (wrong file signature)');
        }

        $len = strlen($bytes);
        $pos = 8;
        $chunks = [];          // [type, data] in file order
        $ended = false;
        while ($pos < $len) {
            if ($ended) {
                return $fail('has data after its IEND chunk');
            }
            if (count($chunks) >= self::MAX_CHUNKS) {
                return $fail('has too many chunks');
            }
            if ($pos + 12 > $len) {
                return $fail('is truncated inside a chunk');
            }
            $size = unpack('N', substr($bytes, $pos, 4))[1];
            $type = substr($bytes, $pos + 4, 4);
            if (! preg_match('/^[A-Za-z]{4}\z/D', $type)) {
                return $fail('has a chunk with an invalid type');
            }
            if ($size > $len - $pos - 12) {
                return $fail('has a chunk longer than the file');
            }
            $data = substr($bytes, $pos + 8, $size);
            $crc = unpack('N', substr($bytes, $pos + 8 + $size, 4))[1];
            if ($crc !== (crc32($type . $data) & 0xFFFFFFFF)) {
                return $fail('has a chunk (' . $type . ') with a bad checksum');
            }
            $chunks[] = [$type, $data];
            $pos += 12 + $size;
            if ($type === 'IEND') {
                $ended = true;
            }
        }
        if (! $ended) {
            return $fail('has no IEND chunk');
        }
        if ($chunks[0][0] !== 'IHDR' || strlen($chunks[0][1]) !== 13) {
            return $fail('does not start with a valid IHDR chunk');
        }
        if ($chunks[count($chunks) - 1][1] !== '') {
            return $fail('has an IEND chunk with data in it');
        }

        $h = unpack('Nw/Nh/Cdepth/Ctype/Ccompression/Cfilter/Cinterlace', $chunks[0][1]);
        $w = $h['w'];
        $ht = $h['h'];
        if ($w < 1 || $ht < 1 || $w > self::MAX_SIDE || $ht > self::MAX_SIDE) {
            return $fail('is ' . $w . 'x' . $ht . ' px; a texture is at most ' . self::MAX_SIDE . 'x' . self::MAX_SIDE);
        }
        if (! isset(self::DEPTHS[$h['type']]) || ! in_array($h['depth'], self::DEPTHS[$h['type']], true)) {
            return $fail('uses a colour type or bit depth that is not accepted (8-bit greyscale, greyscale with alpha, RGB, RGBA, or palette)');
        }
        if ($h['compression'] !== 0 || $h['filter'] !== 0) {
            return $fail('has an invalid header');
        }
        if ($h['interlace'] !== 0) {
            return $fail('is interlaced; save it without interlacing');
        }

        $type = $h['type'];
        $palette = null;
        $trns = null;
        $idat = '';
        $seenIdat = false;
        $idatDone = false;
        $keep = [];
        for ($i = 1, $n = count($chunks) - 1; $i < $n; $i++) {
            [$t, $data] = $chunks[$i];
            $critical = ($t[0] & "\x20") === "\x00";
            if ($t === 'IDAT') {
                if ($idatDone) {
                    return $fail('has image data split by another chunk');
                }
                $seenIdat = true;
                $idat .= $data;
                continue;
            }
            if ($seenIdat) {
                $idatDone = true;
            }
            switch ($t) {
                case 'IHDR':
                    return $fail('has more than one IHDR chunk');
                case 'PLTE':
                    if ($palette !== null || $seenIdat) {
                        return $fail('has a misplaced PLTE chunk');
                    }
                    $count = intdiv(strlen($data), 3);
                    if (strlen($data) % 3 !== 0 || $count < 1 || $count > 256) {
                        return $fail('has an invalid palette');
                    }
                    if ($type === 0 || $type === 4) {
                        return $fail('has a palette, which greyscale images can not have');
                    }
                    if ($type === 3 && $count > (1 << $h['depth'])) {
                        return $fail('has more palette entries than its bit depth allows');
                    }
                    $palette = $data;
                    break;
                case 'tRNS':
                    if ($trns !== null || $seenIdat) {
                        return $fail('has a misplaced tRNS chunk');
                    }
                    $trns = $data;
                    break;
                case 'acTL':
                case 'fcTL':
                case 'fdAT':
                    return $fail('is an animated PNG; a texture must be a single still image');
                default:
                    if ($critical) {
                        return $fail('has a critical chunk (' . $t . ') that is not accepted');
                    }
                    if ($strict) {
                        return $fail('has an extra chunk (' . $t . '); re-save it without metadata');
                    }
            }
        }
        if (! $seenIdat) {
            return $fail('has no image data');
        }
        if ($type === 3 && $palette === null) {
            return $fail('is a palette image with no palette');
        }
        if ($trns !== null) {
            $want = match ($type) {
                0 => strlen($trns) === 2,
                2 => strlen($trns) === 6,
                3 => strlen($trns) >= 1 && strlen($trns) <= intdiv(strlen((string) $palette), 3),
                default => false,
            };
            if (! $want) {
                return $fail('has an invalid tRNS chunk');
            }
        }

        // The pixel data must inflate to exactly the size the header implies: no
        // less, no more, nothing after the end of the stream.
        $rowBytes = intdiv($w * self::CHANNELS[$type] * $h['depth'] + 7, 8);
        $want = $ht * ($rowBytes + 1);
        $raw = self::inflateExactly($idat, $want);
        if ($raw === null) {
            return $fail('has image data that does not match its header (or that is corrupt, or expands beyond its declared size)');
        }
        for ($row = 0; $row < $ht; $row++) {
            if (ord($raw[$row * ($rowBytes + 1)]) > 4) {
                return $fail('has an invalid row filter');
            }
        }

        // Re-write it: only the chunks that matter, with the pixel data compressed
        // afresh, so nothing can hide in the original compressed stream.
        $compressed = gzcompress($raw, 9);
        if ($compressed === false) {
            return $fail('could not be processed');
        }
        $out = self::SIGNATURE . self::chunk('IHDR', $chunks[0][1]);
        if ($type === 3) {
            $out .= self::chunk('PLTE', (string) $palette);
        }
        if ($trns !== null) {
            $out .= self::chunk('tRNS', $trns);
        }
        $out .= self::chunk('IDAT', $compressed) . self::chunk('IEND', '');
        if (strlen($out) > Limits::TEXTURE_BYTES) {
            return $fail('is ' . strlen($out) . ' bytes once cleaned; a texture is at most ' . Limits::TEXTURE_BYTES . ' (use a palette or greyscale, or a smaller tile)');
        }

        return ['png' => $out, 'width' => $w, 'height' => $ht];
    }

    private static function chunk(string $type, string $data): string
    {
        return pack('N', strlen($data)) . $type . $data . pack('N', crc32($type . $data) & 0xFFFFFFFF);
    }

    /**
     * Inflate a zlib stream to exactly $want bytes, feeding it in small slices so a
     * decompression bomb is stopped as soon as it passes the expected size.
     */
    private static function inflateExactly(string $data, int $want): ?string
    {
        $ctx = @inflate_init(ZLIB_ENCODING_DEFLATE);
        if ($ctx === false) {
            return null;
        }
        $out = '';
        $n = strlen($data);
        for ($i = 0; $i < $n; $i += 512) {
            $slice = substr($data, $i, 512);
            $piece = @inflate_add($ctx, $slice, ZLIB_SYNC_FLUSH);
            if ($piece === false) {
                return null;
            }
            $out .= $piece;
            if (strlen($out) > $want) {
                return null;
            }
            if (inflate_get_status($ctx) === ZLIB_STREAM_END && $i + 512 < $n) {
                return null;        // more bytes after the end of the stream
            }
        }
        if (inflate_get_status($ctx) !== ZLIB_STREAM_END || inflate_get_read_len($ctx) !== $n) {
            return null;
        }

        return strlen($out) === $want ? $out : null;
    }
}
