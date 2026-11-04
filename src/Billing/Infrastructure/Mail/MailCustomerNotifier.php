<?php

namespace Domain\Billing\Infrastructure\Mail;

use Domain\Billing\Domain\Entities\Budget;
use Domain\Billing\Domain\Entities\Payment;
use Domain\Billing\Domain\Ports\CustomerNotifier;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\URL;

/** E-mails ao cliente. Falha de envio não desfaz o orçamento/cobrança gravados. */
class MailCustomerNotifier implements CustomerNotifier
{
    public function budgetReady(Budget $budget): void
    {
        $this->send($budget->getCustomerEmail(), new BudgetReadyMail(
            budget: $budget,
            approveUrl: $this->signed('billing.public.budgets.approve', $budget),
            rejectUrl: $this->signed('billing.public.budgets.reject', $budget),
            cancelUrl: $this->signed('billing.public.budgets.reject', $budget, ['final' => 1]),
        ), $budget);
    }

    public function paymentRequested(Budget $budget, Payment $payment): void
    {
        $this->send($budget->getCustomerEmail(), new PaymentRequestedMail($budget, $payment), $budget);
    }

    private function signed(string $route, Budget $budget, array $extra = []): string
    {
        return URL::temporarySignedRoute(
            $route,
            now()->addDays((int) config('billing.link_ttl_days')),
            ['budget' => $budget->getId()] + $extra,
        );
    }

    private function send(?string $email, object $mail, Budget $budget): void
    {
        if ($email === null) {
            Log::warning('cliente sem e-mail; notificação não enviada', ['budget_id' => $budget->getId()]);

            return;
        }

        try {
            Mail::to($email)->send($mail);
        } catch (\Throwable $e) {
            Log::error('falha ao enviar e-mail ao cliente', ['budget_id' => $budget->getId(), 'error' => $e->getMessage()]);
        }
    }
}
