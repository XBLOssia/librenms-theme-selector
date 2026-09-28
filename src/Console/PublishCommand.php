<?php

namespace Xblossia\ThemeSelector\Console;

use Illuminate\Console\Command;
use Xblossia\ThemeSelector\SkinPublisher;

/**
 * Republish the bundled skins now. Normally unnecessary: the first web
 * request after a package update does it. Useful after install, and to check
 * the webserver user can write the target directory.
 */
class PublishCommand extends Command
{
    protected $signature = 'theme-selector:publish';

    protected $description = 'Copy Theme Selector\'s base stylesheet and bundled skins into the webroot';

    public function handle(SkinPublisher $publisher): int
    {
        $publisher->syncNow();
        $this->info('Published: base.css, ' . implode(', ', $publisher->bundledSkins()));

        return self::SUCCESS;
    }
}
