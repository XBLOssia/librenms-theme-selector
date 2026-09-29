<?php

namespace Xblossia\ThemeSelector\Skin;

use RuntimeException;

/**
 * The --ts-* tokens that exist, and what an uploaded skin may do with each.
 * Loaded from resources/token-catalog.json, which scripts/gen-token-catalog.py
 * derives from base/base.css (dev/test.sh checks it is current).
 */
final class TokenCatalog
{
    /** @var array<string, array{structural: bool, maxPx: int, kinds: string[]}> */
    private array $tokens;

    /**
     * @param  array<string, array{structural: bool, maxPx: int, kinds: string[]}>  $tokens
     */
    private function __construct(array $tokens)
    {
        $this->tokens = $tokens;
    }

    public static function fromFile(string $path): self
    {
        $raw = @file_get_contents($path);
        $data = $raw === false ? null : json_decode($raw, true);
        if (! is_array($data) || ! is_array($data['tokens'] ?? null) || $data['tokens'] === []) {
            throw new RuntimeException("token catalog unreadable: $path");
        }

        $tokens = [];
        foreach ($data['tokens'] as $name => $entry) {
            if (! is_string($name) || ! preg_match('/^--ts-[a-z0-9-]+$/D', $name)
                || ! is_bool($entry['structural'] ?? null) || ! is_int($entry['maxPx'] ?? null)) {
                throw new RuntimeException('token catalog malformed');
            }
            $tokens[$name] = [
                'structural' => $entry['structural'],
                'maxPx' => $entry['maxPx'],
                'kinds' => array_values(array_filter((array) ($entry['kinds'] ?? []), 'is_string')),
            ];
        }

        return new self($tokens);
    }

    public function has(string $name): bool
    {
        return isset($this->tokens[$name]);
    }

    public function isStructural(string $name): bool
    {
        return $this->tokens[$name]['structural'] ?? true;
    }

    public function maxPx(string $name): int
    {
        return $this->tokens[$name]['maxPx'] ?? 0;
    }

    /**
     * @return string[]
     */
    public function kinds(string $name): array
    {
        return $this->tokens[$name]['kinds'] ?? [];
    }

    /**
     * @return string[]
     */
    public function names(): array
    {
        return array_keys($this->tokens);
    }

    /**
     * @return string[]
     */
    public function structural(): array
    {
        return array_keys(array_filter($this->tokens, fn ($t) => $t['structural']));
    }
}
