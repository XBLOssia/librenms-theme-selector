<?php

namespace Xblossia\ThemeSelector\Skin;

/**
 * A last, independent check on the stylesheet about to be published.
 *
 * TokenFile builds its output from a parsed structure, so in normal operation
 * this can't fail. It exists for the case where a bug in the parser lets
 * something through: the check looks only at the finished text, knows nothing
 * about how it was produced, and is deliberately dull. If it says no, nothing
 * is written.
 *
 * What a served skin stylesheet may contain, in full:
 *   - printable ASCII and newlines;
 *   - one `html.dark { ... }` block and one `@font-face { ... }` block per font;
 *   - exactly one `url(` per font, each a base64 data: URL for a font type;
 *   - exactly one `url(` per texture, each a `--tx-<name>` declaration holding a
 *     base64 data: URL that decodes to a clean PNG (PngTexture checks it again).
 * There is no `@import`, no other at-rule, no other `url(`, no backslash, no
 * angle bracket, no scriptable pseudo-protocol.
 */
final class OutputGuard
{
    public static function safe(string $css, int $fonts, int $textures = 0): bool
    {
        if ($css === '' || preg_match('/[^\x0A\x20-\x7E]/', $css)) {
            return false;
        }
        foreach (['<', '>', '\\', '@import', '@charset', '@namespace', '@media', '@keyframes', 'expression', 'javascript:', 'vbscript:', 'behavior', 'binding', 'image-set', 'element(', 'paint(', 'attr('] as $bad) {
            if (stripos($css, $bad) !== false) {
                return false;
            }
        }

        // Structure: one root block plus one block per font face.
        if (substr_count($css, '@') !== $fonts || substr_count($css, '@font-face') !== $fonts
            || substr_count($css, '{') !== $fonts + 1 || substr_count($css, '}') !== $fonts + 1) {
            return false;
        }

        // Every url( is a data: URL for a font or a texture, and there are no others.
        if (substr_count(strtolower($css), 'url(') !== $fonts + $textures) {
            return false;
        }
        if ($fonts > 0) {
            $matched = preg_match_all('#url\("data:font/(?:woff2|woff);base64,[A-Za-z0-9+/]+={0,2}"\)#', $css);
            if ($matched !== $fonts) {
                return false;
            }
        }
        $found = preg_match_all('#^  --tx-[a-z0-9][a-z0-9-]{0,40}: url\("data:image/png;base64,([A-Za-z0-9+/]+={0,2})"\);$#m', $css, $m);
        if ($found !== $textures) {
            return false;
        }
        foreach ($m[1] as $b64) {
            $png = base64_decode($b64, true);
            if ($png === false || PngTexture::check('texture', $png, new Report(), true) === null) {
                return false;
            }
        }

        return true;
    }
}
