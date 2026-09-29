<?php

namespace Xblossia\ThemeSelector;

use InvalidArgumentException;

/**
 * The instance default skin, and everything that has to follow it: the stored
 * setting and the graph palette in LibreNMS config. One place, so the picker
 * page and the installer (which must clear a default it is about to delete)
 * can't drift apart.
 */
class DefaultSkin
{
    public function __construct(
        private readonly SkinRepository $skins,
        private readonly Settings $settings,
        private readonly GraphPalette $graphs,
    ) {
    }

    public function current(): ?string
    {
        $id = $this->settings->get(Settings::DEFAULT_SKIN);

        return is_string($id) ? $id : null;
    }

    /**
     * Set the default (null for none) and apply its graph palette, restoring
     * whatever the previous default overwrote.
     */
    public function set(?string $id): void
    {
        if ($id !== null && ! $this->skins->exists($id)) {
            throw new InvalidArgumentException('unknown skin');
        }
        if ($id === null) {
            $this->settings->forget(Settings::DEFAULT_SKIN);
        } else {
            $this->settings->set(Settings::DEFAULT_SKIN, $id);
        }
        $this->graphs->apply($id);
    }

    /**
     * Re-apply the current default's palette, after the skin itself changed.
     */
    public function reapply(): void
    {
        $id = $this->current();
        if ($id !== null && $this->skins->exists($id)) {
            $this->graphs->apply($id);
        }
    }
}
