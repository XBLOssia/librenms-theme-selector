<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Instance-wide Theme Selector state (default skin, graph palette originals).
 * Not LibreNMS's plugin settings: PluginManager::cleanupPlugins() can delete
 * those along with the plugin's row. Per-user choices live in users_prefs.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('theme_selector_settings', function (Blueprint $table): void {
            $table->string('name', 64)->primary();
            $table->json('value')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('theme_selector_settings');
    }
};
