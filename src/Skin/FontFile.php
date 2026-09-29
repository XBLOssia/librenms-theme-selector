<?php

namespace Xblossia\ThemeSelector\Skin;

/**
 * Checks that an uploaded "font" is a WOFF or WOFF2 file and nothing else.
 *
 * An uploaded font is never served as a file. It is validated here and then
 * embedded as base64 inside the generated stylesheet, so its bytes can't be
 * requested, sniffed or executed on their own. These checks are the second
 * line: they make a polyglot (a real font with a payload attached) fail early
 * and keep the accepted set honest.
 *
 * - Magic number must match the extension, so an SVG, HTML or script renamed to
 *   .woff2 is refused.
 * - Both formats declare their own total length in the header. Requiring it to
 *   equal the file size means nothing can be appended after a valid font.
 * - Bytes that open PHP, a short tag or an HTML script are refused wherever
 *   they occur. Only unambiguous openers are scanned for, because a bare `<?`
 *   turns up by chance in compressed data.
 *
 * It does not parse the font tables; the browser's own font sanitiser does
 * that, on data it can only reach as an inert data: URL.
 */
final class FontFile
{
    public static function check(string $name, string $bytes, Report $report): bool
    {
        $label = 'font ' . Report::quote($name);
        $len = strlen($bytes);
        $isWoff2 = str_ends_with($name, '.woff2');

        if ($len < 48 || $len > Limits::FONT_BYTES) {
            $report->error($label, 'is empty, truncated or too large');

            return false;
        }

        $magic = $isWoff2 ? 'wOF2' : 'wOFF';
        if (substr($bytes, 0, 4) !== $magic) {
            $report->error($label, 'is not a ' . ($isWoff2 ? 'WOFF2' : 'WOFF') . ' font (wrong file signature)');

            return false;
        }

        // WOFF and WOFF2 headers both hold the total length at offset 8, the
        // table count at 12 and a reserved field, which must be 0, at 14.
        $h = unpack('Nlength/nnumTables/nreserved', substr($bytes, 8, 8));
        if ($h['length'] !== $len) {
            $report->error($label, 'declares a different size than the file has (extra data appended?)');

            return false;
        }
        if ($h['reserved'] !== 0 || $h['numTables'] < 1 || $h['numTables'] > 120) {
            $report->error($label, 'has an invalid header');

            return false;
        }

        if (stripos($bytes, '<?php') !== false || stripos($bytes, '<script') !== false
            || str_contains($bytes, '<?=') || str_contains($bytes, '<%')
            || preg_match('/<\?[ \t\r\n]/', $bytes)) {
            $report->error($label, 'contains what looks like PHP or script code');

            return false;
        }

        return true;
    }
}
