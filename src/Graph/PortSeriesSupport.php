<?php

namespace Xblossia\ThemeSelector\Graph;

use ReflectionClass;
use ReflectionException;
use ReflectionNamedType;

/**
 * Whether it is safe to subclass LibreNMS's RRD store to recolour the port series.
 *
 * RecolouringRrd extends LibreNMS\Data\Store\Rrd and overrides graph(). If core ever changed that
 * method's shape, PHP would refuse to load the subclass with a fatal error that can't be caught,
 * on every request. So the subclass is only ever loaded after this has checked, by reflection,
 * that the parent is exactly what the override assumes. Anything else answers "no" and the
 * graphs are simply drawn in stock colours.
 */
final class PortSeriesSupport
{
    public const STORE = 'LibreNMS\\Data\\Store\\Rrd';

    /** Is $class (default: the real store) a class RecolouringRrd can extend? */
    public static function compatible(string $class = self::STORE): bool
    {
        if (! class_exists($class)) {
            return false;
        }

        try {
            $rc = new ReflectionClass($class);
            if ($rc->isFinal() || $rc->isAbstract() || ! $rc->isInstantiable()) {
                return false;
            }
            $constructor = $rc->getConstructor();
            if ($constructor !== null && $constructor->getNumberOfRequiredParameters() > 0) {
                return false;
            }
            if (! $rc->hasMethod('graph')) {
                return false;
            }
            $graph = $rc->getMethod('graph');
            if ($graph->isFinal() || $graph->isStatic() || ! $graph->isPublic() || $graph->isAbstract()) {
                return false;
            }
            $params = $graph->getParameters();
            if (count($params) !== 1) {
                return false;
            }
            [$param] = $params;
            $type = $param->getType();
            if (! $type instanceof ReflectionNamedType || $type->getName() !== 'array' || $type->allowsNull()
                || $param->isVariadic() || $param->isPassedByReference()) {
                return false;
            }
            $return = $graph->getReturnType();

            return $return instanceof ReflectionNamedType && $return->getName() === 'string' && ! $return->allowsNull();
        } catch (ReflectionException) {
            return false;
        }
    }
}
