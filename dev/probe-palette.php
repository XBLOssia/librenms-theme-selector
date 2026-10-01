<?php

/*
 * Run inside the dev LibreNMS (via `php artisan tinker --execute`) by dev/test-patch.sh:
 * applies a skin's graph palette as the instance default would, and reports which port
 * keys were written and what the config table holds for them.
 *
 *   SKIN=protoss   apply protoss's palette
 *   SKIN=null      clear it (restores everything, erasing keys that had no override)
 */

$skin = getenv('SKIN') ?: 'null';
$applied = app(Xblossia\ThemeSelector\GraphPalette::class)->apply($skin === 'null' ? null : $skin);
$port = array_values(array_filter($applied, fn ($k) => str_starts_with($k, 'graph_colours.port_')));
sort($port);
$rows = App\Models\Config::query()->where('config_name', 'like', 'graph_colours.port_%')->pluck('config_value', 'config_name')->all();
ksort($rows);

echo 'PROBE ' . json_encode(['port_applied' => $port, 'rows' => $rows]) . PHP_EOL;
