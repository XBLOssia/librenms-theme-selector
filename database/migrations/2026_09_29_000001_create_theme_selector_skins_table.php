<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Skins installed through the upload page.
 *
 * The row IS the skin: a directory under the web root only counts as a skin if
 * it has a row here (or is bundled), so files that turn up there some other way
 * are never offered or applied. The row also holds the validated metadata and
 * graph palette, so neither is read back from a file in the web root.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('theme_selector_skins', function (Blueprint $table): void {
            $table->string('id', 63)->primary();
            $table->string('name', 60);
            $table->string('description', 200)->default('');
            $table->string('author', 60)->default('');
            $table->string('version', 20)->default('1.0.0');
            $table->string('license', 60)->default('');
            $table->char('sha256', 64);
            $table->json('graph')->nullable();
            $table->unsignedInteger('installed_by')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('theme_selector_skins');
    }
};
