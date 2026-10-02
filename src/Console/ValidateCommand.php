<?php

namespace Xblossia\ThemeSelector\Console;

use Illuminate\Console\Command;
use Xblossia\ThemeSelector\Skin\Report;
use Xblossia\ThemeSelector\Skin\SkinCompiler;

/**
 * Check a skin bundle exactly as the upload page would, without installing it.
 *
 * For skin authors: run it while you work and fix everything it lists. It
 * changes nothing and needs no admin session.
 */
class ValidateCommand extends Command
{
    protected $signature = 'theme-selector:validate {bundle : path to a skin .zip}';

    protected $description = 'Check a skin bundle the way the upload page would, without installing it';

    public function handle(SkinCompiler $compiler): int
    {
        $path = (string) $this->argument('bundle');
        if (! is_file($path)) {
            $this->error("No such file: $path");

            return self::FAILURE;
        }

        $report = new Report();
        $skin = $compiler->compileZip($path, $report);

        if ($skin === null) {
            $this->error('Not accepted:');
            foreach ($report->errors() as $problem) {
                $this->line('  - ' . $problem);
            }

            return self::FAILURE;
        }

        $m = $skin->manifest;
        $this->info("Accepted: {$m['name']} ({$m['id']}) {$m['version']}");
        $this->line('  written for: ' . $m['mode'] . ' mode' . ($m['family'] !== '' ? ', family ' . $m['family'] : '') . ' (usable in either mode: the other is served as its mirror)');
        $this->line('  stylesheet: ' . number_format(strlen($skin->css)) . ' bytes, ' . $skin->fontCount . ' font(s) embedded');
        $this->line('  graph palette: ' . ($skin->graph === [] ? 'none' : count($skin->graph) . ' setting(s)'));
        $this->line('  fingerprint: ' . $skin->sha256);

        return self::SUCCESS;
    }
}
