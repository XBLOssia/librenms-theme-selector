<?php

namespace Xblossia\ThemeSelector;

use App\Facades\LibrenmsConfig;
use App\Models\Config as ConfigModel;

/**
 * Applies a skin's graph palette as instance-wide LibreNMS config.
 *
 * RRDtool draws graphs server-side from config, so CSS can't reach them and
 * the palette can't be per-user yet (docs/PLUGIN.md, phase 5). It follows the
 * admin's default skin instead.
 *
 * Before a key is first overwritten, its original state is recorded: whether
 * the database held an override, and if so its value. Switching skins restores
 * keys the new palette doesn't set; clearing the default restores everything,
 * erasing overrides that didn't exist before rather than pinning today's
 * defaults.
 */
class GraphPalette
{
    public function __construct(
        private readonly SkinRepository $skins,
        private readonly Settings $settings,
    ) {
    }

    /**
     * @return string[] keys applied; keys the palette names but LibreNMS
     *                  doesn't declare (graph_colours.port_in/port_out without
     *                  the optional core patch) are skipped
     */
    public function apply(?string $skinId): array
    {
        $palette = $skinId === null ? [] : $this->skins->graphPalette($skinId);
        $definitions = LibrenmsConfig::getDefinitions();
        $palette = array_filter($palette, fn ($key) => array_key_exists($key, $definitions), ARRAY_FILTER_USE_KEY);

        /** @var array<string, array{override: bool, value: mixed}> $originals */
        $originals = $this->settings->get(Settings::GRAPH_ORIGINALS, []);

        foreach ($palette as $key => $value) {
            if (! array_key_exists($key, $originals)) {
                $row = ConfigModel::query()->where('config_name', $key)->first();
                $originals[$key] = ['override' => $row !== null, 'value' => $row?->config_value];
            }
        }
        // Record before writing, so a failure part-way still leaves a way back.
        $this->settings->set(Settings::GRAPH_ORIGINALS, $originals);

        foreach ($palette as $key => $value) {
            LibrenmsConfig::persist($key, $value);
        }

        foreach ($originals as $key => $original) {
            if (array_key_exists($key, $palette)) {
                continue;
            }
            if ($original['override']) {
                LibrenmsConfig::persist($key, $original['value']);
            } else {
                LibrenmsConfig::erase($key);
            }
            unset($originals[$key]);
        }

        if ($originals === []) {
            $this->settings->forget(Settings::GRAPH_ORIGINALS);
        } else {
            $this->settings->set(Settings::GRAPH_ORIGINALS, $originals);
        }

        return array_keys($palette);
    }
}
