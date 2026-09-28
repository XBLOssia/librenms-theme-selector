<?php

namespace Xblossia\ThemeSelector;

/**
 * The skins this install can apply: every valid skin directory in the
 * published location, bundled or (from phase 3) uploaded. Served layout,
 * under the webroot:
 *
 *   css/custom/theme-selector/base.css
 *   css/custom/theme-selector/skins/<id>/skin.css     token file
 *   css/custom/theme-selector/skins/<id>/skin.json    manifest
 *   css/custom/theme-selector/skins/<id>/graph.conf   optional graph palette
 *   css/custom/theme-selector/skins/<id>/fonts/       optional fonts
 */
class SkinRepository
{
    public const PUBLIC_DIR = 'css/custom/theme-selector';

    /** @var array<string, array{id: string, name: string, description: string, modes: string[]}>|null */
    private ?array $skins = null;

    public function __construct(private readonly string $publicDir)
    {
    }

    public static function isValidId(string $id): bool
    {
        return (bool) preg_match('/^[a-z0-9][a-z0-9-]{0,62}$/', $id);
    }

    /**
     * @return array<string, array{id: string, name: string, description: string, modes: string[]}> by id, sorted by name
     */
    public function all(): array
    {
        if ($this->skins !== null) {
            return $this->skins;
        }

        $skins = [];
        foreach (glob("$this->publicDir/skins/*", GLOB_ONLYDIR) ?: [] as $dir) {
            $id = basename($dir);
            if (! self::isValidId($id) || ! is_file("$dir/skin.css")) {
                continue;
            }
            $manifest = json_decode((string) @file_get_contents("$dir/skin.json"), true);
            $manifest = is_array($manifest) ? $manifest : [];
            $skins[$id] = [
                'id' => $id,
                'name' => is_string($manifest['name'] ?? null) ? $manifest['name'] : ucfirst($id),
                'description' => is_string($manifest['description'] ?? null) ? $manifest['description'] : '',
                'modes' => array_values(array_intersect(['dark', 'light'], (array) ($manifest['modes'] ?? ['dark']))),
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
            if (! is_file($path)) {
                return [];
            }
            $urls[] = self::PUBLIC_DIR . "/$file?v=" . filemtime($path);
        }

        return $urls;
    }

    /**
     * The skin's graph palette as config key => value, or [] if it has none.
     * Lines are `key=value`; values starting with `[` are JSON arrays.
     *
     * @return array<string, string|array<int, string>>
     */
    public function graphPalette(string $id): array
    {
        if (! $this->exists($id)) {
            return [];
        }

        $raw = @file_get_contents("$this->publicDir/skins/$id/graph.conf");
        if ($raw === false) {
            return [];
        }

        $palette = [];
        foreach (preg_split('/\R/', $raw) as $line) {
            $line = trim($line);
            if ($line === '' || $line[0] === '#' || ! str_contains($line, '=')) {
                continue;
            }
            [$key, $value] = array_map('trim', explode('=', $line, 2));
            if (! preg_match('/^(rrdgraph_def_text(_color)?_dark|graph_colours\.[a-z_]+)$/', $key)) {
                continue; // only graph colour settings, whatever the file says
            }
            if (str_starts_with($value, '[')) {
                $decoded = json_decode($value, true);
                if (! is_array($decoded)) {
                    continue;
                }
                $value = array_values(array_filter($decoded, fn ($c) => is_string($c) && preg_match('/^[0-9A-Fa-f]{6}([0-9A-Fa-f]{2})?$/', $c)));
            }
            $palette[$key] = $value;
        }

        return $palette;
    }
}
