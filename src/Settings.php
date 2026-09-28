<?php

namespace Xblossia\ThemeSelector;

use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Instance-wide settings, one JSON value per name, in the plugin's own table.
 */
class Settings
{
    private const TABLE = 'theme_selector_settings';

    public const DEFAULT_SKIN = 'default_skin';
    public const GRAPH_ORIGINALS = 'graph_originals';

    /** @var array<string, mixed> */
    private array $cache = [];

    /**
     * Returns $default when the value is unset, and also when the table is
     * missing (plugin installed but not yet migrated): reads happen on every
     * page render and must not break it.
     */
    public function get(string $name, mixed $default = null): mixed
    {
        if (! array_key_exists($name, $this->cache)) {
            try {
                $raw = DB::table(self::TABLE)->where('name', $name)->value('value');
                $this->cache[$name] = $raw === null ? null : json_decode($raw, true);
            } catch (Throwable) {
                return $default;
            }
        }

        return $this->cache[$name] ?? $default;
    }

    public function set(string $name, mixed $value): void
    {
        DB::table(self::TABLE)->updateOrInsert(
            ['name' => $name],
            ['value' => json_encode($value, JSON_UNESCAPED_SLASHES), 'updated_at' => now(), 'created_at' => now()],
        );
        $this->cache[$name] = $value;
    }

    public function forget(string $name): void
    {
        DB::table(self::TABLE)->where('name', $name)->delete();
        $this->cache[$name] = null;
    }
}
