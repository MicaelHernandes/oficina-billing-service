<?php

namespace Domain\Billing\Application;

use Domain\Billing\Domain\Entities\Budget;
use Domain\Billing\Domain\Entities\Payment;
use Domain\Billing\Domain\Enums\BudgetStatus;
use Domain\Billing\Domain\Enums\PaymentStatus;
use Domain\Billing\Domain\Exceptions\DuplicateMessageException;
use Domain\Billing\Domain\Messages\Envelope;
use Domain\Billing\Domain\Messages\MessageType;
use Domain\Billing\Domain\Ports\CustomerNotifier;
use Domain\Billing\Domain\Ports\PaymentGateway;
use Domain\Billing\Domain\Repositories\BillingStoreInterface;
use Domain\Billing\Domain\Repositories\UnitOfWork;
use Illuminate\Support\Facades\Log;

/**
 * Executa os comandos que o orquestrador da saga (OS Service) envia ao
 * Billing. Cada comando grava inbox + estado + eventos numa única transação;
 * chamadas ao gateway acontecem antes do commit e notificações ao cliente,
 * depois.
 */
class CommandHandler
{
    public function __construct(
        private readonly BillingStoreInterface $store,
        private readonly PaymentGateway $gateway,
        private readonly CustomerNotifier $notifier,
    ) {}

    public function handle(Envelope $command): void
    {
        $type = $command->messageType();

        if ($type === null || $command->osId === null) {
            Log::info('mensagem ignorada pelo Billing', $command->logContext());

            return;
        }

        try {
            match ($type) {
                MessageType::GenerateBudget => $this->generateBudget($command),
                MessageType::RequestPayment => $this->requestPayment($command),
                MessageType::CancelPayment => $this->cancelPayment($command),
                MessageType::RefundPayment => $this->refundPayment($command),
                MessageType::CancelBudget => $this->cancelBudget($command),
                default => Log::info('evento do próprio Billing ignorado', $command->logContext()),
            };
        } catch (DuplicateMessageException) {
            Log::info('comando duplicado ignorado', $command->logContext());
        }
    }

    private function generateBudget(Envelope $command): void
    {
        $budget = Budget::fromCommand($command->osId, $command->sagaId, $command->correlationId, $command->payload);
        $work = new UnitOfWork($command);

        // Renegociação: a proposta anterior ainda pendente deixa de valer.
        foreach ($this->store->budgetsOf($command->osId) as $previous) {
            if ($previous->getStatus() === BudgetStatus::Pending) {
                $previous->supersede();
                $work->saveBudget($previous);
            }
        }

        $work->saveBudget($budget)->publish(Events::about($budget, MessageType::BudgetGenerated, [
            'budget_id' => $budget->getId(),
            'revision' => $budget->getRevision(),
            'total_amount' => $budget->getTotalAmount(),
        ]));

        $this->store->commit($work);
        $this->notifier->budgetReady($budget);

        Log::info('orçamento gerado', $command->logContext() + ['budget_id' => $budget->getId()]);
    }

    private function requestPayment(Envelope $command): void
    {
        $budget = $this->resolveApprovedBudget($command);

        if ($budget === null) {
            $this->store->commit((new UnitOfWork($command))->publish(
                Events::replyTo($command, MessageType::PaymentFailed, ['reason' => 'orçamento aprovado não encontrado'])
            ));

            return;
        }

        // Já existe cobrança em aberto (ou paga) para este orçamento: reaproveita.
        foreach ($this->store->paymentsOf($command->osId) as $existing) {
            if ($existing->getBudgetId() === $budget->getId()
                && in_array($existing->getStatus(), [PaymentStatus::Pending, PaymentStatus::Approved], true)) {
                $this->store->commit(new UnitOfWork($command));

                return;
            }
        }

        $payment = Payment::forBudget($budget, (float) ($command->payload['amount'] ?? 0));
        $checkout = $this->gateway->createCheckout($payment, $budget);
        $payment->attachCheckout($checkout->reference, $checkout->url);

        $this->store->commit((new UnitOfWork($command))->savePayment($payment));
        $this->notifier->paymentRequested($budget, $payment);

        Log::info('cobrança criada', $command->logContext() + ['payment_id' => $payment->getId()]);
    }

    private function cancelPayment(Envelope $command): void
    {
        $work = new UnitOfWork($command);

        foreach ($this->store->paymentsOf($command->osId) as $payment) {
            if ($payment->getStatus() === PaymentStatus::Pending) {
                $payment->cancel();
                $work->savePayment($payment);
            }
        }
        $this->cancelBudgetsInto($work, $command->osId);

        $this->store->commit($work);
    }

    /**
     * Compensação da saga. Sempre responde com billing.payment.refunded para
     * a saga fechar — `refunded: false` quando não havia o que estornar.
     */
    private function refundPayment(Envelope $command): void
    {
        $payment = $this->findPaymentToRefund($command);
        $work = new UnitOfWork($command);
        $refunded = false;

        if ($payment !== null && $payment->getStatus() === PaymentStatus::Approved) {
            $this->gateway->refund((string) $payment->getGatewayPaymentId(), $command->id);
            $payment->refund();
            $work->savePayment($payment);
            $refunded = true;
        }

        $this->cancelBudgetsInto($work, $command->osId);

        $work->publish(Events::replyTo($command, MessageType::PaymentRefunded, [
            'payment_id' => $payment?->getId(),
            'refunded' => $refunded,
            'amount' => $refunded ? $payment->getAmount() : 0.0,
        ]));

        $this->store->commit($work);

        Log::info('estorno processado', $command->logContext() + ['refunded' => $refunded]);
    }

    private function cancelBudget(Envelope $command): void
    {
        $work = new UnitOfWork($command);
        $this->cancelBudgetsInto($work, $command->osId);
        $this->store->commit($work);
    }

    private function cancelBudgetsInto(UnitOfWork $work, int $osId): void
    {
        foreach ($this->store->budgetsOf($osId) as $budget) {
            if (in_array($budget->getStatus(), [BudgetStatus::Pending, BudgetStatus::Approved], true)) {
                $budget->cancel();
                $work->saveBudget($budget);
            }
        }
    }

    private function resolveApprovedBudget(Envelope $command): ?Budget
    {
        $budgetId = $command->payload['budget_id'] ?? null;
        $budget = is_string($budgetId) ? $this->store->findBudget($budgetId) : null;

        $budget ??= collect($this->store->budgetsOf($command->osId))
            ->first(fn (Budget $b) => $b->getStatus() === BudgetStatus::Approved);

        return $budget?->getStatus() === BudgetStatus::Approved ? $budget : null;
    }

    private function findPaymentToRefund(Envelope $command): ?Payment
    {
        $paymentId = $command->payload['payment_id'] ?? null;
        if (is_string($paymentId) && ($payment = $this->store->findPayment($paymentId)) !== null) {
            return $payment;
        }

        return collect($this->store->paymentsOf($command->osId))
            ->first(fn (Payment $p) => $p->getStatus() === PaymentStatus::Approved);
    }
}
