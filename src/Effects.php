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
 */
final class Effects
{
    public const RABBIT_ONE_IN = 10;

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

        return $html;
    }

    private function read(string $name): string
    {
        $text = @file_get_contents("$this->dir/$name");

        return is_string($text) ? $text : '';
    }
}
