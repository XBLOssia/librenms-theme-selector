<?php

namespace Xblossia\ThemeSelector;

use App\Facades\LibrenmsConfig;
use App\Models\Config as ConfigModel;

/**
 * A skin's graph palette, and how it reaches LibreNMS's graph code.
 *
 * RRDtool draws graphs on the server from LibreNMS config, so CSS can't reach
 * them. Two mechanisms, both driven by the same palette:
 *
 * - apply() writes the INSTANCE DEFAULT skin's palette into the persistent
 *   config, so every graph, including ones no logged-in user asked for (API,
 *   reports), uses it. Before a key is first overwritten its original state is
 *   recorded: whether the database held an override, and its value. Switching
 *   skins restores keys the new palette doesn't set; clearing the default
 *   restores everything, erasing overrides that didn't exist before rather
 *   than pinning today's defaults.
 *
 * - overridesFor() gives, for one user's graph request, the keys whose
 *   in-memory value must differ from what the persistent config holds, so a
 *   user's graphs follow their own skin. GraphColours applies them to that
 *   request only; nothing is written.
 */
class GraphPalette
{
    public function __construct(
        private readonly SkinRepository $skins,
        private readonly Settings $settings,
    ) {
    }

    /**
     * The skin's palette limited to keys this LibreNMS declares (the
     * graph_colours.port_in/port_out pair only exists with the optional core
     * patch).
     *
     * @return array<string, string|array<int, string>>
     */
    public function palette(?string $skinId): array
    {
        if ($skinId === null) {
            return [];
        }

        $definitions = LibrenmsConfig::getDefinitions();

        return array_filter(
            $this->skins->graphPalette($skinId),
            fn ($key) => array_key_exists($key, $definitions),
            ARRAY_FILTER_USE_KEY,
        );
    }

    /**
     * @return string[] keys applied
     */
    public function apply(?string $skinId): array
    {
        $palette = $this->palette($skinId);

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

    /**
     * What to set, in memory, for a request whose user resolves to $skinId
     * when the persistent config holds $defaultSkinId's palette. Empty when
     * they are the same: the config is already right.
     *
     * A key the user's skin doesn't set but the default's does goes back to
     * its stock value, so a user who chose stock LibreNMS gets stock graphs.
     *
     * @return array<string, string|array<int, string>>
     */
    public function overridesFor(?string $skinId, ?string $defaultSkinId): array
    {
        if ($skinId === $defaultSkinId) {
            return [];
        }

        $wanted = $this->palette($skinId);
        $overrides = $wanted;

        foreach (array_keys($this->palette($defaultSkinId)) as $key) {
            if (! array_key_exists($key, $wanted)) {
                $overrides[$key] = $this->stockValue($key);
            }
        }

        return array_filter($overrides, fn ($value) => $value !== null);
    }

    /**
     * The value a key had before this plugin changed it: the recorded original
     * if the database held an override, otherwise LibreNMS's own default.
     */
    private function stockValue(string $key): mixed
    {
        $originals = $this->settings->get(Settings::GRAPH_ORIGINALS, []);
        $original = $originals[$key] ?? null;

        if ($original !== null && $original['override']) {
            return $original['value'];
        }

        return LibrenmsConfig::getDefinitions()[$key]['default'] ?? null;
    }
}
