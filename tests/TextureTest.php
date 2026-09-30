<?php

declare(strict_types=1);

use Xblossia\ThemeSelector\Skin\Limits;
use Xblossia\ThemeSelector\Skin\Mode;
use Xblossia\ThemeSelector\Skin\OutputGuard;
use Xblossia\ThemeSelector\Skin\PngTexture;
use Xblossia\ThemeSelector\Skin\Report;
use Xblossia\ThemeSelector\Skin\SkinCompiler;
use Xblossia\ThemeSelector\Skin\ZipBundleReader;

function png_ok(string $what, string $bytes, bool $strict = false): ?array
{
    $r = new Report();
    $t = PngTexture::check('textures/t.png', $bytes, $r, $strict);
    T::ok("$what is accepted", $t !== null && $r->ok(), implode(' | ', $r->errors()));

    return $t;
}

function png_bad(string $what, string $bytes, ?string $mentions = null, bool $strict = false): void
{
    $r = new Report();
    $t = PngTexture::check('textures/t.png', $bytes, $r, $strict);
    T::ok("$what is refused", $t === null && ! $r->ok(), 'was accepted');
    if ($mentions !== null && $t === null) {
        T::ok("$what explains why ('$mentions')", stripos(implode(' ', $r->errors()), $mentions) !== false, implode(' | ', $r->errors()));
    }
}

function test_textures(): void
{
    T::group('textures: PNGs that are accepted');
    foreach ([
        'an RGBA tile, 64x64' => ['w' => 64, 'h' => 64],
        'an RGBA tile, 256x256' => ['w' => 256, 'h' => 256],
        'a non-square tile' => ['w' => 128, 'h' => 32],
        'a 1x1 pixel' => ['w' => 1, 'h' => 1],
        'an RGB tile' => ['type' => 2],
        'greyscale' => ['type' => 0],
        'greyscale, 4 bits' => ['type' => 0, 'depth' => 4, 'w' => 33],
        'greyscale with alpha' => ['type' => 4],
        'a palette tile, 8 bits' => ['type' => 3, 'palette' => str_repeat("\x10\x20\x30", 200)],
        'a palette tile, 2 bits, with transparency' => ['type' => 3, 'depth' => 2, 'w' => 31, 'trns' => "\x00\x80"],
        'greyscale with a transparent colour' => ['type' => 0, 'trns' => "\x00\x07"],
        'an RGB tile with a transparent colour' => ['type' => 2, 'trns' => "\x00\x01\x00\x02\x00\x03"],
        'image data split over two IDAT chunks' => ['between' => []],
    ] as $what => $opts) {
        $t = png_ok($what, png_build($opts));
        if ($t !== null) {
            T::ok("$what: the result is a clean PNG that passes the strict check", PngTexture::check('x', $t['png'], new Report(), true) !== null);
            T::ok("$what: cleaning it twice changes nothing", PngTexture::check('x', $t['png'], new Report()) ['png'] === $t['png']);
            T::ok("$what: it reports its size", $t['width'] === ($opts['w'] ?? 64) && $t['height'] === ($opts['h'] ?? 64));
        }
    }
    $big = png_ok('a tile with an unusual size', png_build(['w' => 100, 'h' => 37, 'type' => 6]));

    T::group('textures: what cleaning drops');
    $meta = [['tEXt', "Comment\0hello"], ['gAMA', pack('N', 45455)], ['pHYs', pack('NNC', 2835, 2835, 1)], ['iCCP', "x\0\0garbage"], ['eXIf', 'II*secret'], ['tIME', str_repeat("\1", 7)], ['sRGB', "\0"], ['zTXt', "k\0\0z"], ['bKGD', "\0\0"]];
    $dirty = png_build(['before' => $meta, 'after' => [['tEXt', "After\0data"]]]);
    $t = png_ok('a PNG with metadata chunks', $dirty);
    T::ok('none of it is in the result', $t !== null && ! str_contains($t['png'], 'tEXt') && ! str_contains($t['png'], 'eXIf') && ! str_contains($t['png'], 'iCCP') && ! str_contains($t['png'], 'hello') && ! str_contains($t['png'], 'secret'));
    T::ok('and the result is smaller than the input', $t !== null && strlen($t['png']) < strlen($dirty));
    png_bad('the same PNG under the strict check (for textures shipped in this package)', $dirty, 'extra chunk', true);
    $t = png_ok('a PNG with an unused palette in an RGBA image', png_build(['type' => 6, 'palette' => "\1\2\3\4\5\6"]));
    T::ok('the palette is dropped from it', $t !== null && ! str_contains($t['png'], 'PLTE'));

    T::group('textures: PNGs that are refused');
    $good = png_build([]);
    png_bad('an empty file', '', 'signature');
    png_bad('text', 'hello world', 'signature');
    png_bad('a GIF', "GIF89a" . str_repeat("\0", 64), 'signature');
    png_bad('a JPEG', "\xff\xd8\xff\xe0" . str_repeat("\0", 64), 'signature');
    png_bad('an SVG', '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>', 'signature');
    png_bad('PHP', '<?php system($_GET[0]); ?>', 'signature');
    png_bad('a PNG signature and nothing else', "\x89PNG\r\n\x1a\n", 'IEND');
    png_bad('a PNG cut short', substr($good, 0, 40), 'truncated');
    png_bad('a PNG cut in the IEND chunk', substr($good, 0, -5), 'truncated');
    png_bad('a chunk that claims to be longer than the file', "\x89PNG\r\n\x1a\n" . pack('N', 999999) . 'IHDR' . str_repeat("\0", 20), 'longer than the file');
    png_bad('a bad checksum on IHDR', png_build(['badIhdrCrc' => true]), 'checksum');
    png_bad('a bad checksum on a metadata chunk', png_build(['before' => [['tEXt', 'a', true]]]), 'checksum');
    png_bad('a PNG with no IEND', png_build(['skipIend' => true]), 'IEND');
    png_bad('data after IEND', png_build(['tail' => '<?php echo 1; ?>']), 'after its IEND');
    png_bad('a PNG followed by another PNG', $good . $good, 'after its IEND');
    png_bad('an IEND chunk with data in it', png_build(['iendData' => 'x']), 'IEND chunk with data');
    png_bad('a chunk with a non-letter type', png_build(['before' => [["\x01\x02\x03\x04", 'x']]]), 'invalid type');
    png_bad('a file that does not start with IHDR', "\x89PNG\r\n\x1a\n" . png_chunk('tEXt', 'x') . png_chunk('IEND', ''), 'IHDR');
    png_bad('two IHDR chunks', png_build(['before' => [['IHDR', pack('NNCCCCC', 8, 8, 8, 6, 0, 0, 0)]]]), 'more than one IHDR');
    png_bad('a width of 0', png_build(['w' => 0, 'raw' => '']), 'px');
    png_bad('a height of 0', png_build(['h' => 0, 'raw' => '']), 'px');
    png_bad('a tile 257 wide', png_build(['w' => 257, 'h' => 2]), '257x2');
    png_bad('a tile 257 tall', png_build(['w' => 2, 'h' => 257]), '2x257');
    png_bad('a huge declared size over a tiny stream (a decompression bomb)', png_build(['w' => 60000, 'h' => 60000, 'raw' => 'x']), '60000x60000');
    png_bad('an interlaced PNG', png_build(['interlace' => 1]), 'interlaced');
    foreach ([1, 5, 7, 8, 255] as $ct) {
        png_bad("colour type $ct", png_build(['type' => $ct, 'raw' => str_repeat("\0", 100)]), 'colour type');
    }
    png_bad('16-bit greyscale', png_build(['type' => 0, 'depth' => 16]), 'bit depth');
    png_bad('16-bit RGBA', png_build(['type' => 6, 'depth' => 16]), 'bit depth');
    png_bad('4-bit RGB', png_build(['type' => 2, 'depth' => 4]), 'bit depth');
    png_bad('a bit depth of 3', png_build(['type' => 0, 'depth' => 3]), 'bit depth');
    png_bad('a bit depth of 0', png_build(['type' => 0, 'depth' => 0]), 'bit depth');
    png_bad('a compression method other than 0', png_build(['compression' => 1]), 'header');
    png_bad('a filter method other than 0', png_build(['filter' => 1]), 'header');
    png_bad('an animated PNG (acTL)', png_build(['before' => [['acTL', pack('NN', 2, 0)]]]), 'animated');
    png_bad('an animated PNG (fcTL)', png_build(['after' => [['fcTL', str_repeat("\0", 26)]]]), 'animated');
    png_bad('an animated PNG (fdAT)', png_build(['after' => [['fdAT', "\0\0\0\1abc"]]]), 'animated');
    png_bad('an unknown critical chunk', png_build(['before' => [['XXXX', 'x']]]), 'critical chunk');
    png_bad('a palette image with no palette', png_build(['type' => 3, 'palette' => null]), 'no palette') ;
    png_bad('a palette that is not a multiple of 3', png_build(['type' => 3, 'palette' => "\1\2\3\4"]), 'palette');
    png_bad('an empty palette', png_build(['type' => 3, 'palette' => '']), 'palette');
    png_bad('a palette with 257 entries', png_build(['type' => 3, 'palette' => str_repeat("\1\2\3", 257)]), 'palette');
    png_bad('a 2-bit palette image with 5 entries', png_build(['type' => 3, 'depth' => 2, 'palette' => str_repeat("\1\2\3", 5)]), 'bit depth allows');
    png_bad('a palette in a greyscale image', png_build(['type' => 0, 'palette' => "\1\2\3"]), 'greyscale');
    png_bad('a palette in a greyscale+alpha image', png_build(['type' => 4, 'palette' => "\1\2\3"]), 'greyscale');
    png_bad('a tRNS chunk on an RGBA image', png_build(['type' => 6, 'trns' => "\0\1"]), 'tRNS');
    png_bad('a tRNS chunk of the wrong length for greyscale', png_build(['type' => 0, 'trns' => "\0"]), 'tRNS');
    png_bad('a tRNS chunk of the wrong length for RGB', png_build(['type' => 2, 'trns' => "\0\0"]), 'tRNS');
    png_bad('a tRNS chunk longer than the palette', png_build(['type' => 3, 'depth' => 8, 'palette' => "\1\2\3\4\5\6", 'trns' => "\1\2\3"]), 'tRNS');
    png_bad('a PLTE chunk after the image data', png_build(['type' => 6, 'palette' => null, 'after' => [['PLTE', "\1\2\3"]]]) , null);
    png_bad('a chunk between two IDAT chunks', png_build(['between' => [['tEXt', 'a']]]), 'split');
    png_bad('no image data', "\x89PNG\r\n\x1a\n" . png_chunk('IHDR', pack('NNCCCCC', 8, 8, 8, 6, 0, 0, 0)) . png_chunk('IEND', ''), 'no image data');
    png_bad('pixel data one byte short', png_build(['w' => 8, 'h' => 8, 'raw' => substr(png_raw(8, 8), 0, -1)]), 'does not match');
    png_bad('pixel data one byte long', png_build(['w' => 8, 'h' => 8, 'raw' => png_raw(8, 8) . "\0"]), 'does not match');
    png_bad('pixel data for a smaller image', png_build(['w' => 8, 'h' => 8, 'raw' => png_raw(4, 4)]), 'does not match');
    png_bad('a row filter of 5', png_build(['w' => 4, 'h' => 2, 'raw' => "\5" . str_repeat("\0", 16) . "\0" . str_repeat("\0", 16)]), 'row filter');
    png_bad('a row filter of 255 on the last row', png_build(['w' => 4, 'h' => 2, 'raw' => "\0" . str_repeat("\0", 16) . "\xff" . str_repeat("\0", 16)]), 'row filter');
    png_bad('a corrupt zlib stream', png_build(['w' => 8, 'h' => 8, 'idat' => "\x78\x9c" . random_bytes(40)]), 'does not match');
    png_bad('a zlib stream that is not zlib', png_build(['w' => 8, 'h' => 8, 'idat' => str_repeat('A', 100)]), 'does not match');
    png_bad('a zlib stream with bytes after its end', png_build(['w' => 8, 'h' => 8, 'idat' => gzcompress(png_raw(8, 8), 9) . '<?php echo 1; ?>']), 'does not match');
    png_bad('a zlib stream cut short', png_build(['w' => 8, 'h' => 8, 'idat' => substr(gzcompress(png_raw(8, 8), 9), 0, -6)]), 'does not match');
    png_bad('a raw-deflate stream (no zlib header)', png_build(['w' => 8, 'h' => 8, 'idat' => gzdeflate(png_raw(8, 8), 9)]), 'does not match');
    png_bad('a decompression bomb with the right header (a 40 MB stream of zeros)', png_build(['w' => 256, 'h' => 256, 'idat' => gzcompress(str_repeat("\0", 40 * 1024 * 1024), 9)]), 'does not match');
    png_bad('a file over the input limit', png_build(['tail' => str_repeat('x', Limits::TEXTURE_INPUT_BYTES)]), 'larger than');
    png_bad('noise that is too big once cleaned', png_build(['w' => 200, 'h' => 200, 'noise' => true]), 'once cleaned');

    // A bomb must be stopped early: memory while refusing it stays small.
    if (function_exists('memory_reset_peak_usage')) {
        $bomb = png_build(['w' => 256, 'h' => 256, 'idat' => gzcompress(str_repeat("\0", 64 * 1024 * 1024), 9)]);
        gc_collect_cycles();
        memory_reset_peak_usage();
        $base = memory_get_usage();
        PngTexture::check('bomb', $bomb, new Report());
        T::ok('refusing a 64 MB bomb costs under 8 MB of memory', memory_get_peak_usage() - $base < 8 * 1024 * 1024, (string) (memory_get_peak_usage() - $base));
    }

    T::group('textures: in the zip');
    $zip = fn (array $extra) => ZipBuilder::build(array_merge([
        ['name' => 'skin.json', 'data' => good_manifest(), 'method' => 8],
        ['name' => 'skin.css', 'data' => good_css(), 'method' => 8],
    ], $extra));
    $png = png_build(['w' => 8, 'h' => 8]);
    $r = new Report();
    $files = ZipBundleReader::parse($zip([['name' => 'textures/creep-1.png', 'data' => $png, 'method' => 8]]), $r);
    T::ok('an exact textures/<name>.png entry is read', $files !== null && ($files['textures/creep-1.png'] ?? null) === $png, implode(' | ', $r->errors()));
    foreach (['textures/Creep.png', 'textures/creep.PNG', 'textures/creep.png.php', 'textures/creep.php', 'textures/creep.gif', 'textures/creep.svg', 'textures/creep.webp', 'textures/creep.jpg',
              'textures/a/b.png', 'textures/.png', 'textures/-a.png', 'textures/a_b.png', 'textures/a b.png', 'textures/' . str_repeat('a', 42) . '.png', 'texture/creep.png', 'Textures/creep.png',
              'textures/creep.png/', 'textures/creep.png ', "textures/creep.png\n", '../textures/creep.png', '/textures/creep.png', 'fonts/creep.png', 'skins/textures/creep.png', 'textures', 'textures/'] as $name) {
        zip_case('entry name ' . json_encode($name), $zip([['name' => $name, 'data' => $png]]), 'not allowed');
    }
    zip_case('a texture over its declared limit', $zip([['name' => 'textures/t.png', 'data' => 'x', 'usize' => Limits::TEXTURE_INPUT_BYTES + 1]]), 'larger than');

    T::group('textures: through the compiler');
    $compiler = new SkinCompiler(catalog());
    $css = good_css() . "\nhtml.dark {\n  --tx-creep: url(\"textures/creep.png\");\n  --ts-body-bg-image: var(--tx-creep);\n}\n";
    $files = ['skin.json' => good_manifest(), 'skin.css' => $css, 'textures/creep.png' => png_build(['w' => 64, 'h' => 32, 'type' => 6, 'trns' => null ?? null])];
    unset($files['textures/creep.png']);
    $files['textures/creep.png'] = png_build(['w' => 64, 'h' => 32]);
    $r = new Report();
    $c = $compiler->compileFiles($files, $r);
    T::ok('a bundle with a texture compiles', $c !== null, implode(' | ', $r->errors()));
    if ($c !== null) {
        T::ok('the stylesheet embeds it as a data: URL and has no file reference', str_contains($c->css, 'url("data:image/png;base64,') && ! str_contains($c->css, 'textures/'));
        T::ok('the output guard accepts the output', OutputGuard::safe($c->css, 0, 1));
        T::ok('the texture is reported (name and size)', $c->textures === [['name' => 'creep', 'width' => 64, 'height' => 32, 'bytes' => $c->textures[0]['bytes']]] && $c->textures[0]['bytes'] > 50);
        $again = $compiler->compileFiles(['textures/creep.png' => png_build(['w' => 64, 'h' => 32, 'before' => [['tEXt', 'junk']]])] + $files, new Report());
        T::ok('metadata in the upload does not change what is served', $again !== null && $again->css === $c->css);
        $other = $compiler->compileFiles(['textures/creep.png' => png_build(['w' => 64, 'h' => 32, 'type' => 2])] + $files, new Report());
        T::ok('a different texture changes the stylesheet and the fingerprint', $other !== null && $other->css !== $c->css && $other->sha256 !== $c->sha256);
    }
    $r = new Report();
    $b = $compiler->compileFiles($files, $r, Mode::Bundled);
    T::ok('as a bundled skin it keeps the file reference and embeds nothing', $b === null ? false : (str_contains($b->css, 'url("textures/creep.png")') && ! str_contains($b->css, 'data:image')), implode(' | ', $r->errors()));
    $r = new Report();
    $dirtyBundle = $compiler->compileFiles(['textures/creep.png' => png_build(['w' => 64, 'h' => 32, 'before' => [['tEXt', 'x']]])] + $files, $r, Mode::Bundled);
    T::ok('a bundled texture with metadata is refused (it is served as a file, so it must be clean)', $dirtyBundle === null && stripos(implode(' ', $r->errors()), 'extra chunk') !== false);

    $tx = function (string $cssText, array $extra = []) use ($compiler, &$r) {
        $r = new Report();

        return $compiler->compileFiles(['skin.json' => good_manifest(), 'skin.css' => good_css() . $cssText] + $extra, $r);
    };
    $badCases = [
        'a texture file that is declared nowhere' => ["\nhtml.dark {\n  --ts-body-bg-size: 64px;\n}\n", ['textures/creep.png' => $png], 'not declared'],
        'a declaration with no file in the bundle' => ["\nhtml.dark {\n  --tx-creep: url(\"textures/creep.png\");\n  --ts-body-bg-image: var(--tx-creep);\n}\n", [], 'not in the bundle'],
        'a texture that nothing uses' => ["\nhtml.dark {\n  --tx-creep: url(\"textures/creep.png\");\n}\n", ['textures/creep.png' => $png], 'no token uses it'],
        'a texture used only by an unused palette entry' => ["\nhtml.dark {\n  --tx-creep: url(\"textures/creep.png\");\n  --p-x: var(--tx-creep);\n}\n", ['textures/creep.png' => $png], 'no token uses it'],
        'var() of a texture that is not declared' => ["\nhtml.dark {\n  --ts-body-bg-image: var(--tx-nope);\n}\n", [], 'not defined'],
        'a texture in a colour token' => ["\nhtml.dark {\n  --tx-creep: url(\"textures/creep.png\");\n  --ts-link-hover-fg: var(--tx-creep);\n}\n", ['textures/creep.png' => $png], 'only image tokens'],
        'a texture in a colour token, through the palette' => ["\nhtml.dark {\n  --tx-creep: url(\"textures/creep.png\");\n  --p-x: var(--tx-creep);\n  --ts-well-bg: var(--p-x);\n}\n", ['textures/creep.png' => $png], 'only image tokens'],
        'a texture in a font token' => ["\nhtml.dark {\n  --tx-creep: url(\"textures/creep.png\");\n  --ts-input-font-family: var(--tx-creep);\n}\n", ['textures/creep.png' => $png], 'only image tokens'],
        'a remote url' => ["\nhtml.dark {\n  --tx-creep: url(\"http://evil.example/a.png\");\n  --ts-body-bg-image: var(--tx-creep);\n}\n", ['textures/creep.png' => $png], 'exactly url("textures/creep.png")'],
        'a data: url' => ["\nhtml.dark {\n  --tx-creep: url(\"data:image/png;base64,AAAA\");\n  --ts-body-bg-image: var(--tx-creep);\n}\n", ['textures/creep.png' => $png], 'exactly url('],
        'a url to a different name' => ["\nhtml.dark {\n  --tx-creep: url(\"textures/other.png\");\n  --ts-body-bg-image: var(--tx-creep);\n}\n", ['textures/creep.png' => $png, 'textures/other.png' => $png], 'with the same name'],
        'a path with ..' => ["\nhtml.dark {\n  --tx-creep: url(\"textures/../skin.css\");\n  --ts-body-bg-image: var(--tx-creep);\n}\n", ['textures/creep.png' => $png], 'exactly url('],
        'an unquoted url' => ["\nhtml.dark {\n  --tx-creep: url(textures/creep.png);\n  --ts-body-bg-image: var(--tx-creep);\n}\n", ['textures/creep.png' => $png], 'exactly url('],
        'a texture next to a gradient in one declaration' => ["\nhtml.dark {\n  --tx-creep: url(\"textures/creep.png\"), linear-gradient(red, blue);\n  --ts-body-bg-image: var(--tx-creep);\n}\n", ['textures/creep.png' => $png], 'exactly url('],
        'an uppercase texture name' => ["\nhtml.dark {\n  --tx-Creep: url(\"textures/Creep.png\");\n  --ts-body-bg-image: var(--tx-Creep);\n}\n", [], 'valid texture name'],
        'a texture name with an underscore' => ["\nhtml.dark {\n  --tx-a_b: url(\"textures/a_b.png\");\n  --ts-body-bg-image: var(--tx-a_b);\n}\n", [], 'valid texture name'],
        'the same texture declared twice' => ["\nhtml.dark {\n  --tx-creep: url(\"textures/creep.png\");\n  --tx-creep: url(\"textures/creep.png\");\n  --ts-body-bg-image: var(--tx-creep);\n}\n", ['textures/creep.png' => $png], 'more than once'],
        'a texture with a fallback in var()' => ["\nhtml.dark {\n  --tx-creep: url(\"textures/creep.png\");\n  --ts-body-bg-image: var(--tx-creep, none);\n}\n", ['textures/creep.png' => $png], 'var()'],
        'a url() that is not a texture, in an image token' => ["\nhtml.dark {\n  --ts-body-bg-image: url(\"textures/creep.png\");\n}\n", ['textures/creep.png' => $png], 'url'],
    ];
    foreach ($badCases as $what => [$cssText, $extra, $mentions]) {
        $res = $tx($cssText, $extra);
        T::ok("$what is refused", $res === null, 'was accepted');
        if ($res === null) {
            T::ok("$what explains why ('$mentions')", stripos(implode(' ', $r->errors()), $mentions) !== false, implode(' | ', $r->errors()));
        }
    }
    $five = ['skin.json' => good_manifest(), 'skin.css' => good_css() . "\nhtml.dark {\n" . implode('', array_map(fn ($i) => "  --tx-t$i: url(\"textures/t$i.png\");\n", range(1, 5))) . "  --ts-body-bg-image: var(--tx-t1), var(--tx-t2), var(--tx-t3), var(--tx-t4), var(--tx-t5);\n}\n"];
    foreach (range(1, 5) as $i) {
        $five["textures/t$i.png"] = $png;
    }
    $r = new Report();
    T::ok('five textures are refused (the limit is four)', $compiler->compileFiles($five, $r) === null && stripos(implode(' ', $r->errors()), 'more than 4') !== false, implode(' | ', $r->errors()));
    $r = new Report();
    $noisy = png_build(['w' => 100, 'h' => 100, 'type' => 0, 'noise' => true]);
    $four = ['skin.json' => good_manifest(), 'skin.css' => good_css() . "\nhtml.dark {\n" . implode('', array_map(fn ($i) => "  --tx-t$i: url(\"textures/t$i.png\");\n", range(1, 4))) . "  --ts-body-bg-image: var(--tx-t1), var(--tx-t2), var(--tx-t3), var(--tx-t4);\n}\n"];
    foreach (range(1, 4) as $i) {
        $four["textures/t$i.png"] = png_build(['w' => 200, 'h' => 200, 'type' => 0, 'noise' => true]);
    }
    T::ok('four textures together over the total limit are refused', $compiler->compileFiles($four, $r) === null && stripos(implode(' ', $r->errors()), 'totalling') !== false, implode(' | ', $r->errors()));

    $r = new Report();
    $viaPalette = $compiler->compileFiles(['skin.json' => good_manifest(), 'skin.css' => good_css() . "\nhtml.dark {\n  --tx-tile: url(\"textures/tile.png\");\n  --p-bg: var(--tx-tile), linear-gradient(180deg, #000 0%, #111 100%);\n  --ts-body-bg-image: var(--p-bg);\n  --ts-body-bg-size: 128px 128px, auto;\n}\n", 'textures/tile.png' => $png], $r);
    T::ok('a texture used through the palette in the page background is accepted', $viaPalette !== null, implode(' | ', $r->errors()));
    $r = new Report();
    $layered = $compiler->compileFiles(['skin.json' => good_manifest(), 'skin.css' => good_css() . "\nhtml.dark {\n  --tx-a: url(\"textures/a.png\");\n  --tx-b: url(\"textures/b.png\");\n  --ts-body-bg-image: var(--tx-a), var(--tx-b);\n  --ts-widget-bg-image: var(--tx-b);\n  --ts-panel-bg-image: var(--tx-a);\n}\n", 'textures/a.png' => $png, 'textures/b.png' => $png], $r);
    T::ok('several textures, used by several image tokens, are accepted', $layered !== null, implode(' | ', $r->errors()));
    css_good('the page background size at its limit', dark('--ts-bg: #000;', '--ts-body-bg-size: 512px 512px;', '--ts-body-bg-position: 256px 0;', '--ts-body-bg-repeat: repeat-x;'));
    css_bad('a page background size over the limit', dark('--ts-bg: #000;', '--ts-body-bg-size: 513px 10px;'), 'out of range');

    T::group('textures: the output guard');
    $sheet = fn (string $decl) => "/* generated */\nhtml.dark {\n  $decl\n  --ts-bg: #000;\n}\n";
    $b64 = base64_encode(PngTexture::check('x', png_build(['w' => 8, 'h' => 8]), new Report())['png']);
    $line = "--tx-a: url(\"data:image/png;base64,$b64\");";
    T::ok('a stylesheet with one embedded texture is safe', OutputGuard::safe($sheet($line), 0, 1));
    T::ok('the same stylesheet, expected to have none, is not', ! OutputGuard::safe($sheet($line), 0, 0));
    T::ok('one with two expected, is not', ! OutputGuard::safe($sheet($line), 0, 2));
    T::ok('a texture whose data is not a PNG is not safe', ! OutputGuard::safe($sheet('--tx-a: url("data:image/png;base64,' . base64_encode('<?php echo 1;') . '");'), 0, 1));
    T::ok('a texture with bytes after IEND is not safe', ! OutputGuard::safe($sheet('--tx-a: url("data:image/png;base64,' . base64_encode(png_build(['w' => 8, 'h' => 8, 'tail' => 'x']) ) . '");'), 0, 1));
    T::ok('a texture that still has metadata is not safe', ! OutputGuard::safe($sheet('--tx-a: url("data:image/png;base64,' . base64_encode(png_build(['w' => 8, 'h' => 8, 'before' => [['tEXt', 'x']]])) . '");'), 0, 1));
    T::ok('a data: URL of another type is not safe', ! OutputGuard::safe($sheet('--tx-a: url("data:image/svg+xml;base64,' . base64_encode('<svg/>') . '");'), 0, 1));
    T::ok('a remote url is not safe', ! OutputGuard::safe($sheet('--tx-a: url("http://evil.example/a.png");'), 0, 1));
    T::ok('an extra url() is not safe', ! OutputGuard::safe($sheet($line . "\n  --ts-bg-image: url(\"data:image/png;base64,$b64\");"), 0, 1));
    T::ok('a texture declared outside the --tx- shape is not safe', ! OutputGuard::safe($sheet("--ts-body-bg-image: url(\"data:image/png;base64,$b64\");"), 0, 1));
    T::ok('a texture name that is not lowercase is not safe', ! OutputGuard::safe($sheet(str_replace('--tx-a', '--tx-A', $line)), 0, 1));
    T::ok('an indented or trailing-junk declaration is not safe', ! OutputGuard::safe($sheet($line . ' x'), 0, 1));

    T::group('textures: the bundled skins');
    foreach (glob(__DIR__ . '/../skins/*/textures/*.png') ?: [] as $file) {
        $t = png_ok('bundled ' . basename(dirname(dirname($file))) . '/' . basename($file), (string) file_get_contents($file), true);
        T::ok(basename($file) . ' is within the limits', $t !== null && strlen($t['png']) <= Limits::TEXTURE_BYTES && $t['width'] <= PngTexture::MAX_SIDE && $t['height'] <= PngTexture::MAX_SIDE);
    }
}
