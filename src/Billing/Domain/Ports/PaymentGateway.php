<?php

namespace Domain\Billing\Domain\Ports;

use Domain\Billing\Domain\Entities\Budget;
use Domain\Billing\Domain\Entities\Payment;

/** Gateway de pagamento (Mercado Pago em produção; fake em local/demo). */
interface PaymentGateway
{
    /** Cria o checkout da cobrança; `external_reference` = id do Payment. */
    public function createCheckout(Payment $payment, Budget $budget): CheckoutSession;

    /** Consulta o pagamento informado pelo webhook. */
    public function fetchPayment(string $gatewayPaymentId): GatewayPayment;

    /** Estorno total de um pagamento aprovado. */
    public function refund(string $gatewayPaymentId, string $idempotencyKey): void;
}
