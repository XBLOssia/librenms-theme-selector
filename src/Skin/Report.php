<?php

namespace Xblossia\ThemeSelector\Skin;

/**
 * What a validator found. Collects every problem instead of stopping at the
 * first, so a skin author fixes a bundle in one round rather than ten.
 *
 * Everything in a message can be attacker-controlled (file names, token names,
 * values), and messages go to the log and to the page. quote() is the one
 * place that turns arbitrary bytes into something safe to print: printable
 * ASCII only, no markup characters, bounded length. Callers must pass anything
 * taken from the bundle through it.
 */
final class Report
{
    /** @var string[] */
    private array $errors = [];
    private bool $failed = false;
    private bool $truncated = false;

    public function error(string $where, string $message): void
    {
        $this->failed = true;
        if (count($this->errors) >= Limits::REPORT_ERRORS) {
            $this->truncated = true;

            return;
        }
        $this->errors[] = $where . ': ' . $message;
    }

    public function ok(): bool
    {
        return ! $this->failed;
    }

    /**
     * Fold a stage's own report into this one.
     */
    public function merge(self $other): void
    {
        foreach ($other->errors as $error) {
            $this->failed = true;
            if (count($this->errors) >= Limits::REPORT_ERRORS) {
                $this->truncated = true;

                return;
            }
            $this->errors[] = $error;
        }
        $this->failed = $this->failed || $other->failed;
        $this->truncated = $this->truncated || $other->truncated;
    }

    /**
     * @return string[]
     */
    public function errors(): array
    {
        return $this->truncated
            ? [...$this->errors, '(more problems not shown)']
            : $this->errors;
    }

    /**
     * Safe to embed in a message: printable ASCII, no `<>&"'\`, at most $max characters.
     */
    public static function quote(string $s, int $max = 60): string
    {
        $out = '';
        $len = min(strlen($s), $max);
        for ($i = 0; $i < $len; $i++) {
            $c = $s[$i];
            $out .= ($c >= ' ' && $c <= '~' && ! str_contains('<>&"\'`\\', $c)) ? $c : '?';
        }

        return strlen($s) > $max ? $out . '...' : $out;
    }
}
