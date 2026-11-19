<?php

// Query the bootstrapped application directly. Some newer LibreNMS images
// replace artisan route:list with a command that lists only Restify routes.
try {
    if (! isset($argv[1])) {
        throw new RuntimeException('Expected a route name');
    }
    chdir('/opt/librenms');
    require 'vendor/autoload.php';
    $app = require 'bootstrap/app.php';
    $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
    $describe = $argv[1] === '--describe';
    $name = $describe ? ($argv[2] ?? '') : $argv[1];
    if ($name === '') {
        throw new RuntimeException('Expected a route name');
    }
    $count = 0;
    $matches = [];
    foreach ($app['router']->getRoutes()->getRoutes() as $route) {
        $actual = $route->getName();
        if ($actual === $name || ($name === 'theme-selector' && is_string($actual) && str_starts_with($actual, 'theme-selector.'))) {
            $count++;
            if ($describe) {
                $matches[] = ['name' => $actual, 'uri' => $route->uri(), 'methods' => $route->methods(),
                    'middleware' => $app['router']->gatherRouteMiddleware($route)];
            }
        }
    }
    echo $describe ? json_encode($matches, JSON_THROW_ON_ERROR) : $count, PHP_EOL;
} catch (Throwable $e) {
    fwrite(STDERR, 'Could not inspect the route registry: ' . $e->getMessage() . PHP_EOL);
    exit(1);
}
