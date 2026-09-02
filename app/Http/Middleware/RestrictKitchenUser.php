<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Confines a `kitchen` user to the kitchen orders board.
 *
 * The kitchen terminal sits in the back and is used by whoever is cooking, so
 * it must not reach sales, costing, payroll or the POS. This is a **deny-by-
 * default allowlist**, applied to the whole authenticated route group on
 * purpose: a route added later is closed to the kitchen until someone puts it
 * on the list, rather than quietly opening because nobody remembered to gate it.
 *
 * Every other role passes straight through — this middleware only ever narrows
 * `kitchen`.
 */
class RestrictKitchenUser
{
    /**
     * Route URIs (as declared in routes/api.php, without the `api/` prefix) a
     * kitchen login may call. Placeholders are matched literally, so this is
     * compared against the route's pattern, not the resolved path.
     */
    private const ALLOWED = [
        // Session
        'logout',
        'me',
        // The board itself
        'orders/kitchen',
        'orders/{order}/kitchen-status',
        // Answering "how much longer?" — asking is the front desk's job.
        'orders/{order}/eta',
    ];

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user || $user->role !== 'kitchen') {
            return $next($request);
        }

        $uri = ltrim((string) $request->route()?->uri(), '/');
        // The API routes are registered under an `api` prefix; the allowlist is
        // written without it so it reads like routes/api.php.
        $uri = preg_replace('#^api/#', '', $uri);

        if (! in_array($uri, self::ALLOWED, true)) {
            return response()->json([
                'message' => 'This account only has access to the kitchen orders board.',
            ], 403);
        }

        return $next($request);
    }
}
