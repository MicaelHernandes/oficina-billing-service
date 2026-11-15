<?php

namespace Domain\Billing\Application;

use Domain\Billing\Domain\Entities\Budget;
use Domain\Billing\Domain\Exceptions\NotFoundException;
use Domain\Billing\Domain\Messages\MessageType;
use Domain\Billing\Domain\Repositories\BillingStoreInterface;
use Domain\Billing\Domain\Repositories\UnitOfWork;

/** Aprovação ou recusa do orçamento pelo cliente (links assinados do e-mail). */
class BudgetDecision
{
    public function __construct(private readonly BillingStoreInterface $store) {}

    public function approve(string $budgetId): Budget
    {
        $budget = $this->find($budgetId);
        $budget->approve();

        $this->store->commit((new UnitOfWork)->saveBudget($budget)->publish(
            Events::about($budget, MessageType::BudgetApproved, [
                'budget_id' => $budget->getId(),
                'revision' => $budget->getRevision(),
            ])
        ));

        return $budget;
    }

    /** `$final`: o cliente desiste da OS; senão, pede uma nova proposta. */
    public function reject(string $budgetId, bool $final): Budget
    {
        $budget = $this->find($budgetId);
        $budget->reject();

        $this->store->commit((new UnitOfWork)->saveBudget($budget)->publish(
            Events::about($budget, MessageType::BudgetRejected, [
                'budget_id' => $budget->getId(),
                'revision' => $budget->getRevision(),
                'final' => $final,
            ])
        ));

        return $budget;
    }

    private function find(string $budgetId): Budget
    {
        return $this->store->findBudget($budgetId)
            ?? throw NotFoundException::forEntity('Orçamento', $budgetId);
    }
}
