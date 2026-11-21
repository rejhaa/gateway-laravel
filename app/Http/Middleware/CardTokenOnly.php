<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Gateway\CardToken;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Lets a request through only with a valid card token in the X-Card-Token
 * header, and puts the checked token on the request for the controller. A
 * session cookie counts for nothing here.
 */
final class CardTokenOnly
{
    public const EXPIRED = 'This card has expired. Decide on the approval page.';

    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $token = CardToken::check($request->header(CardToken::HEADER));

        if ($token === null) {
            return response()->json(['error' => self::EXPIRED], 401);
        }

        $request->attributes->set(CardToken::class, $token);

        return $next($request);
    }
}
