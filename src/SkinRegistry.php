<?php

namespace Xblossia\ThemeSelector;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Throwable;
use Xblossia\ThemeSelector\Skin\CompiledSkin;

/**
 * Skins installed through the upload page, in the plugin's own table.
 *
 * Reads never throw: they run while rendering pages (the picker list, the skin
 * lookup on every request), and a missing table or a database blip must leave
 * the page working with no custom skins, not broken. Writes throw, because the
 * installer has to know.
 */
class SkinRegistry
{
    private const TABLE = 'theme_selector_skins';

    /** @var array<string, array<string, mixed>>|null */
    private ?array $cache = null;

    /**
     * @return array<string, array{id: string, name: string, description: string, author: string, version: string, license: string, sha256: string, graph: mixed, installed_by: int|null, created_at: string|null}>
     */
    public function all(): array
    {
        if ($this->cache !== null) {
            return $this->cache;
        }
        try {
            $rows = [];
            foreach (DB::table(self::TABLE)->orderBy('name')->get() as $row) {
                $row = (array) $row;
                $row['graph'] = $row['graph'] === null ? [] : json_decode((string) $row['graph'], true);
                $tex = isset($row['textures']) ? json_decode((string) $row['textures'], true) : [];
                $row['textures'] = is_array($tex) ? $tex : [];
                $rows[$row['id']] = $row;
            }

            return $this->cache = $rows;
        } catch (Throwable) {
            return [];
        }
    }

    /**
     * @return array<string, mixed>|null
     */
    public function find(string $id): ?array
    {
        return $this->all()[$id] ?? null;
    }

    public function save(CompiledSkin $skin, ?int $installedBy): void
    {
        $m = $skin->manifest;
        $now = now();
        $fields = [
            'name' => $m['name'],
            'description' => $m['description'],
            'author' => $m['author'],
            'version' => $m['version'],
            'license' => $m['license'],
            'license_text' => $skin->licenseText === '' ? null : $skin->licenseText,
            'sha256' => $skin->sha256,
            'graph' => json_encode($skin->graph === [] ? new \stdClass() : $skin->graph, JSON_UNESCAPED_SLASHES),
            'installed_by' => $installedBy,
            'updated_at' => $now,
        ];
        // Added by a later migration: an update that hasn't run it yet still installs skins.
        if (Schema::hasColumn(self::TABLE, 'textures')) {
            $fields['textures'] = $skin->textures === [] ? null : json_encode($skin->textures);
        }
        // Added by a later migration. Until it has run every skin is dark, and a light one can't be
        // recorded truthfully, so it is refused rather than quietly stored as dark.
        if (Schema::hasColumn(self::TABLE, 'mode')) {
            $fields['mode'] = $m['mode'] ?? Modes::DARK;
            $fields['family'] = $m['family'] ?? '';
        } elseif (($m['mode'] ?? Modes::DARK) !== Modes::DARK) {
            throw new \RuntimeException('light-mode skins need the latest migration: run php artisan migrate --force');
        }
        if (DB::table(self::TABLE)->where('id', $m['id'])->exists()) {
            DB::table(self::TABLE)->where('id', $m['id'])->update($fields);
        } else {
            DB::table(self::TABLE)->insert($fields + ['id' => $m['id'], 'created_at' => $now]);
        }
        $this->cache = null;
    }

    public function delete(string $id): void
    {
        DB::table(self::TABLE)->where('id', $id)->delete();
        $this->cache = null;
    }
}
