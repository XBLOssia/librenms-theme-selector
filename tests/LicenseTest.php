<?php

declare(strict_types=1);

use Xblossia\ThemeSelector\Skin\Limits;
use Xblossia\ThemeSelector\Skin\LicenseText;
use Xblossia\ThemeSelector\Skin\Report;
use Xblossia\ThemeSelector\Skin\SkinCompiler;

function test_license(): void
{
    $check = function (string $text): array {
        $r = new Report();

        return [LicenseText::check($text, $r), $r];
    };

    T::group('LICENSE.txt: text that is accepted');
    $ofl = "Copyright 2011 The Montserrat Project Authors (https://github.com/JulietaUla/Montserrat)\n\n"
        . "This Font Software is licensed under the SIL Open Font License, Version 1.1.\n"
        . "This license is copied below, and is also available with a FAQ at: https://openfontlicense.org\n\n"
        . "PREAMBLE\nThe goals of the Open Font License (OFL) are to stimulate worldwide development of collaborative font projects.\n";
    [$out, $r] = $check($ofl);
    T::ok('an OFL-style notice', $out === $ofl && $r->ok(), implode(' | ', $r->errors()));
    foreach ([
        'a copyright sign' => "Copyright \xc2\xa9 2026 Example",
        'curly quotes' => "\xe2\x80\x9cAs is\xe2\x80\x9d, with no warranty",
        'an email in angle brackets' => 'Contact: Example <name@example.org>',
        'markup characters, which are only ever shown escaped' => "Copyright (c) 2026 <b>Example</b> & Co <script>alert('x')</script>",
        'tabs and blank lines' => "Line one\n\n\tindented\n",
        'accented letters and CJK' => "Copyright 2026 \xc3\x89cole \xe6\x97\xa5\xe6\x9c\xac",
        'a non-breaking space' => "Copyright\xc2\xa02026",
    ] as $what => $text) {
        [$out, $r] = $check($text);
        T::ok("accepts $what", $out !== null && $r->ok(), implode(' | ', $r->errors()));
    }
    [$out] = $check("Line one\r\nLine two\rLine three");
    T::ok('line endings are normalised to \n', $out === "Line one\nLine two\nLine three");
    [$out] = $check(str_repeat('a', Limits::LICENSE_BYTES));
    T::ok('a notice at the size limit is accepted', $out !== null);

    T::group('LICENSE.txt: text that is refused');
    foreach ([
        'a NUL byte' => ["Copyright\0 2026", 'control'],
        'a terminal escape sequence' => ["Copyright \x1b[31m2026", 'control'],
        'a form feed' => ["Copyright\x0c2026", 'control'],
        'a DEL character' => ["Copyright\x7f2026", 'control'],
        'a bidirectional override (text spoofing)' => ["Copyright \xe2\x80\xae2026", 'control'],
        'a bidirectional isolate' => ["Copyright \xe2\x81\xa62026", 'control'],
        'a zero-width space' => ["Copy\xe2\x80\x8bright", 'control'],
        'a zero-width joiner' => ["Copy\xe2\x80\x8dright", 'control'],
        'a byte order mark in the middle' => ["Copy\xef\xbb\xbfright", 'control'],
        'a private-use code point' => ["Copyright \xee\x80\x80", 'control'],
        'an unassigned code point' => ["Copyright \xcd\xb8", 'control'],
        'a line separator' => ["Copyright\xe2\x80\xa8" . '2026', 'control'],
        'invalid UTF-8' => ["Copyright \xff\xfe 2026", 'UTF-8'],
        'an overlong UTF-8 encoding' => ["Copyright \xc0\xaf 2026", 'UTF-8'],
        'a truncated UTF-8 sequence' => ["Copyright \xe2\x80", 'UTF-8'],
        'an empty file' => ['', 'empty'],
        'only whitespace' => ["  \n\t \r\n", 'empty'],
        'a file over the size limit' => [str_repeat('a', Limits::LICENSE_BYTES + 1), 'larger than'],
    ] as $what => [$text, $mentions]) {
        [$out, $r] = $check($text);
        T::ok("refuses $what", $out === null && ! $r->ok(), 'was accepted');
        T::ok("and says why for $what", stripos(implode(' ', $r->errors()), $mentions) !== false, implode(' | ', $r->errors()));
    }

    T::group('LICENSE.txt: in the zip');
    $zip = fn (array $extra) => ZipBuilder::build(array_merge([
        ['name' => 'skin.json', 'data' => good_manifest(), 'method' => 8],
        ['name' => 'skin.css', 'data' => good_css(), 'method' => 8],
    ], $extra));
    $r = new Report();
    $files = Xblossia\ThemeSelector\Skin\ZipBundleReader::parse($zip([['name' => 'LICENSE.txt', 'data' => $ofl, 'method' => 8]]), $r);
    T::ok('an exact LICENSE.txt entry is read', $files !== null && ($files['LICENSE.txt'] ?? null) === $ofl, implode(' | ', $r->errors()));
    foreach (['license.txt', 'LICENSE', 'LICENCE.txt', 'LICENSE.TXT', 'License.txt', 'LICENSE.txt.php', 'LICENSE.php', 'LICENSE.txt ', "LICENSE.txt\n",
              'fonts/LICENSE.txt', 'docs/LICENSE.txt', '../LICENSE.txt', '/LICENSE.txt', 'LICENSE.txt/', 'NOTICE.txt', 'OFL.txt', 'fonts/OFL.txt', 'COPYING'] as $name) {
        zip_case('entry name ' . json_encode($name), $zip([['name' => $name, 'data' => 'x']]), 'not allowed');
    }
    zip_case('a LICENSE.txt over its declared limit', $zip([['name' => 'LICENSE.txt', 'data' => 'x', 'usize' => Limits::LICENSE_BYTES + 1]]), 'larger than');

    T::group('LICENSE.txt: through the compiler');
    $compiler = new SkinCompiler(catalog());
    $base = ['skin.json' => good_manifest(), 'skin.css' => good_css()];
    $r = new Report();
    $with = $compiler->compileFiles($base + ['LICENSE.txt' => "Line one\r\nLine two"], $r);
    T::ok('a bundle with a notice compiles', $with !== null, implode(' | ', $r->errors()));
    T::ok('the notice is kept, normalised', $with !== null && $with->licenseText === "Line one\nLine two");
    $r = new Report();
    $without = $compiler->compileFiles($base, $r);
    T::ok('a bundle without one has an empty notice', $without !== null && $without->licenseText === '');
    T::ok('the notice changes the fingerprint', $with !== null && $without !== null && $with->sha256 !== $without->sha256);
    T::ok('and the stylesheet is the same either way', $with !== null && $without !== null && $with->css === $without->css);
    T::ok('the notice never appears in the generated stylesheet', $with !== null && ! str_contains($with->css, 'Line one'));
    $r = new Report();
    $bad = $compiler->compileFiles($base + ['LICENSE.txt' => "Copyright \x1b[31m"], $r);
    T::ok('a bad notice refuses the whole bundle', $bad === null && stripos(implode(' ', $r->errors()), 'LICENSE.txt') !== false);
    $r = new Report();
    $markup = $compiler->compileFiles($base + ['LICENSE.txt' => "<script>alert('x')</script>"], $r);
    T::ok('markup in a notice is data: accepted, stored as written', $markup !== null && $markup->licenseText === "<script>alert('x')</script>");
}
