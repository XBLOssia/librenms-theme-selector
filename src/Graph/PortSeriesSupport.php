<?php

namespace Xblossia\ThemeSelector\Graph;

use ReflectionClass;
use ReflectionException;
use ReflectionNamedType;

/**
 * Whether a supported LibreNMS RRD interface is safe to use for port colours.
 *
 * RecolouringRrd extends LibreNMS\Data\Store\Rrd and overrides graph(). If core ever changed that
 * method's shape, PHP would refuse to load the subclass with a fatal error that can't be caught,
 * on every request. So the subclass is only ever loaded after this has checked, by reflection,
 * that the parent is exactly what the override assumes. Anything else answers "no" and the
 * graphs are simply drawn in stock colours. Newer LibreNMS versions expose a
 * backend interface instead; its complete contract is checked before wrapping it.
 */
final class PortSeriesSupport
{
    public const STORE = 'LibreNMS\\Data\\Store\\Rrd';
    public const BACKEND = 'LibreNMS\\RRD\\Backend\\RrdBackendInterface';

    /** The newer backend is wrapped only when its complete contract is known. */
    public static function backendCompatible(string $interface = self::BACKEND): bool
    {
        if (! interface_exists($interface)) {
            return false;
        }

        $path = 'LibreNMS\\RRD\\RrdPath';
        $definition = 'LibreNMS\\RRD\\RrdDefinition';
        $expected = [
            'create' => [[$path, $definition], 'void'],
            'update' => [[$path, 'array', '?int='], 'void'],
            'tune' => [[$path, 'array'], 'void'],
            'last' => [[$path], 'int'],
            'list' => [['string', 'string='], 'array'],
            'graph' => [['array', '?string='], 'string'],
            'remote' => [[], 'bool'],
        ];
        try {
            $rc = new ReflectionClass($interface);
            $methods = $rc->getMethods();
            if (! $rc->isInterface() || count($methods) !== count($expected)) {
                return false;
            }
            foreach ($methods as $method) {
                $spec = $expected[$method->getName()] ?? null;
                $return = $method->getReturnType();
                if ($spec === null || ! $method->isPublic() || $method->isStatic()
                    || $method->returnsReference() || ! $return instanceof ReflectionNamedType
                    || $return->allowsNull() || $return->getName() !== $spec[1]) {
                    return false;
                }
                $params = $method->getParameters();
                if (count($params) !== count($spec[0])) {
                    return false;
                }
                foreach ($params as $i => $param) {
                    $wanted = $spec[0][$i];
                    $optional = str_ends_with($wanted, '=');
                    $nullable = str_starts_with($wanted, '?');
                    $type = $param->getType();
                    if (! $type instanceof ReflectionNamedType || $type->getName() !== trim($wanted, '?=')
                        || $type->allowsNull() !== $nullable || $param->isOptional() !== $optional
                        || $param->isVariadic() || $param->isPassedByReference()) {
                        return false;
                    }
                }
            }
            return true;
        } catch (ReflectionException) {
            return false;
        }
    }

    public static function available(): bool
    {
        return self::backendCompatible() || self::compatible();
    }

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
