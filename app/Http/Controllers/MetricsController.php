<?php

namespace App\Http\Controllers;

use Domain\Billing\Domain\Repositories\BillingStoreInterface;
use Illuminate\Http\Response;
use Prometheus\CollectorRegistry;
use Prometheus\RenderTextFormat;

/**
 * /metrics (formato texto do Prometheus): latência por rota (middleware),
 * notificações do Mercado Pago por resultado e o outbox ainda não publicado.
 */
class MetricsController extends Controller
{
    public function __construct(
        private readonly CollectorRegistry $registry,
        private readonly BillingStoreInterface $store,
    ) {}

    public function __invoke(): Response
    {
        try {
            $this->registry
                ->getOrRegisterGauge(config('metrics.namespace'), 'outbox_pending_messages', 'Mensagens do outbox ainda não publicadas', [])
                ->set((float) count($this->store->pendingOutbox(1000)), []);
        } catch (\Throwable $e) {
            // DynamoDB indisponível: segue com as métricas de requisição.
        }

        $body = (new RenderTextFormat)->render($this->registry->getMetricFamilySamples());

        return response($body, 200, ['Content-Type' => RenderTextFormat::MIME_TYPE]);
    }
}
