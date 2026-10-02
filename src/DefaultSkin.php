<?php

namespace Xblossia\ThemeSelector;

use InvalidArgumentException;

/**
 * The instance default skins, one for each mode (light and dark), and everything that has to
 * follow them: the stored settings and the graph palettes in LibreNMS config. One place, so the
 * picker page and the installer (which must clear a default it is about to delete) can't drift
 * apart.
 */
class DefaultSkin
{
    public function __construct(
        private readonly SkinRepository $skins,
        private readonly Settings $settings,
        private readonly GraphPalette $graphs,
    ) {
    }

    public function current(string $mode = Modes::DARK): ?string
    {
        $id = $this->settings->get(Settings::defaultName($mode));

        return is_string($id) ? $id : null;
    }

    /** Is this skin the default for either mode. */
    public function isDefault(string $id): bool
    {
        return $this->current(Modes::DARK) === $id || $this->current(Modes::LIGHT) === $id;
    }

    /**
     * Set one mode's default (null for none) and apply the palettes, restoring whatever the
     * previous defaults overwrote.
     */
    public function set(?string $id, string $mode = Modes::DARK): void
    {
        if (! Modes::valid($mode)) {
            throw new InvalidArgumentException('unknown mode');
        }
        if ($id !== null && ! $this->skins->exists($id)) {
            throw new InvalidArgumentException('unknown skin');
        }
        $name = Settings::defaultName($mode);
        if ($id === null) {
            $this->settings->forget($name);
        } else {
            $this->settings->set($name, $id);
        }
        $this->reapply();
    }

    /**
     * Stop a skin being the default for any mode (it is about to go away), restoring the graph
     * colours it set.
     */
    public function clear(string $id): void
    {
        foreach (Modes::ALL as $mode) {
            if ($this->current($mode) === $id) {
                $this->settings->forget(Settings::defaultName($mode));
            }
        }
        $this->reapply();
    }

    /**
     * Re-apply the current defaults' palettes, after a skin itself changed.
     */
    public function reapply(): void
    {
        $dark = $this->current(Modes::DARK);
        $light = $this->current(Modes::LIGHT);
        $this->graphs->apply(
            $dark !== null && $this->skins->exists($dark) ? $dark : null,
            $light !== null && $this->skins->exists($light) ? $light : null,
        );
    }
}
