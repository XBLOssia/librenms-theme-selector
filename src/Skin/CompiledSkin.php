<?php

namespace Xblossia\ThemeSelector\Skin;

/**
 * A skin that passed every check: what to publish and what to record.
 * Built only by SkinCompiler.
 */
final class CompiledSkin
{
    /**
     * @param  array{id: string, name: string, description: string, author: string, version: string, license: string, family: string, mode: string, modes: string[]}  $manifest
     * @param  array<string, string|string[]>  $graph  validated graph palette, possibly empty
     */
    public function __construct(
        public readonly array $manifest,
        public readonly string $css,
        public readonly array $graph,
        public readonly string $sha256,
        public readonly int $fontCount,
        public readonly string $licenseText = '',
        /** @var array<int, array{name: string, width: int, height: int, bytes: int}> */
        public readonly array $textures = [],
        /** The same rules for the other mode (Modes::mirror), published beside skin.css so the skin can be put in either slot. */
        public readonly string $mirror = '',
    ) {
    }

    public function id(): string
    {
        return $this->manifest['id'];
    }
}
