<?php

declare(strict_types=1);

use Xblossia\ThemeSelector\Graph\PortSeries;
use Xblossia\ThemeSelector\Graph\PortSeriesSupport;

/** Stand-ins for LibreNMS's RRD store, in every shape the guard has to judge. */
class PS_Good
{
    public function graph(array $options): string
    {
        return '';
    }
}
class PS_NoMethod
{
}
final class PS_Final
{
    public function graph(array $options): string
    {
        return '';
    }
}
class PS_FinalMethod
{
    final public function graph(array $options): string
    {
        return '';
    }
}
class PS_Static
{
    public static function graph(array $options): string
    {
        return '';
    }
}
class PS_Private
{
    private function graph(array $options): string
    {
        return '';
    }
}
class PS_Untyped
{
    public function graph($options): string
    {
        return '';
    }
}
class PS_NoReturnType
{
    public function graph(array $options)
    {
        return '';
    }
}
class PS_TwoParams
{
    public function graph(array $options, int $timeout = 5): string
    {
        return '';
    }
}
class PS_NullableReturn
{
    public function graph(array $options): ?string
    {
        return '';
    }
}
class PS_ObjectReturn
{
    public function graph(array $options): object
    {
        return new stdClass();
    }
}
class PS_Nullable
{
    public function graph(?array $options): string
    {
        return '';
    }
}
class PS_Ctor
{
    public function __construct(string $path)
    {
    }

    public function graph(array $options): string
    {
        return '';
    }
}
abstract class PS_Abstract
{
    abstract public function graph(array $options): string;
}

function test_port_series(): void
{
    T::group('port graph colours: rewriting the six series options');
    $in = ['AA0001', 'AA0002', 'AA0003'];
    $out = ['BB0001', 'BB0002', 'BB0003'];

    // generic_data.inc.php's six options, as it writes them (not stacked: no alpha; stacked: '88').
    $stock = fn (string $a, string $f) => [
        "AREA:in{$f}_max#D7FFC7$a:",
        "AREA:in{$f}#90B040$a:",
        "LINE:in{$f}#608720:In ",
        "AREA:dout{$f}_max#E0E0FF$a:",
        "AREA:dout{$f}#8080C0$a:",
        "LINE:dout{$f}#606090:Out",
    ];
    $want = fn (string $a, string $f) => [
        "AREA:in{$f}_max#AA0001$a:",
        "AREA:in{$f}#AA0002$a:",
        "LINE:in{$f}#AA0003:In ",
        "AREA:dout{$f}_max#BB0001$a:",
        "AREA:dout{$f}#BB0002$a:",
        "LINE:dout{$f}#BB0003:Out",
    ];
    foreach (['bits', 'octets'] as $f) {
        foreach (['', '88'] as $a) {
            $label = "$f" . ($a === '' ? '' : ' stacked');
            T::ok("the six options are recoloured ($label)", PortSeries::recolour($stock($a, $f), $in, $out) === $want($a, $f), json_encode(PortSeries::recolour($stock($a, $f), $in, $out)));
        }
    }

    $surround = ['DEF:inbits=x.rrd:INOCTETS:AVERAGE', 'COMMENT:bps      Now', 'GPRINT:inbits:LAST:%6.2lf%s', 'HRULE:percentilehigh#FF0000:Highest', 'LINE1:percentile_in#aa0000', 'LINE2:ilsl#44aa55:\'In Linear Prediction\\n\':dashes=8', 7];
    $mixed = array_merge(array_slice($surround, 0, 3), $stock('', 'bits'), array_slice($surround, 3));
    $got = PortSeries::recolour($mixed, $in, $out);
    T::ok('options around them are untouched, and so is their order', array_merge(array_slice($surround, 0, 3), $want('', 'bits'), array_slice($surround, 3)) === $got);

    T::ok('lower-case hex in the stock options still matches', PortSeries::recolour(['AREA:inbits#90b040:'], $in, $out) === ['AREA:inbits#AA0002:']);
    T::ok('palette entries are applied as given, upper-cased', PortSeries::recolour(['AREA:inbits#90B040:'], ['aaaaaa', 'bbbbbb', 'cccccc'], null) === ['AREA:inbits#BBBBBB:']);

    T::group('port graph colours: what must not be touched');
    $same = fn (array $o, $i = null, $u = null) => PortSeries::recolour($o, $i ?? ['AA0001', 'AA0002', 'AA0003'], $u ?? ['BB0001', 'BB0002', 'BB0003']) === $o;
    T::ok('a colour that is not the stock literal is left alone (a caller chose it)', $same(['AREA:inbits#CDEB8B:', 'LINE:dout' . 'bits#000099:Out']));
    T::ok('a stock literal on the wrong role is left alone', $same(['AREA:inbits#608720:', 'AREA:inbits_max#90B040:', 'LINE:inbits#D7FFC7:In ']));
    T::ok('a stock "in" colour on an out series is left alone', $same(['AREA:doutbits#90B040:']));
    T::ok('a LINE of the _max series is left alone', $same(['LINE:inbits_max#608720:']));
    T::ok('other series names are left alone (generic_duplex, generic_simplex, ...)', $same(['AREA:in#90B040:', 'AREA:in_max#D7FFC7:', 'AREA:dout#8080C0:', 'AREA:ifInOctets#90B040:', 'AREA:inbitsX#90B040:']));
    T::ok('other commands are left alone', $same(['HRULE:inbits#90B040:', 'GPRINT:inbits#90B040:', 'LINE1:inbits#90B040:', 'XAREA:inbits#90B040:', ' AREA:inbits#90B040:']));
    T::ok('a longer hex or no hex is left alone', $same(['AREA:inbits#90B0401234:', 'AREA:inbits#90B04:', 'AREA:inbits:']));
    T::ok('already recoloured options are left alone (idempotent)', PortSeries::recolour(PortSeries::recolour($stock('', 'bits'), $in, $out), $in, $out) === $want('', 'bits'));
    T::ok('non-string entries survive', $same([1, null, ['AREA:inbits#90B040:'], 1.5, true]));

    T::group('port graph colours: a palette that cannot be used');
    foreach ([
        'null' => null, 'a string' => 'AA0001', 'an empty list' => [], 'two tones' => ['AA0001', 'AA0002'],
        'a non-hex tone' => ['AA0001', 'zzzzzz', 'AA0003'], 'a tone with a #' => ['#AA0001', 'AA0002', 'AA0003'],
        'a tone with alpha' => ['AA0001', 'AA0002', 'AA000388'], 'a short tone' => ['AA0001', 'AA002', 'AA0003'],
        'a number' => [111111, 222222, 333333], 'nested' => [['AA0001'], 'AA0002', 'AA0003'], 'an injection' => ['AA0001', 'AA0002', "AA0003:\nHRULE"], 'a tone with a trailing newline' => ['AA0001', 'AA0002', "AA0003\n"],
    ] as $label => $bad) {
        T::ok("$label leaves the series as core drew them", PortSeries::recolour($stock('', 'bits'), $bad, $bad) === $stock('', 'bits'));
    }
    $half = PortSeries::recolour($stock('', 'bits'), $in, null);
    T::ok('a usable "in" and an unusable "out" recolours only "in"', array_slice($half, 0, 3) === array_slice($want('', 'bits'), 0, 3) && array_slice($half, 3) === array_slice($stock('', 'bits'), 3));
    T::ok('a list with more than three tones uses the first three', PortSeries::recolour(['LINE:inbits#608720:In '], ['AA0001', 'AA0002', 'AA0003', 'AA0004'], null) === ['LINE:inbits#AA0003:In ']);
    T::ok('a list with keys is read in order', PortSeries::recolour(['LINE:inbits#608720:In '], ['x' => 'AA0001', 'y' => 'AA0002', 'z' => 'AA0003'], null) === ['LINE:inbits#AA0003:In ']);

    T::group('port graph colours: is it safe to subclass core\'s store?');
    $ok = fn (string $c) => PortSeriesSupport::compatible($c);
    T::ok('a store with graph(array): string is accepted', $ok('PS_Good'));
    T::ok('a class that does not exist is refused', ! $ok('PS_Missing_' . bin2hex(random_bytes(3))));
    foreach (['PS_NoMethod' => 'no graph()', 'PS_Final' => 'a final class', 'PS_FinalMethod' => 'a final graph()', 'PS_Static' => 'a static graph()',
        'PS_Private' => 'a private graph()', 'PS_Untyped' => 'an untyped parameter', 'PS_NoReturnType' => 'no return type', 'PS_TwoParams' => 'a second parameter',
        'PS_NullableReturn' => 'a nullable return', 'PS_ObjectReturn' => 'a different return type', 'PS_Nullable' => 'a nullable parameter',
        'PS_Ctor' => 'a constructor that needs arguments', 'PS_Abstract' => 'an abstract class'] as $class => $why) {
        T::ok("$why is refused", ! $ok($class));
    }
    T::ok('the real store is refused outside LibreNMS (not loaded)', ! PortSeriesSupport::compatible());
}
