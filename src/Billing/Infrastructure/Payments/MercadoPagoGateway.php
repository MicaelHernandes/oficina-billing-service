<?php

namespace Domain\Billing\Infrastructure\Payments;

use Domain\Billing\Domain\Entities\Budget;
use Domain\Billing\Domain\Entities\Payment;
use Domain\Billing\Domain\Ports\CheckoutSession;
use Domain\Billing\Domain\Ports\GatewayPayment;
use Domain\Billing\Domain\Ports\PaymentGateway;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;

/**
 * Mercado Pago (API REST):
 * - Checkout Pro: POST /checkout/preferences, com external_reference = id do
 *   Payment e notification_url apontando para o webhook do Billing;
 * - consulta: GET /v1/payments/{id};
 * - estorno total: POST /v1/payments/{id}/refunds (X-Idempotency-Key).
 */
class MercadoPagoGateway implements PaymentGateway
{
    public function __construct(
        private readonly string $baseUrl,
        private readonly string $accessToken,
        private readonly bool $sandbox,
        private readonly int $timeout = 10,
    ) {}

    public function createCheckout(Payment $payment, Budget $budget): CheckoutSession
    {
        $response = $this->http()
            ->withHeaders(['X-Idempotency-Key' => $payment->getId()])
            ->post('/checkout/preferences', [
                'items' => [[
                    'id' => $budget->getId(),
                    'title' => "Ordem de Serviço #{$budget->getOsId()} — orçamento rev. {$budget->getRevision()}",
                    'quantity' => 1,
                    'currency_id' => 'BRL',
                    'unit_price' => round($payment->getAmount(), 2),
                ]],
                'payer' => array_filter(['email' => $budget->getCustomerEmail()]),
                'external_reference' => $payment->getId(),
                'notification_url' => route('billing.webhooks.mercadopago'),
                'metadata' => [
                    'os_id' => $budget->getOsId(),
                    'saga_id' => $budget->getSagaId(),
                    'correlation_id' => $budget->getCorrelationId(),
                ],
            ])
            ->throw()
            ->json();

        return new CheckoutSession(
            reference: (string) $response['id'],
            url: (string) ($this->sandbox ? $response['sandbox_init_point'] : $response['init_point']),
        );
    }

    public function fetchPayment(string $gatewayPaymentId): GatewayPayment
    {
        $payment = $this->http()->get("/v1/payments/{$gatewayPaymentId}")->throw()->json();

        return new GatewayPayment(
            id: (string) $payment['id'],
            status: GatewayPayment::normalize((string) $payment['status']),
            externalReference: $payment['external_reference'] ?? null,
            statusDetail: $payment['status_detail'] ?? null,
        );
    }

    public function refund(string $gatewayPaymentId, string $idempotencyKey): void
    {
        $this->http()
            ->withHeaders(['X-Idempotency-Key' => $idempotencyKey])
            ->post("/v1/payments/{$gatewayPaymentId}/refunds", (object) [])
            ->throw();
    }

    private function http(): PendingRequest
    {
        return Http::baseUrl($this->baseUrl)
            ->withToken($this->accessToken)
            ->acceptJson()
            ->timeout($this->timeout)
            ->retry(2, 200, throw: false);
    }
}
