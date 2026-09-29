<?php

namespace Xblossia\ThemeSelector\Skin;

/**
 * A skin's optional LICENSE.txt: the notice that has to travel with a font (the
 * SIL Open Font License asks for exactly this).
 *
 * It is DATA, not a served file. It is checked here, stored in the database
 * with the skin, and shown to admins as escaped text. It is never written to
 * the web root, so the "nothing uploaded is ever served" rule still holds.
 *
 * What is accepted is real text: valid UTF-8 made of letters, marks, numbers,
 * punctuation, symbols and spaces, plus newlines and tabs. Licence notices
 * contain "(c)", the copyright sign and curly quotes, so this is wider than the
 * ASCII-only rule for CSS, but it still refuses:
 *   - control characters (terminal escapes, NUL, form feeds), which matter
 *     because this text also reaches the log;
 *   - invisible formatting characters, above all the bidirectional overrides
 *     used to make text read differently from how it is stored;
 *   - unassigned and private-use code points.
 * Length is capped. Markup characters are allowed (a notice may quote an email
 * address in angle brackets) because the text is only ever displayed escaped.
 */
final class LicenseText
{
    /**
     * @return string|null  the text with line endings normalised, or null (see $report)
     */
    public static function check(string $raw, Report $report): ?string
    {
        if (strlen($raw) > Limits::LICENSE_BYTES) {
            $report->error('LICENSE.txt', 'is larger than ' . Limits::LICENSE_BYTES . ' bytes');

            return null;
        }
        if (! mb_check_encoding($raw, 'UTF-8')) {
            $report->error('LICENSE.txt', 'is not valid UTF-8 text');

            return null;
        }
        $text = str_replace(["\r\n", "\r"], "\n", $raw);
        if (trim($text) === '') {
            $report->error('LICENSE.txt', 'is empty');

            return null;
        }
        if (preg_match('/[^\p{L}\p{M}\p{N}\p{P}\p{S}\p{Zs}\n\t]/u', $text)) {
            $report->error('LICENSE.txt', 'contains control, invisible or non-text characters');

            return null;
        }

        return $text;
    }
}
