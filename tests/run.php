<?php

/*
 * Runs the skin-validation tests. No framework, no Composer, no LibreNMS:
 * only PHP with zlib, so it runs the same on a laptop, in CI and in the dev
 * container.
 *
 *   php tests/run.php
 *   FUZZ_ROUNDS=50000 php tests/run.php     # longer fuzzing
 *
 * Exit status is 0 only if every check passed.
 */

declare(strict_types=1);

error_reporting(E_ALL);
ini_set('display_errors', '1');
ini_set('memory_limit', '512M');

set_error_handler(function (int $no, string $str, string $file, int $line): bool {
    // The code under test suppresses expected warnings with @; honour that.
    // Any other warning inside a validator is a bug: make it loud.
    if (! (error_reporting() & $no)) {
        return false;
    }
    throw new ErrorException($str, 0, $no, $file, $line);
});

require __DIR__ . '/bootstrap.php';
foreach (['ZipTest', 'CssTest', 'MiscTest', 'LicenseTest', 'InstallerTest', 'FuzzTest'] as $f) {
    require __DIR__ . "/$f.php";
}

$started = microtime(true);
foreach (['test_zip', 'test_css', 'test_values', 'test_fonts_in_css', 'test_fonts', 'test_graph', 'test_manifest', 'test_catalog', 'test_bundled', 'test_license', 'test_installer', 'test_fuzz'] as $fn) {
    try {
        $fn();
    } catch (Throwable $e) {
        T::ok("$fn ran without an exception", false, get_class($e) . ': ' . $e->getMessage() . ' at ' . basename($e->getFile()) . ':' . $e->getLine());
    }
}
printf("\n(%.1fs)\n", microtime(true) - $started);
exit(T::summary());
