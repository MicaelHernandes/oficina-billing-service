<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/**
 * Garante um X-Request-Id por requisição (gera se ausente) e o propaga na
 * resposta e no contexto de log. Também captura o x-amzn-trace-id do API
 * Gateway para correlação ponta a ponta.
 */
class RequestId
{
    public function handle(Request $request, Closure $next): Response
    {
        $requestId = $request->header('X-Request-Id') ?: (string) Str::uuid();
        $request->headers->set('X-Request-Id', $requestId);

        // Disponibiliza para os logs (JsonFormatter lê do contexto compartilhado).
        Log::shareContext([
            'request_id' => $requestId,
            'amzn_trace_id' => $request->header('x-amzn-trace-id'),
        ]);

        /** @var Response $response */
        $response = $next($request);
        $response->headers->set('X-Request-Id', $requestId);

        return $response;
    }
}
