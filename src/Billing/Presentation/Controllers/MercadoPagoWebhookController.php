<?php

namespace Domain\Billing\Presentation\Controllers;

use App\Http\Controllers\Controller;
use Domain\Billing\Application\PaymentNotificationHandler;
use Domain\Billing\Infrastructure\Payments\MercadoPagoSignature;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use OpenApi\Attributes as OA;
use Prometheus\CollectorRegistry;

class MercadoPagoWebhookController extends Controller
{
    public function __construct(
        private readonly PaymentNotificationHandler $handler,
        private readonly CollectorRegistry $registry,
    ) {}

    #[OA\Post(
        path: '/billing/api/webhooks/mercadopago',
        summary: 'Notificação de pagamento do Mercado Pago (valida x-signature quando MERCADOPAGO_WEBHOOK_SECRET está definido)',
        tags: ['Mercado Pago'],
        requestBody: new OA\RequestBody(content: new OA\JsonContent(properties: [
            new OA\Property(property: 'type', type: 'string', example: 'payment'),
            new OA\Property(property: 'data', properties: [new OA\Property(property: 'id', type: 'string', example: '123456789')], type: 'object'),
        ])),
        responses: [
            new OA\Response(response: 200, description: 'Notificação processada (ou ignorada, se não for de pagamento)'),
            new OA\Response(response: 401, description: 'Assinatura inválida'),
            new OA\Response(response: 502, description: 'Falha ao consultar o Mercado Pago; o MP reenvia a notificação'),
        ]
    )]
    public function __invoke(Request $request): JsonResponse
    {
        $type = $request->input('type', $request->query('topic'));
        $dataId = (string) ($request->input('data.id') ?? $request->query('data_id') ?? $request->query('id') ?? '');

        if ($type !== 'payment' || $dataId === '') {
            return $this->result('ignored', 200);
        }

        $secret = config('billing.mercadopago.webhook_secret');
        if ($secret && ! MercadoPagoSignature::isValid(
            $request->header('x-signature'),
            $request->header('x-request-id'),
            (string) ($request->query('data_id') ?? $dataId),
            $secret,
        )) {
            Log::warning('webhook do Mercado Pago com assinatura inválida', ['data_id' => $dataId]);

            return $this->result('invalid_signature', 401);
        }

        try {
            $payment = $this->handler->fromWebhook($dataId);
        } catch (\Throwable $e) {
            Log::error('falha ao processar webhook do Mercado Pago', ['data_id' => $dataId, 'error' => $e->getMessage()]);

            return $this->result('error', 502);
        }

        return $this->result($payment?->getStatus()->value ?? 'unknown_payment', 200);
    }

    private function result(string $result, int $status): JsonResponse
    {
        try {
            $this->registry
                ->getOrRegisterCounter(config('metrics.namespace'), 'mercadopago_webhooks_total', 'Notificações do Mercado Pago por resultado', ['result'])
                ->inc([$result]);
        } catch (\Throwable $e) {
            // Métricas nunca devem quebrar o webhook.
        }

        return response()->json(['result' => $result], $status);
    }
}
