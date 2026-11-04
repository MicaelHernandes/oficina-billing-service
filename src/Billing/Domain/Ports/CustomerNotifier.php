<?php

namespace Domain\Billing\Domain\Ports;

use Domain\Billing\Domain\Entities\Budget;
use Domain\Billing\Domain\Entities\Payment;

interface CustomerNotifier
{
    /** Envia o orçamento com os links assinados de aprovação/recusa. */
    public function budgetReady(Budget $budget): void;

    /** Envia o link de pagamento do orçamento aprovado. */
    public function paymentRequested(Budget $budget, Payment $payment): void;
}
