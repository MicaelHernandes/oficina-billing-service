<?php

namespace Domain\Billing\Domain\Repositories;

use Domain\Billing\Domain\Entities\Budget;
use Domain\Billing\Domain\Entities\Payment;
use Domain\Billing\Domain\Messages\Envelope;

/**
 * Tudo o que precisa ser gravado de uma vez (TransactWriteItems no DynamoDB):
 * a mensagem recebida (inbox, idempotência), o estado alterado e as
 * mensagens a publicar (outbox). Ou tudo é gravado, ou nada.
 */
final class UnitOfWork
{
    /** @var array<string, Budget> */
    public array $budgets = [];

    /** @var array<string, Payment> */
    public array $payments = [];

    /** @var Envelope[] */
    public array $outbox = [];

    public function __construct(public readonly ?Envelope $consumed = null) {}

    public function saveBudget(Budget $budget): self
    {
        $this->budgets[$budget->getId()] = $budget;

        return $this;
    }

    public function savePayment(Payment $payment): self
    {
        $this->payments[$payment->getId()] = $payment;

        return $this;
    }

    public function publish(Envelope $envelope): self
    {
        $this->outbox[] = $envelope;

        return $this;
    }
}
