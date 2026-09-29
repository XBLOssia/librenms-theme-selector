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
 *   - exactly one `url(` per font, each a base64 data: URL for a font type.
 * There is no `@import`, no other at-rule, no other `url(`, no backslash, no
 * angle bracket, no scriptable pseudo-protocol.
 */
final class OutputGuard
{
    public static function safe(string $css, int $fonts): bool
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

        // Every url( is a data: URL for a font, and there are no others.
        if (substr_count(strtolower($css), 'url(') !== $fonts) {
            return false;
        }
        if ($fonts > 0) {
            $matched = preg_match_all('#url\("data:font/(?:woff2|woff);base64,[A-Za-z0-9+/]+={0,2}"\)#', $css);
            if ($matched !== $fonts) {
                return false;
            }
        }

        return true;
    }
}
