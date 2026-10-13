<?php

namespace Xblossia\ThemeSelector;

/**
 * Fixed pieces of page markup that a bundled skin can ask for (Features), added to the end of the
 * page. Each is a file in resources/effects/ written by hand and shipped in this package: nothing
 * a skin, an upload or a visitor supplies ever reaches the output.
 *
 * white-rabbit: on any page, one load in ten, a small white rabbit appears at the bottom right for
 * three and a half seconds and fades out. Click it and the whole screen goes black for under five
 * seconds with one line typed on it and a cursor that blinks three times, then it is gone as if it
 * had not happened.
 *
 * eye-of-providence (the Paisley skins): on any page, one load in twelve, a small eye in a triangle
 * with rays opens at the bottom left, glances to one side and the other, and fades out after five
 * seconds. Click it and purple rain falls all over the screen for ten seconds, then fades out. Its
 * colours are the page's own palette variables (the skin's gold, violet and glow), with fallbacks.
 *
 * The limits, both: pure CSS, no script. The click is a hidden checkbox inside a label that the
 * sprite belongs to (the checkbox is display:none, so it can't be focused or tabbed to), and the
 * overlay or the rain is shown by `:checked`. Hidden for visitors who ask for reduced motion; every
 * animation ends and leaves nothing behind; read by no screen reader. The rain never takes clicks;
 * the overlay does for its few seconds, and a click on it ends it.
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
    public function html(array $effects, callable $roll): string
    {
        $html = '';
        if (in_array('white-rabbit', $effects, true) && $roll(self::RABBIT_ONE_IN) === 1) {
            $html .= $this->read('white-rabbit.html');
        }
        if (in_array('eye-of-providence', $effects, true) && $roll(self::EYE_ONE_IN) === 1) {
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
