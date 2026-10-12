<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * Two bundled skins changed their ids and names: protoss is now ascended and zerg is now organic.
 *
 * A user's choice and an instance default are stored as the skin's id, so without this a host that had either
 * skin chosen would fall back to the instance default, silently. This carries those choices over.
 *
 * It is harmless to run twice (it only changes rows that still hold an old id), and it does not touch a row when
 * an uploaded skin already has the new id: that would point someone at a skin they never chose.
 */
return new class extends Migration
{
    private const RENAMED = ['protoss' => 'ascended', 'zerg' => 'organic'];

    private const USER_PREFS = ['theme_selector.skin', 'theme_selector.skin_light'];

    private const DEFAULTS = ['default_skin', 'default_skin_light'];

    public function up(): void
    {
        $this->rename(self::RENAMED);
    }

    public function down(): void
    {
        $this->rename(array_flip(self::RENAMED));
    }

    /**
     * @param  array<string, string>  $map  old id => new id
     */
    private function rename(array $map): void
    {
        foreach ($map as $old => $new) {
            if (Schema::hasTable('theme_selector_skins') && DB::table('theme_selector_skins')->where('id', $new)->exists()) {
                continue;
            }
            if (Schema::hasTable('users_prefs')) {
                DB::table('users_prefs')->whereIn('pref', self::USER_PREFS)->where('value', $old)->update(['value' => $new]);
            }
            if (Schema::hasTable('theme_selector_settings')) {
                // The settings table holds JSON, so a skin id is a quoted string.
                DB::table('theme_selector_settings')->whereIn('name', self::DEFAULTS)->where('value', json_encode($old))->update(['value' => json_encode($new)]);
            }
        }
    }
};
