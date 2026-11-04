<?php

namespace Domain\Billing\Infrastructure\Payments;

use Domain\Billing\Domain\Entities\Budget;
use Domain\Billing\Domain\Entities\Payment;
use Domain\Billing\Domain\Exceptions\DomainException;
use Domain\Billing\Domain\Ports\CheckoutSession;
use Domain\Billing\Domain\Ports\GatewayPayment;
use Domain\Billing\Domain\Ports\PaymentGateway;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\URL;

/**
 * Gateway simulado (PAYMENT_DRIVER=fake): o link de pagamento abre um
 * checkout assinado do próprio Billing, onde se aprova ou recusa a cobrança.
 * Serve para desenvolvimento local e para demonstrar a saga sem depender das
 * credenciais do Mercado Pago.
 */
class FakePaymentGateway implements PaymentGateway
{
    public function createCheckout(Payment $payment, Budget $budget): CheckoutSession
    {
        return new CheckoutSession(
            reference: "fake-pref-{$payment->getId()}",
            url: URL::temporarySignedRoute(
                'billing.fake-checkout',
                now()->addDays((int) config('billing.link_ttl_days')),
                ['payment' => $payment->getId()],
            ),
        );
    }

    public function fetchPayment(string $gatewayPaymentId): GatewayPayment
    {
        throw DomainException::because('O gateway fake não recebe webhooks; use o checkout simulado.');
    }

    public function refund(string $gatewayPaymentId, string $idempotencyKey): void
    {
        Log::info('estorno simulado (gateway fake)', ['gateway_payment_id' => $gatewayPaymentId]);
    }
}
