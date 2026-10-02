<?php

namespace Xblossia\ThemeSelector;

use App\Facades\LibrenmsConfig;
use App\Models\Config as ConfigModel;
use Xblossia\ThemeSelector\Graph\PortSeriesSupport;
use Xblossia\ThemeSelector\Skin\GraphConf;

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
    /**
     * The port traffic series, with the colours generic_data.inc.php hard-codes (so stock output
     * is what they fall back to). LibreNMS reads no such keys itself, so the plugin writes them
     * when something will honour them: RecolouringRrd rewrites the series just before rrdtool
     * runs, or the optional core patch makes the helper read them. LibreNMS does not declare
     * the keys, so `lnms config:set` refuses them, but persist() stores them and every later
     * process reads them back; no definitions entry is needed.
     */
    private const PORT_STOCK = [
        'graph_colours.port_in' => ['D7FFC7', '90B040', '608720'],
        'graph_colours.port_out' => ['E0E0FF', '8080C0', '606090'],
    ];

    private ?bool $portHonoured = null;

    public function __construct(
        private readonly SkinRepository $skins,
        private readonly Settings $settings,
    ) {
    }

    /**
     * The skin's palette for graphs drawn in one mode, limited to keys LibreNMS will use: the
     * ones it declares, and the graph_colours.port_in/port_out pair when something honours them
     * (see PORT_STOCK).
     *
     * A graph is drawn light or dark (the request's `style`), and a skin's palette is tuned for
     * the mode the skin is written for: it applies in that mode, and in the other the graphs stay
     * as they are without it. In a mode only that mode's chrome keys apply (the `_dark` ones for
     * dark graphs, the others for light); the series ramps are the same keys in both.
     *
     * @return array<string, string|array<int, string>>
     */
    public function palette(?string $skinId, string $mode = Modes::DARK): array
    {
        if ($skinId === null || ! $this->skins->exists($skinId) || ($this->skins->all()[$skinId]['mode'] ?? Modes::DARK) !== $mode) {
            return [];
        }

        $definitions = LibrenmsConfig::getDefinitions();

        return array_filter(
            $this->skins->graphPalette($skinId),
            fn ($key) => $this->forMode($key, $mode)
                && (array_key_exists($key, $definitions) || (isset(self::PORT_STOCK[$key]) && $this->portSeriesHonoured())),
            ARRAY_FILTER_USE_KEY,
        );
    }

    /**
     * Does this key matter to graphs drawn in $mode: the series ramps always, the chrome and its
     * text colour only for their own mode.
     */
    private function forMode(string $key, string $mode): bool
    {
        if (in_array($key, GraphConf::CHROME_KEYS, true) || in_array($key, GraphConf::FONT_KEYS, true)) {
            return str_ends_with($key, '_dark') === ($mode === Modes::DARK);
        }

        return true;
    }

    /**
     * What the persistent config holds when the instance defaults are $dark and $light: the dark
     * default's palette for dark graphs, the light default's for light ones. The series ramps are
     * one set of keys for both, and graphs nobody asked for in a mode (an alert email, the API)
     * are drawn light, so where both defaults set one the light default's wins.
     *
     * @return array<string, string|array<int, string>>
     */
    public function defaultsPalette(?string $dark, ?string $light): array
    {
        return $this->palette($light, Modes::LIGHT) + $this->palette($dark, Modes::DARK);
    }

    /**
     * Whether port series colours set in config will be used: the store can recolour them, or
     * includes/html/graphs/generic_data.inc.php reads them itself (the optional core patch, or
     * a LibreNMS that has adopted the keys). Read once per process.
     */
    private function portSeriesHonoured(): bool
    {
        return $this->portHonoured ??= PortSeriesSupport::compatible() || str_contains(
            (string) @file_get_contents(base_path('includes/html/graphs/generic_data.inc.php')),
            'graph_colours.port_in',
        );
    }

    /**
     * Write the instance defaults' palettes into the persistent config (see defaultsPalette).
     *
     * @return string[] keys applied
     */
    public function apply(?string $dark, ?string $light = null): array
    {
        $palette = $this->defaultsPalette($dark, $light);

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
     * What to set, in memory, for a graph drawn in $mode for a user whose skin in that mode is
     * $skinId, when the persistent config holds the palettes of the defaults $defaultDark and
     * $defaultLight. Empty when the config is already right.
     *
     * A key the user's skin doesn't set but the config does goes back to its stock value, so a
     * user who chose stock LibreNMS gets stock graphs. Only keys that matter in $mode are
     * considered (the other mode's chrome never affects this graph).
     *
     * @return array<string, string|array<int, string>>
     */
    public function overridesFor(?string $skinId, string $mode, ?string $defaultDark, ?string $defaultLight): array
    {
        $wanted = $this->palette($skinId, $mode);
        $held = array_filter($this->defaultsPalette($defaultDark, $defaultLight), fn ($key) => $this->forMode($key, $mode), ARRAY_FILTER_USE_KEY);

        $overrides = [];
        foreach ($wanted as $key => $value) {
            if (! array_key_exists($key, $held) || $held[$key] !== $value) {
                $overrides[$key] = $value;
            }
        }
        foreach (array_keys($held) as $key) {
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

        return LibrenmsConfig::getDefinitions()[$key]['default'] ?? self::PORT_STOCK[$key] ?? null;
    }
}
