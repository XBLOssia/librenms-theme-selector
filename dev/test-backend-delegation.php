<?php

// Run only on an image with the compatible LibreNMS backend interface. This
// fake backend never reads or writes an RRD, but checks every delegated call.
chdir('/opt/librenms');
require 'vendor/autoload.php';
$app = require 'bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use LibreNMS\RRD\Backend\RrdBackendInterface;
use LibreNMS\RRD\RrdDefinition;
use LibreNMS\RRD\RrdPath;
use Xblossia\ThemeSelector\Graph\RecolouringBackend;

class TestRrdBackend implements RrdBackendInterface
{
    public array $calls = [];
    public function create(RrdPath $rrd, RrdDefinition $definition): void { $this->calls[] = ['create', $rrd, $definition]; }
    public function update(RrdPath $rrd, array $values, ?int $timestamp = null): void { $this->calls[] = ['update', $rrd, $values, $timestamp]; if ($timestamp === -1) throw new RuntimeException('backend failure'); }
    public function tune(RrdPath $rrd, array $limits): void { $this->calls[] = ['tune', $rrd, $limits]; }
    public function last(RrdPath $rrd): int { $this->calls[] = ['last', $rrd]; return 42; }
    public function list(string $hostname, string $prefix = ''): array { $this->calls[] = ['list', $hostname, $prefix]; return [$hostname, $prefix]; }
    public function graph(array $options, ?string $timezone = null): string { $this->calls[] = ['graph', $options, $timezone]; return 'image'; }
    public function remote(): bool { $this->calls[] = ['remote']; return true; }
}

$inner = new TestRrdBackend();
$wrapper = new RecolouringBackend($inner);
$path = RrdPath::make('synthetic.invalid', 'port.rrd');
$definition = RrdDefinition::make();
$wrapper->create($path, $definition);
$wrapper->update($path, [1, null, '2'], 123);
$wrapper->tune($path, ['in' => ['max' => 4]]);
$ok = $wrapper->last($path) === 42 && $wrapper->list('synthetic.invalid', 'port') === ['synthetic.invalid', 'port']
    && $wrapper->graph(['--imgformat', 'SVG'], 'UTC') === 'image' && $wrapper->remote();
$expected = [
    ['create', $path, $definition], ['update', $path, [1, null, '2'], 123],
    ['tune', $path, ['in' => ['max' => 4]]], ['last', $path], ['list', 'synthetic.invalid', 'port'],
    ['graph', ['--imgformat', 'SVG'], 'UTC'], ['remote'],
];
$ok = $ok && $inner->calls === $expected;
try {
    $wrapper->update($path, [], -1);
    $ok = false;
} catch (RuntimeException $e) {
    $ok = $ok && $e->getMessage() === 'backend failure';
}
echo $ok ? "backend operations and errors delegated\n" : "backend delegation failed\n";
exit($ok ? 0 : 1);
