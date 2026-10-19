<?php

namespace Domain\Billing\Domain\Messages;

/**
 * Contrato das mensagens da saga da OS do lado do Billing (espelho do
 * MessageType do OS Service). O Billing consome os comandos `billing.*` do
 * tópico do OS Service e publica os eventos `billing.*` no próprio tópico.
 */
enum MessageType: string
{
    // ── Comandos recebidos do OS Service (orquestrador) ──────────────
    case GenerateBudget = 'billing.budget.generate';
    case CancelBudget = 'billing.budget.cancel';
    case RequestPayment = 'billing.payment.request';
    case CancelPayment = 'billing.payment.cancel';
    case RefundPayment = 'billing.payment.refund';

    // ── Eventos publicados para o OS Service ─────────────────────────
    case BudgetGenerated = 'billing.budget.generated';
    case BudgetApproved = 'billing.budget.approved';
    case BudgetRejected = 'billing.budget.rejected';
    case PaymentConfirmed = 'billing.payment.confirmed';
    case PaymentFailed = 'billing.payment.failed';
    case PaymentRefunded = 'billing.payment.refunded';
}
