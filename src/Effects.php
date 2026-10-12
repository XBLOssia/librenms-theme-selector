<?php

namespace Xblossia\ThemeSelector;

/**
 * Fixed pieces of page markup that a bundled skin can ask for (Features), added to the end of the
 * page. Each is a file in resources/effects/ written by hand and shipped in this package: nothing
 * a skin, an upload or a visitor supplies ever reaches the output.
 *
 * white-rabbit: on a device page, one load in ten, a small white rabbit appears at the bottom
 * right for about a second and fades out. Pure CSS (no script); hidden for visitors who ask for
 * reduced motion; can't be clicked, focused or read by a screen reader.
 *
 * eye-of-providence (the Paisley skins): on a device page, one load in twelve, a small eye in a
 * triangle with rays opens at the bottom left, glances to one side and the other, and fades out
 * after five seconds. Its colours are the page's own palette variables (the skin's gold, violet and
 * glow), with fallbacks. The same limits: pure CSS, no script, hidden under reduced motion,
 * can't be clicked, focused or read.
 */
final class Effects
{
    public const RABBIT_ONE_IN = 10;

    public const EYE_ONE_IN = 12;

    public function __construct(private readonly string $dir)
    {
    }

    /**
     * @param  string[]  $effects  from Features::parse
     * @param  callable(int): int  $roll  returns a whole number from 1 to the argument (random_int in production)
     */
    public function html(array $effects, bool $devicePage, callable $roll): string
    {
        $html = '';
        if ($devicePage && in_array('white-rabbit', $effects, true) && $roll(self::RABBIT_ONE_IN) === 1) {
            $html .= $this->read('white-rabbit.html');
        }
        if ($devicePage && in_array('eye-of-providence', $effects, true) && $roll(self::EYE_ONE_IN) === 1) {
            $html .= $this->read('eye-of-providence.html');
        }

        return $html;
    }

    private function read(string $name): string
    {
        $text = @file_get_contents("$this->dir/$name");

        return is_string($text) ? $text : '';
    }
}
