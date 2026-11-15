<?php

namespace Domain\Billing\Application;

use Domain\Billing\Domain\Entities\Payment;
use Domain\Billing\Domain\Enums\PaymentStatus;
use Domain\Billing\Domain\Messages\MessageType;
use Domain\Billing\Domain\Ports\GatewayPayment;
use Domain\Billing\Domain\Ports\PaymentGateway;
use Domain\Billing\Domain\Repositories\BillingStoreInterface;
use Domain\Billing\Domain\Repositories\UnitOfWork;
use Illuminate\Support\Facades\Log;

/**
 * Notificação de pagamento do gateway (webhook do Mercado Pago ou checkout
 * fake). Confirma ou recusa a cobrança e avisa a saga.
 */
class PaymentNotificationHandler
{
    public function __construct(
        private readonly BillingStoreInterface $store,
        private readonly PaymentGateway $gateway,
    ) {}

    /** Webhook: o gateway só informa o id; o status é consultado na API. */
    public function fromWebhook(string $gatewayPaymentId): ?Payment
    {
        return $this->apply($this->gateway->fetchPayment($gatewayPaymentId));
    }

    public function apply(GatewayPayment $notification): ?Payment
    {
        $payment = $notification->externalReference !== null
            ? $this->store->findPayment($notification->externalReference)
            : null;

        if ($payment === null) {
            Log::warning('notificação de pagamento sem cobrança correspondente', [
                'gateway_payment_id' => $notification->id,
                'external_reference' => $notification->externalReference,
            ]);

            return null;
        }

        // Notificações repetidas (o gateway reenvia) não mudam uma cobrança já decidida.
        if ($payment->getStatus() !== PaymentStatus::Pending || $notification->status === GatewayPayment::PENDING) {
            return $payment;
        }

        $work = new UnitOfWork;

        if ($notification->status === GatewayPayment::APPROVED) {
            $payment->approve($notification->id);
            $work->publish(Events::about($payment, MessageType::PaymentConfirmed, [
                'payment_id' => $payment->getId(),
                'gateway_payment_id' => $notification->id,
                'amount' => $payment->getAmount(),
            ]));
        } else {
            $reason = $notification->statusDetail ?? 'rejected';
            $payment->reject($reason, $notification->id);
            $work->publish(Events::about($payment, MessageType::PaymentFailed, [
                'payment_id' => $payment->getId(),
                'reason' => $reason,
            ]));
        }

        $this->store->commit($work->savePayment($payment));

        Log::info('pagamento atualizado', [
            'payment_id' => $payment->getId(),
            'status' => $payment->getStatus()->value,
            'saga_id' => $payment->getSagaId(),
            'correlation_id' => $payment->getCorrelationId(),
            'os_id' => $payment->getOsId(),
        ]);

        return $payment;
    }
}
