<?php

namespace Domain\Billing\Domain\Entities;

use Domain\Billing\Domain\Enums\PaymentStatus;
use Illuminate\Support\Str;

/**
 * Cobrança do orçamento aprovado no gateway (Mercado Pago Checkout Pro).
 * `gatewayReference` é a preferência criada; `gatewayPaymentId` é o
 * pagamento efetivo informado pelo webhook.
 */
class Payment
{
    public function __construct(
        private readonly string $id,
        private readonly string $budgetId,
        private readonly int $osId,
        private readonly ?string $sagaId,
        private readonly ?string $correlationId,
        private readonly float $amount,
        private PaymentStatus $status,
        private ?string $gatewayReference = null,
        private ?string $checkoutUrl = null,
        private ?string $gatewayPaymentId = null,
        private ?string $failureReason = null,
        private ?\DateTimeImmutable $createdAt = null,
        private ?\DateTimeImmutable $updatedAt = null,
    ) {
        $this->createdAt ??= new \DateTimeImmutable;
    }

    public static function forBudget(Budget $budget, float $amount): self
    {
        return new self(
            id: (string) Str::uuid(),
            budgetId: $budget->getId(),
            osId: $budget->getOsId(),
            sagaId: $budget->getSagaId(),
            correlationId: $budget->getCorrelationId(),
            amount: $amount > 0 ? $amount : $budget->getTotalAmount(),
            status: PaymentStatus::Pending,
        );
    }

    public function getId(): string
    {
        return $this->id;
    }

    public function getBudgetId(): string
    {
        return $this->budgetId;
    }

    public function getOsId(): int
    {
        return $this->osId;
    }

    public function getSagaId(): ?string
    {
        return $this->sagaId;
    }

    public function getCorrelationId(): ?string
    {
        return $this->correlationId;
    }

    public function getAmount(): float
    {
        return $this->amount;
    }

    public function getStatus(): PaymentStatus
    {
        return $this->status;
    }

    public function getCheckoutUrl(): ?string
    {
        return $this->checkoutUrl;
    }

    public function getGatewayPaymentId(): ?string
    {
        return $this->gatewayPaymentId;
    }

    public function getFailureReason(): ?string
    {
        return $this->failureReason;
    }

    public function attachCheckout(string $reference, string $url): void
    {
        $this->gatewayReference = $reference;
        $this->checkoutUrl = $url;
        $this->touch();
    }

    public function approve(string $gatewayPaymentId): void
    {
        $this->status = PaymentStatus::Approved;
        $this->gatewayPaymentId = $gatewayPaymentId;
        $this->touch();
    }

    public function reject(string $reason, ?string $gatewayPaymentId = null): void
    {
        $this->status = PaymentStatus::Rejected;
        $this->failureReason = $reason;
        $this->gatewayPaymentId = $gatewayPaymentId ?? $this->gatewayPaymentId;
        $this->touch();
    }

    public function cancel(): void
    {
        $this->status = PaymentStatus::Cancelled;
        $this->touch();
    }

    public function refund(): void
    {
        $this->status = PaymentStatus::Refunded;
        $this->touch();
    }

    private function touch(): void
    {
        $this->updatedAt = new \DateTimeImmutable;
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'budget_id' => $this->budgetId,
            'os_id' => $this->osId,
            'saga_id' => $this->sagaId,
            'correlation_id' => $this->correlationId,
            'amount' => $this->amount,
            'status' => $this->status->value,
            'gateway_reference' => $this->gatewayReference,
            'checkout_url' => $this->checkoutUrl,
            'gateway_payment_id' => $this->gatewayPaymentId,
            'failure_reason' => $this->failureReason,
            'created_at' => $this->createdAt?->format(DATE_ATOM),
            'updated_at' => $this->updatedAt?->format(DATE_ATOM),
        ];
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        return new self(
            id: $data['id'],
            budgetId: $data['budget_id'],
            osId: (int) $data['os_id'],
            sagaId: $data['saga_id'] ?? null,
            correlationId: $data['correlation_id'] ?? null,
            amount: (float) $data['amount'],
            status: PaymentStatus::from($data['status']),
            gatewayReference: $data['gateway_reference'] ?? null,
            checkoutUrl: $data['checkout_url'] ?? null,
            gatewayPaymentId: $data['gateway_payment_id'] ?? null,
            failureReason: $data['failure_reason'] ?? null,
            createdAt: isset($data['created_at']) ? new \DateTimeImmutable($data['created_at']) : null,
            updatedAt: isset($data['updated_at']) ? new \DateTimeImmutable($data['updated_at']) : null,
        );
    }
}
