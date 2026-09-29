<?php

declare(strict_types=1);

use Xblossia\ThemeSelector\Skin\Limits;
use Xblossia\ThemeSelector\Skin\Report;
use Xblossia\ThemeSelector\Skin\ZipBundleReader;

function zip_case(string $what, string $bytes, ?string $mentions = null, bool $expectOk = false): void
{
    $r = new Report();
    $files = ZipBundleReader::parse($bytes, $r);
    if ($expectOk) {
        T::ok($what . ' is accepted', $files !== null && $r->ok(), implode(' | ', $r->errors()));

        return;
    }
    T::rejected($what, $files === null ? null : (object) $files, $r, $mentions);
}

function test_zip(): void
{
    T::group('zip: well-formed bundles');

    $ok = ZipBuilder::build([
        ['name' => 'skin.json', 'data' => good_manifest(), 'method' => 8],
        ['name' => 'skin.css', 'data' => good_css(), 'method' => 8],
        ['name' => 'graph.conf', 'data' => "graph_colours.greens=[\"FFFFFF\"]\n"],
        ['name' => 'fonts/', 'data' => ''],
        ['name' => 'fonts/a-b_c.woff2', 'data' => fake_font(), 'method' => 8],
    ]);
    $r = new Report();
    $files = ZipBundleReader::parse($ok, $r);
    T::ok('stored + deflated entries and a fonts/ directory are accepted', $files !== null, implode(' | ', $r->errors()));
    T::ok('contents round-trip exactly', $files !== null && $files['skin.css'] === good_css() && $files['fonts/a-b_c.woff2'] === fake_font());
    T::ok('directory entries are not returned as files', $files !== null && ! isset($files['fonts/']));

    zip_case('a data-descriptor entry (flag bit 3)', ZipBuilder::build([
        ['name' => 'skin.json', 'data' => good_manifest(), 'method' => 8, 'flags' => 0x0008],
        ['name' => 'skin.css', 'data' => good_css()],
    ]), null, true);

    T::group('zip: not a zip, or damaged');
    zip_case('random bytes', random_bytes(300), 'not a zip');
    zip_case('an empty zip (end record only)', "PK\x05\x06" . str_repeat("\0", 18), 'not a zip');
    zip_case('a truncated zip', substr($ok, 0, strlen($ok) - 30));
    zip_case('a zip with data appended', $ok . 'GARBAGE', 'after the archive');
    zip_case('a zip whose comment length lies', ZipBuilder::build([['name' => 'skin.css', 'data' => 'x']], ['comment' => 'hello']) . 'x');
    zip_case('a zip with two end records', ZipBuilder::build([['name' => 'skin.css', 'data' => 'x']], ['eocdCount' => 2]), 'ambiguous');
    zip_case('a directory offset that is off', ZipBuilder::build([['name' => 'skin.css', 'data' => 'x']], ['cdOffsetDelta' => 5]), 'inconsistent');
    zip_case('an entry count that lies', ZipBuilder::build([['name' => 'skin.css', 'data' => 'x']], ['entriesField' => 3]));
    zip_case('ZIP64 markers', 'PK' . "\x03\x04" . str_repeat("\0", 26) . "PK\x06\x06" . str_repeat("\0", 60), 'ZIP64');
    zip_case('an HTML file', '<html><script>alert(1)</script></html>');
    zip_case('a PHP file', '<?php system($_GET["c"]);');
    zip_case('a gzip file', gzencode('hello'));

    T::group('zip: names (allowlist, exact)');
    $bad = [
        '../evil.php', '/etc/passwd', 'fonts/../../x.woff2', 'skin.css/../x', '..\\evil', 'fonts\\a.woff2',
        "skin.css\0.php", 'skin.css.php', 'skin.php', 'a.php', 'index.php', '.htaccess', 'fonts/.htaccess',
        'fonts/x.php', 'fonts/x.woff2.php', 'fonts/x.php.woff2', 'fonts/x.svg', 'fonts/x.ttf', 'fonts/x.html',
        'fonts/sub/x.woff2', 'fonts/x', 'fonts/.woff2', 'SKIN.CSS', 'Skin.css', 'skin.CSS', 'skin.json.bak',
        'readme.txt', 'LICENSE', 'nested.zip', "skin.css\n", "skin.css ", " skin.css", "fonts/a.woff2\n",
        'skin.css:hidden', 'skin.css::$DATA', 'CON', "sk\u{0131}n.css", 'fonts/' . str_repeat('a', 70) . '.woff2', 'other/', 'fonts//a.woff2',
        './skin.css', 'fonts/./a.woff2', 'C:evil.css', '~/x', '%2e%2e/x', 'skin%2ecss',
    ];
    foreach ($bad as $name) {
        zip_case('entry name ' . json_encode($name), ZipBuilder::build([['name' => 'skin.json', 'data' => good_manifest()], ['name' => $name, 'data' => 'x']]), 'not allowed');
    }
    zip_case('one bad entry poisons an otherwise good bundle', ZipBuilder::build([
        ['name' => 'skin.json', 'data' => good_manifest()], ['name' => 'skin.css', 'data' => good_css()], ['name' => 'x.exe', 'data' => 'MZ'],
    ]), 'not allowed');

    T::group('zip: duplicates');
    zip_case('the same name twice', ZipBuilder::build([['name' => 'skin.css', 'data' => 'a'], ['name' => 'skin.css', 'data' => 'b']]), 'more than once');
    zip_case('names differing only in case', ZipBuilder::build([['name' => 'fonts/a.woff2', 'data' => fake_font()], ['name' => 'fonts/A.woff2', 'data' => fake_font()]]), 'more than once');

    T::group('zip: entry kinds and flags');
    zip_case('a symlink entry', ZipBuilder::build([['name' => 'skin.css', 'data' => '/etc/passwd', 'madeBy' => (3 << 8) | 20, 'extAttr' => (0120777 << 16)]]), 'not a regular file');
    zip_case('a device node entry', ZipBuilder::build([['name' => 'skin.css', 'data' => 'x', 'madeBy' => (3 << 8) | 20, 'extAttr' => (0020666 << 16)]]), 'not a regular file');
    zip_case('a regular Unix file entry', ZipBuilder::build([['name' => 'skin.css', 'data' => 'x', 'madeBy' => (3 << 8) | 20, 'extAttr' => (0100644 << 16)]]), null, true);
    zip_case('an encrypted entry', ZipBuilder::build([['name' => 'skin.css', 'data' => 'x', 'flags' => 0x0001]]), 'encrypted');
    zip_case('strong encryption flag', ZipBuilder::build([['name' => 'skin.css', 'data' => 'x', 'flags' => 0x0040]]), 'encrypted');
    zip_case('bzip2 compression', ZipBuilder::build([['name' => 'skin.css', 'data' => 'x', 'method' => 12, 'raw' => 'x']]), 'compression method');
    zip_case('a directory with content', ZipBuilder::build([['name' => 'fonts/', 'data' => 'hello']]), 'has content');

    T::group('zip: lying metadata');
    zip_case('a wrong CRC', ZipBuilder::build([['name' => 'skin.css', 'data' => 'hello', 'crc' => 12345]]), 'checksum');
    zip_case('a stored size that does not match', ZipBuilder::build([['name' => 'skin.css', 'data' => 'hello', 'usize' => 4]]), 'size');
    zip_case('a local header with a different name', ZipBuilder::build([['name' => 'skin.css', 'data' => 'hello', 'localName' => 'skin.php']]), 'disagrees');
    zip_case('a local header with different sizes', ZipBuilder::build([['name' => 'skin.css', 'data' => 'hello', 'localUsize' => 99]]), 'disagrees');
    zip_case('a local header with a different CRC', ZipBuilder::build([['name' => 'skin.css', 'data' => 'hello', 'localCrc' => 1]]), 'disagrees');
    zip_case('data that runs past the archive', ZipBuilder::build([['name' => 'skin.css', 'data' => 'hello', 'csize' => 9999, 'usize' => 9999, 'crc' => crc32('hello')]]));
    zip_case('a local offset pointing into the directory', ZipBuilder::build([['name' => 'skin.css', 'data' => 'hello', 'localOffset' => 999999]]));

    $one = ZipBuilder::build([['name' => 'skin.css', 'data' => 'hello world, hello world']]);
    zip_case('two entries sharing the same data (overlap)', ZipBuilder::build([
        ['name' => 'skin.css', 'data' => 'hello world, hello world'],
        ['name' => 'skin.json', 'data' => 'hello world, hello world', 'localOffset' => 0],
    ]), 'disagrees');

    // Entry B's whole record sits inside entry A's data, and the directory
    // points B at it. Neither local header is wrong on its own; the archive is
    // only wrong because A and B share bytes.
    $bLocal = "PK" . pack('vvvvvVVVvv', 20, 0, 0, 0, 0x21, crc32('hi'), 2, 2, 9, 0) . 'skin.json' . 'hi';
    zip_case('one entry hidden inside another (overlap)', ZipBuilder::build([
        ['name' => 'skin.css', 'data' => $bLocal],
        ['name' => 'skin.json', 'data' => 'hi', 'localOffset' => 30 + strlen('skin.css')],
    ]), 'overlap');

    T::group('zip: limits and decompression bombs');
    zip_case('more entries than allowed', ZipBuilder::build(array_map(fn ($i) => ['name' => "fonts/f$i.woff2", 'data' => fake_font()], range(1, Limits::ENTRIES + 1))), 'entries');
    zip_case('more fonts than allowed', ZipBuilder::build(array_map(fn ($i) => ['name' => "fonts/f$i.woff2", 'data' => fake_font()], range(1, Limits::FONTS + 1))), 'fonts');
    zip_case('a declared size over the per-file limit', ZipBuilder::build([['name' => 'skin.css', 'data' => 'x', 'usize' => Limits::CSS_BYTES + 1]]), 'larger than');
    zip_case('a font over its limit', ZipBuilder::build([['name' => 'fonts/a.woff2', 'data' => 'x', 'usize' => Limits::FONT_BYTES + 1]]), 'larger than');

    // A bomb: 40 MB of zeros deflates to ~40 KB. The entry declares a small
    // size, so it must be stopped as it expands, without producing 40 MB.
    $zeros = str_repeat("\0", 40 * 1024 * 1024);
    $bomb = gzdeflate($zeros, 9);
    unset($zeros);
    $mem = memory_get_peak_usage(true);
    zip_case('a decompression bomb with a small declared size', ZipBuilder::build([
        ['name' => 'skin.css', 'method' => 8, 'raw' => $bomb, 'data' => str_repeat('a', 100), 'usize' => 100, 'crc' => crc32(str_repeat('a', 100))],
    ]), 'expands beyond');
    T::ok('the bomb was stopped early (peak memory grew < 20 MB)', memory_get_peak_usage(true) - $mem < 20 * 1024 * 1024, 'grew ' . (memory_get_peak_usage(true) - $mem));
    zip_case('a bomb declaring its true (huge) size', ZipBuilder::build([
        ['name' => 'skin.css', 'method' => 8, 'raw' => $bomb, 'data' => 'x', 'usize' => 40 * 1024 * 1024, 'crc' => 0],
    ]), 'larger than');
    // Sizes that fit each limit but not the total.
    $big = ZipBuilder::build(array_map(fn ($i) => ['name' => "fonts/f$i.woff2", 'data' => 'x', 'usize' => 400_000, 'method' => 0, 'raw' => 'x', 'csize' => 400_000], range(1, 8)));
    zip_case('a declared total over the uncompressed limit', $big);
    zip_case('corrupt deflate data', ZipBuilder::build([['name' => 'skin.css', 'method' => 8, 'raw' => "\xff\xff\xff\xff\xff\xff", 'data' => 'hello', 'crc' => crc32('hello')]]), 'corrupt');
    zip_case('a deflate stream with trailing junk', ZipBuilder::build([['name' => 'skin.css', 'method' => 8, 'raw' => gzdeflate('hello') . 'JUNK', 'data' => 'hello', 'crc' => crc32('hello')]]));
    zip_case('a deflate stream cut short', ZipBuilder::build([['name' => 'skin.css', 'method' => 8, 'raw' => substr(gzdeflate(str_repeat('hello', 50)), 0, 5), 'data' => str_repeat('hello', 50), 'crc' => crc32(str_repeat('hello', 50))]]), 'corrupt');

    T::group('zip: the file entry points');
    $r = new Report();
    T::ok('a missing file is reported', ZipBundleReader::read('/nonexistent/file.zip', $r) === null && ! $r->ok());
    $r = new Report();
    $p = ZipBuilder::write(str_repeat('A', Limits::ARCHIVE_BYTES + 1));
    T::ok('an oversized archive is refused without being read', ZipBundleReader::read($p, $r) === null && stripos(implode(' ', $r->errors()), 'larger than') !== false);
    @unlink($p);
    $r = new Report();
    $p = ZipBuilder::write($ok);
    T::ok('a good archive on disk is read', ZipBundleReader::read($p, $r) !== null);
    @unlink($p);
}
