<?php

namespace Xblossia\ThemeSelector\Graph;

use App\Facades\LibrenmsConfig;
use LibreNMS\Data\Store\Rrd;
use Throwable;

/**
 * LibreNMS's RRD store, with the port traffic series recoloured from graph_colours.port_in and
 * graph_colours.port_out just before rrdtool draws (see PortSeries).
 *
 * Only graph() is overridden. Never loaded unless PortSeriesSupport::compatible() said the parent
 * still has the shape this assumes, and only installed on web requests (the ones that draw
 * graphs), by ThemeSelectorProvider. Any failure draws the graph as core built it.
 */
class RecolouringRrd extends Rrd
{
    public function graph(array $options): string
    {
        try {
            $options = PortSeries::recolour(
                $options,
                LibrenmsConfig::get('graph_colours.port_in'),
                LibrenmsConfig::get('graph_colours.port_out'),
            );
        } catch (Throwable) {
            // draw it as core built it
        }

        return parent::graph($options);
    }
}
