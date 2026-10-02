<?php

namespace Xblossia\ThemeSelector\Skin;

use Xblossia\ThemeSelector\Modes;

/**
 * A skin's skin.json: its identity and how to show it in the picker.
 *
 * The id becomes a directory name and part of a URL, so it is a strict slug and
 * can't be one of the words the plugin uses itself. Free-text fields are held
 * to a plain printable set (no markup characters, no control characters) even
 * though they are escaped when shown: they also reach the log, and a smaller
 * alphabet is one less thing to reason about.
 *
 * Unknown keys are rejected. A manifest only ever needs these fields, and an
 * ignored key is a place for surprises to live.
 */
final class Manifest
{
    public const RESERVED_IDS = ['none', 'default', 'base', 'skins', 'fonts', 'admin', 'new', 'delete', 'upload'];

    private const TEXT = '/^[A-Za-z0-9][A-Za-z0-9 ._,()\'+:\/&-]*\z/D';
    private const DESCRIPTION = '/^[A-Za-z0-9][A-Za-z0-9 ._,()\'+:\/&!?;-]*\z/D';

    /**
     * @return array{id: string, name: string, description: string, author: string, version: string, license: string, family: string, mode: string, modes: string[]}|null
     */
    public static function parse(string $json, Report $into): ?array
    {
        $report = new Report();
        $result = self::validate($json, $report);
        $into->merge($report);

        return $result;
    }

    /**
     * @return array{id: string, name: string, description: string, author: string, version: string, license: string, family: string, mode: string, modes: string[]}|null
     */
    private static function validate(string $json, Report $report): ?array
    {
        if (strlen($json) > Limits::MANIFEST_BYTES || preg_match('/[^\x09\x0A\x0D\x20-\x7E]/', $json)) {
            $report->error('skin.json', 'is too large or contains non-ASCII or control characters');

            return null;
        }
        $data = json_decode($json, true, 4);
        if (! is_array($data) || array_is_list($data)) {
            $report->error('skin.json', 'must be a JSON object');

            return null;
        }

        $allowed = ['id', 'name', 'description', 'author', 'version', 'license', 'family', 'mode', 'modes'];
        foreach (array_keys($data) as $key) {
            if (! in_array($key, $allowed, true)) {
                $report->error('skin.json', 'unknown field ' . Report::quote((string) $key));
            }
        }

        $id = $data['id'] ?? null;
        if (! is_string($id) || ! preg_match('/^[a-z0-9][a-z0-9-]{0,62}\z/D', $id)) {
            $report->error('skin.json', '"id" must be 1-63 characters: lowercase letters, digits and hyphens, not starting with a hyphen');
        } elseif (in_array($id, self::RESERVED_IDS, true)) {
            $report->error('skin.json', '"id" ' . Report::quote($id) . ' is reserved');
        }

        $name = self::text($data, 'name', 60, self::TEXT, true, $report);
        $description = self::text($data, 'description', 200, self::DESCRIPTION, false, $report);
        $author = self::text($data, 'author', 60, self::TEXT, false, $report);
        $license = self::text($data, 'license', 60, self::TEXT, false, $report);

        $version = $data['version'] ?? '1.0.0';
        if (! is_string($version) || ! preg_match('/^\d{1,4}\.\d{1,4}\.\d{1,4}\z/D', $version)) {
            $report->error('skin.json', '"version" must look like 1.0.0');
        }

        $family = self::text($data, 'family', 40, self::TEXT, false, $report);

        // The mode the skin is written for: "dark" (the default) or "light". The older spelling,
        // "modes": ["dark"] or ["light"], means the same; if both are given they must agree.
        $mode = $data['mode'] ?? null;
        if ($mode !== null && ! Modes::valid($mode)) {
            $report->error('skin.json', '"mode" must be "dark" or "light"');
            $mode = null;
        }
        if (array_key_exists('modes', $data)) {
            $legacy = $data['modes'];
            if ($legacy !== [Modes::DARK] && $legacy !== [Modes::LIGHT]) {
                $report->error('skin.json', '"modes" must be ["dark"] or ["light"] (a skin is written for one mode; use "mode" instead)');
            } elseif ($mode !== null && $mode !== $legacy[0]) {
                $report->error('skin.json', '"mode" and "modes" disagree');
            } else {
                $mode ??= $legacy[0];
            }
        }
        $mode ??= Modes::DARK;

        if (! $report->ok()) {
            return null;
        }

        return [
            'id' => $id,
            'name' => $name,
            'description' => $description ?? '',
            'author' => $author ?? '',
            'version' => $version,
            'license' => $license ?? '',
            'family' => $family ?? '',
            'mode' => $mode,
            'modes' => [$mode],
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private static function text(array $data, string $key, int $max, string $pattern, bool $required, Report $report): ?string
    {
        $value = $data[$key] ?? null;
        if ($value === null) {
            if ($required) {
                $report->error('skin.json', "\"$key\" is required");
            }

            return null;
        }
        if (! is_string($value) || $value === '' || strlen($value) > $max || ! preg_match($pattern, $value)) {
            $report->error('skin.json', "\"$key\" must be plain text up to $max characters (letters, digits, spaces and . , ( ) ' + : / & -)");

            return null;
        }

        return $value;
    }
}
