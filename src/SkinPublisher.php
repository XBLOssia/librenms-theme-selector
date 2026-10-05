<?php

namespace Xblossia\ThemeSelector;

use RuntimeException;
use Xblossia\ThemeSelector\Skin\OutputGuard;
use Xblossia\ThemeSelector\Skin\PngTexture;
use Xblossia\ThemeSelector\Skin\Report;

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

    /** Bumped when what is published from the same package files changes (2: base-light.css and skin.mirror.css; 3: mirrors made for skins uploaded earlier). */
    private const FORMAT = 3;

    /** Files a skin directory may publish, by extension. */
    private const SKIN_FILES = ['css', 'json', 'conf'];
    private const FONT_FILES = ['woff2', 'woff', 'ttf', 'txt'];
    private const TEXTURE_FILES = ['png'];

    public function __construct(
        private readonly string $packageRoot,
        private readonly string $publicDir,
        private readonly SkinRegistry $registry,
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

        // A directory an admin's upload owns is never written to or removed,
        // even if a later package version ships a skin with the same id.
        $uploaded = array_keys($this->registry->all());
        $skip = fn (string $relative): bool => preg_match('#^skins/([^/]+)/#', $relative, $m) === 1 && in_array($m[1], $uploaded, true);

        foreach ($files as $relative => $source) {
            if ($skip($relative) || str_contains($relative, '/textures/') || $relative === 'light.css') {
                continue;
            }
            $this->copy($source, "$this->publicDir/$relative");
        }

        $this->backfillMirrors($uploaded);

        $skins = $this->bundledSkins();

        // A skin dropped from the package since the last publish.
        foreach (array_diff($previousSkins, $skins) as $gone) {
            if (! in_array($gone, $uploaded, true)) {
                $this->removeDirectory("$this->publicDir/skins/$gone");
            }
        }

        $this->write("$this->publicDir/" . self::MARKER, json_encode([
            'fingerprint' => $this->fingerprint($files),
            'skins' => $skins,
        ], JSON_PRETTY_PRINT));
    }

    /**
     * A skin uploaded before light and dark slots existed has no mirror, and without one it can't
     * be put in the other slot (the page would get no skin at all there). Make it from the
     * stylesheet already on disk, which was validated when it was installed. It is checked again
     * as an installer would check it, and the mirror too, and nothing is written otherwise.
     *
     * @param  string[]  $uploaded  ids of the uploaded skins
     */
    private function backfillMirrors(array $uploaded): void
    {
        foreach ($uploaded as $id) {
            $dir = "$this->publicDir/skins/$id";
            if (! SkinRepository::isValidId($id) || is_link($dir) || ! is_file("$dir/skin.css") || is_link("$dir/skin.css")
                || file_exists("$dir/skin.mirror.css") || is_link("$dir/skin.mirror.css")) {
                continue;
            }
            $css = (string) @file_get_contents("$dir/skin.css");
            $native = Modes::nativeOf($css);
            $mirror = Modes::mirror($css);
            if ($native === null || $mirror === null) {
                continue;
            }
            $faces = substr_count($css, '@font-face');
            $textures = (int) preg_match_all('#^  --tx-[a-z0-9][a-z0-9-]{0,40}: url\("data:image/png;#m', $css);
            if (OutputGuard::safe($css, $faces, $textures, $native) && OutputGuard::safe($mirror, $faces, $textures, Modes::other($native))) {
                $this->write("$dir/skin.mirror.css", $mirror);
            }
        }
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
        // light.css is not published as a file: it is part of base-light.css (see copy()). It is
        // listed so that changing it changes the fingerprint and republishes.
        $files = ['base.css' => "$this->packageRoot/base/base.css", 'light.css' => "$this->packageRoot/base/light.css"];

        foreach ($this->bundledSkins() as $id) {
            $dir = "$this->packageRoot/skins/$id";
            foreach (self::SKIN_FILES as $ext) {
                foreach (glob("$dir/*.$ext") ?: [] as $file) {
                    $files["skins/$id/" . basename($file)] = $file;
                }
            }
            foreach (self::FONT_FILES as $ext) {
                foreach (glob("$dir/fonts/*.$ext") ?: [] as $file) {
                    $files["skins/$id/fonts/" . basename($file)] = $file;
                }
            }
            // Textures are not published as files: they are embedded into skin.css (see copy()). They
            // are listed here so that changing one changes the fingerprint and republishes the skin.
            foreach (self::TEXTURE_FILES as $ext) {
                foreach (glob("$dir/textures/*.$ext") ?: [] as $file) {
                    $files["skins/$id/textures/" . basename($file)] = $file;
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

        return sha1(self::FORMAT . "\n" . implode("\n", $parts));
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
        $name = basename($source);
        if ($name === 'skin.css') {
            $contents = $this->embedTextures($contents, dirname($source));
        }
        $this->write($target, $contents);

        // The light twin of the base, and the mirror of a skin (the same rules for the other
        // mode, so any skin can be put in either slot). Derived from what was just written.
        if ($name === 'base.css') {
            // Light mode's own colour mapping goes on the end (docs/ORNAMENTS.md, "Light mode").
            $extra = (string) @file_get_contents(dirname($source) . '/light.css');
            $this->write(dirname($target) . '/base-light.css', Modes::lightBase($contents) . "\n" . $extra);
        } elseif ($name === 'skin.css' && ($mirror = Modes::mirror($contents)) !== null) {
            $this->write(dirname($target) . '/skin.mirror.css', $mirror);
        }
    }

    /**
     * A bundled skin declares a texture as `--tx-<name>: url("textures/<name>.png");` next to its
     * skin.css. A url() inside a custom property is resolved against the stylesheet that USES the
     * variable (base.css), not the one that declares it, so a relative path would point at the wrong
     * place. So, as for uploaded skins, the published file carries the image itself, as a data: URL,
     * and the PNG is checked (strictly: a bundled texture must already be clean) on the way.
     */
    private function embedTextures(string $css, string $dir): string
    {
        return (string) preg_replace_callback('#^  --tx-([a-z0-9][a-z0-9-]{0,40}): url\(\"textures/\1\.png\"\);$#m', function (array $m) use ($dir): string {
            $bytes = @file_get_contents("$dir/textures/$m[1].png");
            $checked = $bytes === false ? null : PngTexture::check("textures/$m[1].png", $bytes, new Report(), true);
            if ($checked === null) {
                throw new RuntimeException("texture textures/$m[1].png in $dir is missing or not a clean PNG");
            }

            return "  --tx-$m[1]: url(\"data:image/png;base64," . base64_encode($checked['png']) . '");';
        }, $css);
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
