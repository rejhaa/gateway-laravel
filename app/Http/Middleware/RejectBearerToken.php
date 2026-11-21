<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Answers 401 to any request with an Authorization header, so Claude's OAuth
 * token can never decide a proposal (DESIGN.md, section 4). It runs on the
 * decision endpoints and never on Passport's own /oauth routes, where a client
 * may authenticate with that header.
 */
final class RejectBearerToken
{
    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->headers->has('Authorization')) {
            return response()->json(['error' => 'This endpoint does not accept an Authorization header.'], 401);
        }

        return $next($request);
    }
}
