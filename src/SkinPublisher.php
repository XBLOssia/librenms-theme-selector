<?php

namespace Xblossia\ThemeSelector;

use RuntimeException;

/**
 * Copies the package's base stylesheet and bundled skins into the webroot.
 *
 * The package lives under vendor/, which the web server doesn't serve. The
 * copy goes to html/css/custom/theme-selector/, which LibreNMS gitignores and
 * daily.sh leaves alone. A fingerprint of the package files (paths, sizes,
 * mtimes) is kept beside the copy, so a package update or an edited skin is
 * republished on the next request, and an unchanged one costs a few stat calls.
 *
 * Only bundled skins are touched. Skins an admin uploads (phase 3) live in the
 * same directory but aren't in the marker, so sync never removes them.
 */
class SkinPublisher
{
    private const MARKER = '.bundled.json';

    /** Files a skin directory may publish, by extension. */
    private const SKIN_FILES = ['css', 'json', 'conf'];
    private const FONT_FILES = ['woff2', 'woff', 'ttf', 'txt'];

    public function __construct(
        private readonly string $packageRoot,
        private readonly string $publicDir,
    ) {
    }

    /**
     * Publish if the package has changed since the last publish.
     *
     * @return bool whether anything was written
     */
    public function syncIfNeeded(): bool
    {
        $files = $this->packageFiles();
        $marker = $this->readMarker();

        if (($marker['fingerprint'] ?? null) === $this->fingerprint($files)) {
            return false;
        }

        $this->sync($files, $marker['skins'] ?? []);

        return true;
    }

    /**
     * Publish unconditionally.
     */
    public function syncNow(): void
    {
        $this->sync($this->packageFiles(), $this->readMarker()['skins'] ?? []);
    }

    /**
     * @param  array<string, string>  $files  relative path => absolute source path
     * @param  string[]  $previousSkins  bundled skins published last time
     */
    private function sync(array $files, array $previousSkins): void
    {
        // Earlier dev setups linked these directories straight to the repo;
        // writing through such a link would overwrite the package itself.
        foreach ([$this->publicDir, "$this->publicDir/skins"] as $dir) {
            if (is_link($dir)) {
                @unlink($dir);
            }
        }

        foreach ($files as $relative => $source) {
            $this->copy($source, "$this->publicDir/$relative");
        }

        $skins = $this->bundledSkins();

        // A skin dropped from the package since the last publish.
        foreach (array_diff($previousSkins, $skins) as $gone) {
            $this->removeDirectory("$this->publicDir/skins/$gone");
        }

        $this->write("$this->publicDir/" . self::MARKER, json_encode([
            'fingerprint' => $this->fingerprint($files),
            'skins' => $skins,
        ], JSON_PRETTY_PRINT));
    }

    /**
     * @return string[] ids of the skins in the package
     */
    public function bundledSkins(): array
    {
        $ids = [];
        foreach (glob("$this->packageRoot/skins/*", GLOB_ONLYDIR) ?: [] as $dir) {
            $id = basename($dir);
            if (SkinRepository::isValidId($id) && is_file("$dir/skin.css") && is_file("$dir/skin.json")) {
                $ids[] = $id;
            }
        }
        sort($ids);

        return $ids;
    }

    /**
     * @return array<string, string> relative path => absolute source path
     */
    private function packageFiles(): array
    {
        $files = ['base.css' => "$this->packageRoot/base/base.css"];

        foreach ($this->bundledSkins() as $id) {
            $dir = "$this->packageRoot/skins/$id";
            foreach (self::SKIN_FILES as $ext) {
                foreach (glob("$dir/*.$ext") ?: [] as $file) {
                    // skip the original standalone <id>.css; only skin.css is published
                    if ($ext === 'css' && basename($file) !== 'skin.css') {
                        continue;
                    }
                    $files["skins/$id/" . basename($file)] = $file;
                }
            }
            foreach (self::FONT_FILES as $ext) {
                foreach (glob("$dir/fonts/*.$ext") ?: [] as $file) {
                    $files["skins/$id/fonts/" . basename($file)] = $file;
                }
            }
        }

        ksort($files);

        return $files;
    }

    /**
     * @param  array<string, string>  $files
     */
    private function fingerprint(array $files): string
    {
        $parts = [];
        foreach ($files as $relative => $source) {
            $parts[] = $relative . ':' . @filesize($source) . ':' . @filemtime($source);
        }

        return sha1(implode("\n", $parts));
    }

    /**
     * @return array{fingerprint?: string, skins?: string[]}
     */
    private function readMarker(): array
    {
        $raw = @file_get_contents("$this->publicDir/" . self::MARKER);
        $data = $raw === false ? null : json_decode($raw, true);

        return is_array($data) ? $data : [];
    }

    private function copy(string $source, string $target): void
    {
        $contents = @file_get_contents($source);
        if ($contents === false) {
            throw new RuntimeException("cannot read $source");
        }
        $this->write($target, $contents);
    }

    /**
     * Write via a temporary file and rename, so a page request never loads a
     * half-written stylesheet.
     */
    private function write(string $target, string $contents): void
    {
        $dir = dirname($target);
        if (! is_dir($dir) && ! @mkdir($dir, 0775, true) && ! is_dir($dir)) {
            throw new RuntimeException("cannot create $dir");
        }

        // A dev setup may have linked this path straight to the repo; replace the link.
        if (is_link($target)) {
            @unlink($target);
        }

        $tmp = $target . '.tmp' . getmypid();
        if (@file_put_contents($tmp, $contents) === false || ! @rename($tmp, $target)) {
            @unlink($tmp);
            throw new RuntimeException("cannot write $target");
        }
    }

    private function removeDirectory(string $dir): void
    {
        if (is_link($dir) || ! is_dir($dir)) {
            @unlink($dir);

            return;
        }
        foreach (scandir($dir) ?: [] as $entry) {
            if ($entry !== '.' && $entry !== '..') {
                $this->removeDirectory("$dir/$entry");
            }
        }
        @rmdir($dir);
    }
}
