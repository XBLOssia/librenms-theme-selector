<?php

/*
 * Exit 0 if every data:image/png in the given stylesheet is a clean PNG (the strict
 * PngTexture check: only IHDR, PLTE, tRNS, IDAT and IEND, nothing after the end), and
 * there is at least one.
 *
 *   php dev/check-png.php path/to/skin.css
 */

declare(strict_types=1);

require __DIR__ . '/../tests/bootstrap.php';

use Xblossia\ThemeSelector\Skin\PngTexture;
use Xblossia\ThemeSelector\Skin\Report;

$css = (string) @file_get_contents($argv[1] ?? '');
preg_match_all('#url\("data:image/png;base64,([A-Za-z0-9+/=]+)"\)#', $css, $m);
if ($m[1] === []) {
    fwrite(STDERR, "no embedded PNG found\n");
    exit(1);
}
foreach ($m[1] as $b64) {
    $png = base64_decode($b64, true);
    $r = new Report();
    if ($png === false || PngTexture::check('embedded', $png, $r, true) === null) {
        fwrite(STDERR, 'not clean: ' . implode(' | ', $r->errors()) . "\n");
        exit(1);
    }
}
exit(0);
