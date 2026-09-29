<?php

/*
 * Test bootstrap: an autoloader for src/, a tiny assertion harness, and a zip
 * BUILDER that can produce deliberately malformed archives.
 *
 * The validator tests need archives no normal tool will write: names with
 * "..", symlink entries, sizes that lie, overlapping data, two end records.
 * ZipBuilder writes those from raw bytes.
 *
 * Run everything with:   php tests/run.php
 */

declare(strict_types=1);

spl_autoload_register(function (string $class): void {
    $prefix = 'Xblossia\\ThemeSelector\\';
    if (str_starts_with($class, $prefix)) {
        $file = __DIR__ . '/../src/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
        if (is_file($file)) {
            require $file;
        }
    }
});

use Xblossia\ThemeSelector\Skin\Report;

final class T
{
    public static int $passed = 0;
    public static int $failed = 0;
    public static string $group = '';
    /** @var string[] */
    public static array $failures = [];

    public static function group(string $name): void
    {
        self::$group = $name;
        echo "\n== $name\n";
    }

    public static function ok(string $what, bool $cond, string $detail = ''): void
    {
        if ($cond) {
            self::$passed++;

            return;
        }
        self::$failed++;
        $line = '  FAIL  ' . $what . ($detail !== '' ? "\n        $detail" : '');
        self::$failures[] = self::$group . ': ' . $what;
        echo $line . "\n";
    }

    /** The input must be refused, and the report must say something. */
    public static function rejected(string $what, ?object $result, Report $r, ?string $mentions = null): void
    {
        $msgs = implode(' | ', $r->errors());
        self::ok($what . ' is rejected', $result === null && ! $r->ok(), 'got a result: ' . ($result === null ? 'null' : get_class($result)) . " / errors: $msgs");
        if ($mentions !== null) {
            self::ok($what . " explains why ('$mentions')", stripos($msgs, $mentions) !== false, "errors: $msgs");
        }
    }

    public static function summary(): int
    {
        echo "\n" . self::$passed . ' passed, ' . self::$failed . " failed\n";
        if (self::$failed) {
            echo "Failures:\n  - " . implode("\n  - ", self::$failures) . "\n";
        }

        return self::$failed ? 1 : 0;
    }
}

/**
 * Builds zip files byte by byte, including broken ones.
 *
 * Each entry: name, data, and optional overrides: method (0|8), flags,
 * madeBy, extAttr, crc, csize, usize, localName, localCsize, localUsize,
 * localCrc, raw (pre-compressed bytes to store as-is), dataDescriptor.
 * Archive options: comment, cdOffsetDelta, eocdCount (2 = write two end
 * records), trailing, entriesField.
 */
final class ZipBuilder
{
    /**
     * @param  array<int, array<string, mixed>>  $entries
     * @param  array<string, mixed>  $opts
     */
    public static function build(array $entries, array $opts = []): string
    {
        $body = '';
        $central = '';
        foreach ($entries as $e) {
            $name = $e['name'];
            $data = $e['data'] ?? '';
            $method = $e['method'] ?? 0;
            $raw = $e['raw'] ?? ($method === 8 ? gzdeflate($data, 9) : $data);
            $crc = $e['crc'] ?? crc32($data);
            $csize = $e['csize'] ?? strlen($raw);
            $usize = $e['usize'] ?? strlen($data);
            $flags = $e['flags'] ?? 0;
            $madeBy = $e['madeBy'] ?? 20;
            $ext = $e['extAttr'] ?? 0;
            $localName = $e['localName'] ?? $name;
            $extraLocal = $e['extraLocal'] ?? '';

            $offset = strlen($body);
            $lcrc = $e['localCrc'] ?? $crc;
            $lcsize = $e['localCsize'] ?? $csize;
            $lusize = $e['localUsize'] ?? $usize;
            if ($flags & 0x0008) {
                [$lcrc, $lcsize, $lusize] = [0, 0, 0];
            }
            $body .= "PK\x03\x04" . pack('vvvvvVVVvv', 20, $flags, $method, 0, 0x21, $lcrc, $lcsize, $lusize, strlen($localName), strlen($extraLocal))
                . $localName . $extraLocal . $raw;
            if ($flags & 0x0008) {
                $body .= pack('VVVV', 0x08074b50, $crc, $csize, $usize);
            }
            $central .= "PK\x01\x02" . pack('vvvvvvVVVvvvvvVV', $madeBy, 20, $flags, $method, 0, 0x21, $crc, $csize, $usize, strlen($name), 0, 0, 0, 0, $ext, $e['localOffset'] ?? $offset)
                . $name;
        }

        $cdOffset = strlen($body) + ($opts['cdOffsetDelta'] ?? 0);
        $count = $opts['entriesField'] ?? count($entries);
        $comment = $opts['comment'] ?? '';
        $eocd = "PK\x05\x06" . pack('vvvvVVv', 0, 0, $count, $count, strlen($central), $cdOffset, strlen($comment)) . $comment;

        $out = $body . $central . $eocd;
        if (($opts['eocdCount'] ?? 1) === 2) {
            $out = $body . $central . $eocd . $eocd;
        }

        return $out . ($opts['trailing'] ?? '');
    }

    public static function write(string $bytes): string
    {
        $path = tempnam(sys_get_temp_dir(), 'tsz');
        file_put_contents($path, $bytes);

        return $path;
    }
}

/** A minimal valid skin.json. */
function good_manifest(array $over = []): string
{
    return json_encode(array_merge(['id' => 'testskin', 'name' => 'Test Skin', 'description' => 'For tests', 'author' => 'Tests', 'version' => '1.0.0', 'modes' => ['dark']], $over));
}

/** A minimal valid token file: the core roles. */
function good_css(): string
{
    return file_get_contents(__DIR__ . '/../examples/minimal/skin.css');
}

/**
 * A structurally valid WOFF2/WOFF file of $size bytes with the right header,
 * for tests that need a font that passes FontFile. Not a real font.
 */
function fake_font(string $magic = 'wOF2', int $size = 200, string $fill = "\x01"): string
{
    $header = $magic . 'OTTO' . pack('N', $size) . pack('nn', 5, 0);
    $header .= str_repeat("\0", 48 - strlen($header));

    return $header . str_repeat($fill, $size - 48);
}
