<?php

namespace Xblossia\ThemeSelector\Hooks;

use LibreNMS\Interfaces\Plugins\Hooks\MenuEntryHook;

/**
 * Navbar Plugins menu entry for the skin picker. Every logged-in user may
 * pick a skin, so there is nothing to authorize.
 */
class Menu implements MenuEntryHook
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array{0: string, 1: array<string, string>}
     */
    public function handle(string $pluginName): array
    {
        return ["$pluginName::menu", ['url' => url('plugin/theme-selector')]];
    }
}
