<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** Rotas internas: exige Bearer igual a BILLING_INTERNAL_TOKEN. Sem token configurado, fecha a rota. */
class InternalToken
{
    public function handle(Request $request, Closure $next): Response
    {
        $expected = (string) config('billing.internal_token');

        if ($expected === '' || ! hash_equals($expected, (string) $request->bearerToken())) {
            return response()->json(['message' => 'Não autorizado.'], 401);
        }

        return $next($request);
    }
}
