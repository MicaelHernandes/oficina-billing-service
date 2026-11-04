<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Prometheus\CollectorRegistry;
use Symfony\Component\HttpFoundation\Response;

/**
 * Coleta métricas por requisição: histograma de latência (rota/método/status)
 * e contador de falhas de integração (respostas 5xx). Persiste no registry
 * (APCu) lido pelo endpoint /metrics.
 */
class PrometheusMetrics
{
    public function __construct(private readonly CollectorRegistry $registry) {}

    public function handle(Request $request, Closure $next): Response
    {
        $start = microtime(true);

        /** @var Response $response */
        $response = $next($request);

        $this->record($request, $response, microtime(true) - $start);

        return $response;
    }

    private function record(Request $request, Response $response, float $seconds): void
    {
        $ns = config('metrics.namespace', 'oficina');
        $route = $request->route()?->uri() ?? $request->path();
        $method = $request->getMethod();
        $status = (string) $response->getStatusCode();

        try {
            $histogram = $this->registry->getOrRegisterHistogram(
                $ns,
                'http_request_duration_seconds',
                'Latência das requisições HTTP em segundos',
                ['method', 'route', 'status'],
                [0.05, 0.1, 0.25, 0.5, 1, 2.5, 5, 10],
            );
            $histogram->observe($seconds, [$method, $route, $status]);

            if ($response->getStatusCode() >= 500) {
                $failures = $this->registry->getOrRegisterCounter(
                    $ns,
                    'integration_failures_total',
                    'Total de falhas (respostas 5xx) nas integrações/handlers',
                    ['route'],
                );
                $failures->inc([$route]);
            }
        } catch (\Throwable $e) {
            // Métricas nunca devem quebrar a requisição.
        }
    }
}
