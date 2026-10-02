<?php

namespace Xblossia\ThemeSelector;

/**
 * What the picker should preview for a choice in its list.
 *
 * The choices are the ones a user can save: '' (follow the instance default), 'none' (stock
 * LibreNMS) and a skin id. Each previews as a skin id, or 'none' for stock; anything else, such as
 * an id that no longer exists, previews as nothing (null), so a stale link shows no frame rather
 * than an error.
 */
final class PreviewChoice
{
    public const STOCK = 'none';

    /**
     * @param  ?string  $default  the instance default skin id, or null for none
     * @param  callable(string): bool  $exists  is this an installed skin id
     */
    public static function target(string $choice, ?string $default, callable $exists): ?string
    {
        if ($choice === '') {
            return $default !== null && $exists($default) ? $default : self::STOCK;
        }
        if ($choice === self::STOCK) {
            return self::STOCK;
        }

        return $exists($choice) ? $choice : null;
    }
}
