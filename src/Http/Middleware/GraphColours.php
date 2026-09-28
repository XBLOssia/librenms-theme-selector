<?php

namespace Xblossia\ThemeSelector\Http\Middleware;

use App\Facades\LibrenmsConfig;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;
use Throwable;
use Xblossia\ThemeSelector\GraphPalette;
use Xblossia\ThemeSelector\SkinResolver;

/**
 * Gives each user's graphs their own skin's colours.
 *
 * Added to the `web` group, so it runs after the session starts and before
 * the graph route's own middleware. It only does anything on /graph (and
 * /graph.php, which core rewrites to it), and only changes LibreNMS's
 * in-memory config for the current request: LibrenmsConfig::set() never
 * writes to the database or the config cache. The next request, and every
 * other user, sees the persistent config as it was.
 *
 * Any failure leaves the graph in the instance's persistent colours.
 */
class GraphColours
{
    public function __construct(
        private readonly SkinResolver $resolver,
        private readonly GraphPalette $palette,
    ) {
    }

    public function handle(Request $request, Closure $next): Response
    {
        if ($request->is('graph', 'graph/*')) {
            try {
                // A request with no session user (signed URL, allowed IP)
                // resolves to the instance default, which the config holds.
                $user = Auth::user();
                if ($user !== null) {
                    $overrides = $this->palette->overridesFor(
                        $this->resolver->forUser($user),
                        $this->resolver->default(),
                    );
                    foreach ($overrides as $key => $value) {
                        LibrenmsConfig::set($key, $value);
                    }
                }
            } catch (Throwable $e) {
                Log::warning('ThemeSelector: per-user graph colours skipped: ' . $e->getMessage());
            }
        }

        return $next($request);
    }
}
