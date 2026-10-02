<?php

namespace Xblossia\ThemeSelector;

/**
 * What a BUNDLED skin may ask of the plugin beyond its stylesheet, from skins/<id>/features.json:
 *
 *   {"ornaments": true, "effects": ["white-rabbit"]}
 *
 * - `ornaments`: apply the ornament rules in base.css (frame slots, cut corners, motion), which an
 *   uploaded skin always gets and a bundled skin otherwise doesn't.
 * - `effects`: small, fixed pieces of page markup that only this package can add (see Effects).
 *
 * Read only for bundled skins, from the package, never from the web root or an upload, and only
 * these two keys and the effect names below are honoured; anything else is ignored. An uploaded
 * skin's manifest can carry neither (Manifest refuses unknown fields), which is a known gap
 * between the two kinds of skin: docs/ROADMAP.md, "Bundled-only features".
 */
final class Features
{
    public const EFFECTS = ['white-rabbit'];

    /** @return array{ornaments: bool, effects: string[]} */
    public static function parse(?string $json): array
    {
        $none = ['ornaments' => false, 'effects' => []];
        if ($json === null || strlen($json) > 2048) {
            return $none;
        }
        $data = json_decode($json, true, 4);
        if (! is_array($data) || array_is_list($data)) {
            return $none;
        }
        $effects = $data['effects'] ?? [];

        return [
            'ornaments' => ($data['ornaments'] ?? false) === true,
            'effects' => is_array($effects) ? array_values(array_intersect(self::EFFECTS, array_filter($effects, 'is_string'))) : [],
        ];
    }
}
