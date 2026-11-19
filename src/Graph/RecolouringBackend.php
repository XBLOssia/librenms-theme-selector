<?php

namespace Xblossia\ThemeSelector\Graph;

use App\Facades\LibrenmsConfig;
use LibreNMS\RRD\Backend\RrdBackendInterface;
use LibreNMS\RRD\RrdDefinition;
use LibreNMS\RRD\RrdPath;
use Throwable;

/** Delegate every backend operation, changing only the port graph colors. */
final class RecolouringBackend implements RrdBackendInterface
{
    public function __construct(private readonly RrdBackendInterface $inner) {}

    public function create(RrdPath $rrd, RrdDefinition $definition): void { $this->inner->create($rrd, $definition); }
    public function update(RrdPath $rrd, array $values, ?int $timestamp = null): void { $this->inner->update($rrd, $values, $timestamp); }
    public function tune(RrdPath $rrd, array $limits): void { $this->inner->tune($rrd, $limits); }
    public function last(RrdPath $rrd): int { return $this->inner->last($rrd); }
    public function list(string $hostname, string $prefix = ''): array { return $this->inner->list($hostname, $prefix); }
    public function remote(): bool { return $this->inner->remote(); }

    public function graph(array $options, ?string $timezone = null): string
    {
        try {
            $options = PortSeries::recolour($options, LibrenmsConfig::get('graph_colours.port_in'), LibrenmsConfig::get('graph_colours.port_out'));
        } catch (Throwable) {
            // Leave the original graph options intact if the palette is unavailable.
        }
        return $this->inner->graph($options, $timezone);
    }
}
