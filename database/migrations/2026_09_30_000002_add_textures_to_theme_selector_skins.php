<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * What textures an uploaded skin carries (name, size in px, bytes), as JSON, so
 * the admin page can show them. The images themselves live only inside the
 * skin's generated stylesheet.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('theme_selector_skins', function (Blueprint $table): void {
            $table->text('textures')->nullable()->after('license_text');
        });
    }

    public function down(): void
    {
        Schema::table('theme_selector_skins', function (Blueprint $table): void {
            $table->dropColumn('textures');
        });
    }
};
