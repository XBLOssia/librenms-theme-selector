<?php

namespace Xblossia\ThemeSelector;

/**
 * Light and dark: the two places a skin can be put.
 *
 * LibreNMS decides light or dark in the browser (a per-user setting, or the device's own
 * preference), so the server can't know which a page will be. Everything here is therefore
 * written to be correct either way: a skin's stylesheet is scoped to one mode by its selector
 * (`html.dark` or `html:not(.dark)`), and a page can carry one skin for each.
 *
 * A skin is written for one mode, its NATIVE mode (the wrapper in its stylesheet). Any skin can
 * be put in either slot: for the other mode the plugin serves the same rules with the selector
 * swapped (the MIRROR), and the base stylesheet likewise has a light twin (lightBase). Both are
 * derived by plain text substitution from files that were already validated, so they hold no
 * rule that the original didn't.
 */
final class Modes
{
    public const DARK = 'dark';
    public const LIGHT = 'light';
    public const ALL = [self::DARK, self::LIGHT];

    public const DARK_SELECTOR = 'html.dark';
    public const LIGHT_SELECTOR = 'html:not(.dark)';

    public static function valid(mixed $mode): bool
    {
        return $mode === self::DARK || $mode === self::LIGHT;
    }

    public static function other(string $mode): string
    {
        return $mode === self::DARK ? self::LIGHT : self::DARK;
    }

    public static function selector(string $mode): string
    {
        return $mode === self::DARK ? self::DARK_SELECTOR : self::LIGHT_SELECTOR;
    }

    /**
     * Which mode a stylesheet is written for, judged by its root blocks: null if it has none, or
     * has some of each (a stylesheet is for one mode).
     */
    public static function nativeOf(string $css): ?string
    {
        $dark = preg_match('/^html\.dark \{$/m', $css) === 1;
        $light = preg_match('/^html:not\(\.dark\) \{$/m', $css) === 1;

        return $dark === $light ? null : ($dark ? self::DARK : self::LIGHT);
    }

    /**
     * The same stylesheet for the other mode: every root block's selector swapped. Null if the
     * stylesheet isn't clearly written for one mode.
     */
    public static function mirror(string $css): ?string
    {
        $native = self::nativeOf($css);
        if ($native === null) {
            return null;
        }
        $from = $native === self::DARK ? '/^html\.dark \{$/m' : '/^html:not\(\.dark\) \{$/m';

        return (string) preg_replace($from, self::selector(self::other($native)) . ' {', $css);
    }

    /**
     * The base stylesheet's twin for light mode: every rule that applies to `html.dark` applies to
     * `html:not(.dark)` instead, and the ornament gate looks for the light slot's own mark
     * (`data-ts-orn-light`), so a dark skin's ornaments don't switch the light ones on. Blocks fenced
     * `ts:dark-only` in base.css (the dark map: inverted tiles and a black attribution bar) are
     * dropped: a light page keeps LibreNMS's own map.
     */
    public static function lightBase(string $base): string
    {
        $base = (string) preg_replace('#/\* ts:dark-only[^*]*\*/.*?/\* ts:end-dark-only \*/\n?#s', '', $base);
        $base = str_replace('html.dark:has(link[data-ts-orn])', 'html.dark:has(link[data-ts-orn-light])', $base);

        return str_replace(self::DARK_SELECTOR, self::LIGHT_SELECTOR, $base);
    }
}
