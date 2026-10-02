<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * The mode an uploaded skin is written for ('dark', which every earlier skin was, or 'light') and the
 * name of the family it belongs to, if any, which the picker groups skins by.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('theme_selector_skins', function (Blueprint $table): void {
            $table->string('mode', 5)->default('dark')->after('version');
            $table->string('family', 40)->default('')->after('mode');
        });
    }

    public function down(): void
    {
        Schema::table('theme_selector_skins', function (Blueprint $table): void {
            $table->dropColumn(['mode', 'family']);
        });
    }
};
