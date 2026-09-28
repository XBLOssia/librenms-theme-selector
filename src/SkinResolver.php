<?php

namespace Xblossia\ThemeSelector;

use App\Models\UserPref;
use Illuminate\Contracts\Auth\Authenticatable;

/**
 * Which skin applies to a user.
 *
 * The per-user preference holds a skin id, NONE for an explicit "stock
 * LibreNMS", or nothing, which means "follow the instance default". A choice
 * of a skin that has since been removed also follows the default.
 */
class SkinResolver
{
    public const PREF = 'theme_selector.skin';
    public const NONE = 'none';

    public function __construct(
        private readonly SkinRepository $skins,
        private readonly Settings $settings,
    ) {
    }

    public function forUser(?Authenticatable $user): ?string
    {
        $choice = $user === null ? null : $this->choice($user);

        if ($choice === self::NONE) {
            return null;
        }
        if ($this->skins->exists($choice)) {
            return $choice;
        }

        return $this->default();
    }

    /**
     * The raw preference: a skin id, NONE, or null for "instance default".
     */
    public function choice(Authenticatable $user): ?string
    {
        $pref = UserPref::getPref($user, self::PREF);

        return is_string($pref) && $pref !== '' ? $pref : null;
    }

    public function default(): ?string
    {
        $default = $this->settings->get(Settings::DEFAULT_SKIN);

        return is_string($default) && $this->skins->exists($default) ? $default : null;
    }
}
