<?php

namespace Xblossia\ThemeSelector;

use Xblossia\ThemeSelector\Skin\GraphConf;
use Xblossia\ThemeSelector\Skin\Report;

/**
 * The skins this install can apply.
 *
 * A skin exists only if it is one of:
 *   - BUNDLED: shipped in this package (skins/<id> in the package), published
 *     into the web root by SkinPublisher; or
 *   - UPLOADED: has a row in the registry, put there by the installer after the
 *     bundle passed validation.
 * Either way its stylesheet must also be present in the web root. A directory
 * that merely appears under skins/ (dropped in by hand, left over from a failed
 * install, or written by anything else) is not a skin: it is not listed, can't
 * be chosen and is never linked. That keeps the web root's contents from
 * deciding what gets applied.
 *
 * Served layout, under the webroot:
 *
 *   css/custom/theme-selector/base.css
 *   css/custom/theme-selector/skins/<id>/skin.css     always
 *   css/custom/theme-selector/skins/<id>/skin.json    bundled only
 *   css/custom/theme-selector/skins/<id>/graph.conf   bundled only
 *   css/custom/theme-selector/skins/<id>/fonts/       bundled only
 *
 * An uploaded skin is a single generated skin.css (its fonts are inside it);
 * its metadata and graph palette live in the registry, not in files.
 */
class SkinRepository
{
    public const PUBLIC_DIR = 'css/custom/theme-selector';

    /** @var array<string, array{id: string, name: string, description: string, author: string, version: string, source: string, modes: string[]}>|null */
    private ?array $skins = null;

    public function __construct(
        private readonly string $publicDir,
        private readonly SkinRegistry $registry,
        private readonly string $packageSkinsDir,
    ) {
    }

    public static function isValidId(string $id): bool
    {
        return (bool) preg_match('/^[a-z0-9][a-z0-9-]{0,62}\z/D', $id);
    }

    /**
     * Ids of the skins shipped in this package.
     *
     * @return string[]
     */
    public function bundledIds(): array
    {
        $ids = [];
        foreach (glob("$this->packageSkinsDir/*", GLOB_ONLYDIR) ?: [] as $dir) {
            $id = basename($dir);
            if (self::isValidId($id) && is_file("$dir/skin.css") && is_file("$dir/skin.json")) {
                $ids[] = $id;
            }
        }
        sort($ids);

        return $ids;
    }

    public function isBundled(string $id): bool
    {
        return in_array($id, $this->bundledIds(), true);
    }

    public function isUploaded(string $id): bool
    {
        return ! $this->isBundled($id) && $this->registry->find($id) !== null;
    }

    /**
     * @return array<string, array{id: string, name: string, description: string, author: string, version: string, source: string, modes: string[]}> by id, sorted by name
     */
    public function all(): array
    {
        if ($this->skins !== null) {
            return $this->skins;
        }

        $skins = [];

        foreach ($this->bundledIds() as $id) {
            if (! is_file("$this->publicDir/skins/$id/skin.css")) {
                continue;
            }
            $manifest = json_decode((string) @file_get_contents("$this->publicDir/skins/$id/skin.json"), true);
            $manifest = is_array($manifest) ? $manifest : [];
            $skins[$id] = [
                'id' => $id,
                'name' => is_string($manifest['name'] ?? null) ? $manifest['name'] : ucfirst($id),
                'description' => is_string($manifest['description'] ?? null) ? $manifest['description'] : '',
                'author' => is_string($manifest['author'] ?? null) ? $manifest['author'] : '',
                'version' => is_string($manifest['version'] ?? null) ? $manifest['version'] : '',
                'source' => 'bundled',
                'modes' => array_values(array_intersect(['dark', 'light'], (array) ($manifest['modes'] ?? ['dark']))),
            ];
        }

        foreach ($this->registry->all() as $id => $row) {
            if (isset($skins[$id]) || ! self::isValidId($id) || ! is_file("$this->publicDir/skins/$id/skin.css")) {
                continue;
            }
            $skins[$id] = [
                'id' => $id,
                'name' => $row['name'],
                'description' => $row['description'],
                'author' => $row['author'],
                'version' => $row['version'],
                'source' => 'uploaded',
                'modes' => ['dark'],
                'license' => (string) ($row['license'] ?? ''),
                'license_text' => (string) ($row['license_text'] ?? ''),
                'textures' => array_values(array_filter((array) ($row['textures'] ?? []), 'is_array')),
            ];
        }

        uasort($skins, fn ($a, $b) => strcasecmp($a['name'], $b['name']));

        return $this->skins = $skins;
    }

    public function exists(?string $id): bool
    {
        return $id !== null && self::isValidId($id) && array_key_exists($id, $this->all());
    }

    public function name(?string $id): ?string
    {
        return $this->exists($id) ? $this->all()[$id]['name'] : null;
    }

    /**
     * Webroot-relative URLs of the base stylesheet and the skin's token file,
     * in load order, each with a cache-buster. Empty if either is missing: a
     * token file without the base, or the base without tokens, would
     * half-apply.
     *
     * @return string[]
     */
    public function stylesheetUrls(string $id): array
    {
        if (! self::isValidId($id)) {
            return [];
        }

        $urls = [];
        foreach (['base.css', "skins/$id/skin.css"] as $file) {
            $path = "$this->publicDir/$file";
            if (! is_file($path) || is_link($path)) {
                return [];
            }
            $urls[] = self::PUBLIC_DIR . "/$file?v=" . filemtime($path);
        }

        return $urls;
    }

    /**
     * The skin's graph palette as config key => value, or [] if it has none.
     *
     * Bundled skins keep theirs in graph.conf; uploaded skins in the registry.
     * Both go through GraphConf's exact-shape checks here, so what reaches
     * LibreNMS config is validated no matter how it was stored.
     *
     * @return array<string, string|array<int, string>>
     */
    public function graphPalette(string $id): array
    {
        if (! $this->exists($id)) {
            return [];
        }

        if ($this->isBundled($id)) {
            $raw = @file_get_contents("$this->publicDir/skins/$id/graph.conf");

            return $raw === false ? [] : (GraphConf::parse($raw, new Report()) ?? []);
        }

        return GraphConf::fromStored($this->registry->find($id)['graph'] ?? []);
    }
}
