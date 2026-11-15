<?php

namespace Domain\Billing\Domain\Repositories;

use Domain\Billing\Domain\Entities\Budget;
use Domain\Billing\Domain\Entities\Payment;
use Domain\Billing\Domain\Exceptions\DuplicateMessageException;
use Domain\Billing\Domain\Messages\Envelope;

interface BillingStoreInterface
{
    public function findBudget(string $id): ?Budget;

    /** @return Budget[] orçamentos da OS, da revisão mais nova para a mais antiga */
    public function budgetsOf(int $osId): array;

    public function findPayment(string $id): ?Payment;

    /** @return Payment[] cobranças da OS, da mais nova para a mais antiga */
    public function paymentsOf(int $osId): array;

    /** @throws DuplicateMessageException se a mensagem consumida já foi processada */
    public function commit(UnitOfWork $work): void;

    /** @return array<string, Envelope> mensagens pendentes do outbox, por chave */
    public function pendingOutbox(int $limit): array;

    public function markPublished(string $key): void;
}
