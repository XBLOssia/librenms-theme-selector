<?php

namespace Xblossia\ThemeSelector;

use App\Models\UserPref;
use Illuminate\Contracts\Auth\Authenticatable;

/**
 * Which skin applies to a user, in each mode (light and dark: Modes).
 *
 * Each mode has its own preference. It holds a skin id, NONE for an explicit "stock LibreNMS",
 * or nothing, which means "follow the instance default for that mode". A choice of a skin that
 * has since been removed also follows the default. The dark preference keeps the name every
 * earlier version used, so what users chose before is their dark-mode skin now.
 */
class SkinResolver
{
    public const PREF = 'theme_selector.skin';
    public const PREF_LIGHT = 'theme_selector.skin_light';
    public const NONE = 'none';

    public function __construct(
        private readonly SkinRepository $skins,
        private readonly Settings $settings,
    ) {
    }

    public static function pref(string $mode): string
    {
        return $mode === Modes::LIGHT ? self::PREF_LIGHT : self::PREF;
    }

    public function forUser(?Authenticatable $user, string $mode = Modes::DARK): ?string
    {
        $choice = $user === null ? null : $this->choice($user, $mode);

        if ($choice === self::NONE) {
            return null;
        }
        if ($this->skins->exists($choice)) {
            return $choice;
        }

        return $this->default($mode);
    }

    /**
     * The raw preference for one mode: a skin id, NONE, or null for "instance default".
     */
    public function choice(Authenticatable $user, string $mode = Modes::DARK): ?string
    {
        $pref = UserPref::getPref($user, self::pref($mode));

        return is_string($pref) && $pref !== '' ? $pref : null;
    }

    public function default(string $mode = Modes::DARK): ?string
    {
        $default = $this->settings->get(Settings::defaultName($mode));

        return is_string($default) && $this->skins->exists($default) ? $default : null;
    }
}
