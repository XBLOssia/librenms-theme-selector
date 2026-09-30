<?php

namespace Xblossia\ThemeSelector\Skin;

/**
 * Every size and count limit in one place. The values are generous for a real
 * skin (the bundled ones use a fraction) and small enough that a hostile
 * bundle can't cost meaningful memory or time to reject.
 */
final class Limits
{
    /** The uploaded zip itself. */
    public const ARCHIVE_BYTES = 4_194_304;
    /** Entries in the zip, directories included. */
    public const ENTRIES = 40;
    /** Sum of every entry's uncompressed size. */
    public const UNCOMPRESSED_TOTAL = 3_145_728;

    public const MANIFEST_BYTES = 4_096;
    public const CSS_BYTES = 98_304;
    public const GRAPH_BYTES = 8_192;
    public const LICENSE_BYTES = 20_480;
    public const FONT_BYTES = 409_600;
    public const FONTS = 8;
    public const FONT_FACES = 12;

    /** Custom-property declarations in one token file. */
    public const DECLARATIONS = 500;
    /** Private --p-* palette entries. */
    public const PRIVATE_TOKENS = 250;
    public const VALUE_BYTES = 1_200;
    public const VALUE_TOKENS = 200;
    public const NESTING = 6;

    /** The generated stylesheet, fonts inlined. */
    public const OUTPUT_BYTES = 3_500_000;

    /** Findings kept in a report before it just says "and more". */
    public const REPORT_ERRORS = 40;
}
