<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * The licence notice that came with an uploaded skin (its LICENSE.txt), kept
 * with the skin and shown to admins. Plain text, validated before it gets
 * here, and never written to the web root.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('theme_selector_skins', function (Blueprint $table): void {
            $table->text('license_text')->nullable()->after('license');
        });
    }

    public function down(): void
    {
        Schema::table('theme_selector_skins', function (Blueprint $table): void {
            $table->dropColumn('license_text');
        });
    }
};
