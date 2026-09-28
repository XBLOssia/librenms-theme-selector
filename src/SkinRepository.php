<?php

namespace Xblossia\ThemeSelector;

/**
 * The skins this install knows about, and where their files are served from.
 *
 * A skin is a directory holding skin.css: token values only (custom
 * properties and bundle-local @font-face), applied by the shared base
 * stylesheet. Served layout, under the webroot:
 *
 *   css/custom/theme-selector/base.css
 *   css/custom/theme-selector/skins/<id>/skin.css
 *
 * Phase 0/1: the bundled skins in the package's skins/ directory, served via
 * links that dev/ creates. Publishing into the webroot is phase 2
 * (docs/PLUGIN.md).
 */
class SkinRepository
{
    public const PUBLIC_DIR = 'css/custom/theme-selector';

    public function __construct(
        private readonly string $packageDir,
        private readonly string $publicDir,
    ) {
    }

    /**
     * @return array<string, string> skin id => display name
     */
    public function all(): array
    {
        $skins = [];

        foreach (glob($this->packageDir . '/*', GLOB_ONLYDIR) ?: [] as $dir) {
            $id = basename($dir);
            if ($this->isValidId($id) && is_file("$dir/skin.css")) {
                $skins[$id] = ucfirst($id);
            }
        }

        ksort($skins);

        return $skins;
    }

    public function exists(string $id): bool
    {
        return $this->isValidId($id) && array_key_exists($id, $this->all());
    }

    /**
     * Webroot-relative URLs of the base stylesheet and the skin's token file,
     * in load order, each with a cache-buster. Empty if either served file is
     * missing: a token file without the base, or the base without tokens,
     * would half-apply.
     *
     * @return string[]
     */
    public function stylesheetUrls(string $id): array
    {
        if (! $this->isValidId($id)) {
            return [];
        }

        $files = ['base.css', "skins/$id/skin.css"];
        $urls = [];

        foreach ($files as $file) {
            $path = "$this->publicDir/$file";
            if (! is_file($path)) {
                return [];
            }
            $urls[] = self::PUBLIC_DIR . "/$file?v=" . filemtime($path);
        }

        return $urls;
    }

    private function isValidId(string $id): bool
    {
        return (bool) preg_match('/^[a-z0-9][a-z0-9-]{0,62}$/', $id);
    }
}
