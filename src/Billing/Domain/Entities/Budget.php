<?php

namespace Domain\Billing\Domain\Entities;

use Domain\Billing\Domain\Enums\BudgetStatus;
use Domain\Billing\Domain\Exceptions\DomainException;
use Illuminate\Support\Str;

/**
 * Orçamento oficial enviado ao cliente. Cada renegociação gera uma nova
 * revisão; a anterior fica `superseded`.
 */
class Budget
{
    /**
     * @param  array<string, mixed>|null  $customer
     * @param  array<int, array<string, mixed>>  $services
     * @param  array<int, array<string, mixed>>  $parts
     */
    public function __construct(
        private readonly string $id,
        private readonly int $osId,
        private readonly ?string $sagaId,
        private readonly ?string $correlationId,
        private readonly int $revision,
        private BudgetStatus $status,
        private readonly ?array $customer,
        private readonly array $services,
        private readonly array $parts,
        private readonly float $totalServices,
        private readonly float $totalParts,
        private readonly float $totalAmount,
        private readonly ?string $notes,
        private readonly \DateTimeImmutable $createdAt,
        private ?\DateTimeImmutable $decidedAt = null,
    ) {}

    /** @param array<string, mixed> $payload payload do comando billing.budget.generate */
    public static function fromCommand(int $osId, ?string $sagaId, ?string $correlationId, array $payload): self
    {
        $total = (float) ($payload['total_amount'] ?? 0);
        if ($total <= 0) {
            throw DomainException::because('Orçamento sem valor: total_amount deve ser maior que zero.');
        }

        return new self(
            id: (string) Str::uuid(),
            osId: $osId,
            sagaId: $sagaId,
            correlationId: $correlationId,
            revision: max(1, (int) ($payload['revision'] ?? 1)),
            status: BudgetStatus::Pending,
            customer: is_array($payload['customer'] ?? null) ? $payload['customer'] : null,
            services: $payload['services'] ?? [],
            parts: $payload['parts'] ?? [],
            totalServices: (float) ($payload['total_services'] ?? 0),
            totalParts: (float) ($payload['total_parts'] ?? 0),
            totalAmount: $total,
            notes: $payload['notes'] ?? null,
            createdAt: new \DateTimeImmutable,
        );
    }

    public function getId(): string
    {
        return $this->id;
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

    public function getRevision(): int
    {
        return $this->revision;
    }

    public function getStatus(): BudgetStatus
    {
        return $this->status;
    }

    /** @return array<string, mixed>|null */
    public function getCustomer(): ?array
    {
        return $this->customer;
    }

    public function getCustomerEmail(): ?string
    {
        return $this->customer['email'] ?? null;
    }

    public function getTotalAmount(): float
    {
        return $this->totalAmount;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getDecidedAt(): ?\DateTimeImmutable
    {
        return $this->decidedAt;
    }

    public function approve(): void
    {
        $this->decide(BudgetStatus::Approved);
    }

    public function reject(): void
    {
        $this->decide(BudgetStatus::Rejected);
    }

    public function supersede(): void
    {
        $this->decide(BudgetStatus::Superseded);
    }

    public function cancel(): void
    {
        if (in_array($this->status, [BudgetStatus::Pending, BudgetStatus::Approved], true)) {
            $this->status = BudgetStatus::Cancelled;
            $this->decidedAt = new \DateTimeImmutable;
        }
    }

    private function decide(BudgetStatus $status): void
    {
        if ($this->status !== BudgetStatus::Pending) {
            throw DomainException::because(
                "O orçamento já está '{$this->status->label()}' e não pode mais ser alterado."
            );
        }

        $this->status = $status;
        $this->decidedAt = new \DateTimeImmutable;
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'os_id' => $this->osId,
            'saga_id' => $this->sagaId,
            'correlation_id' => $this->correlationId,
            'revision' => $this->revision,
            'status' => $this->status->value,
            'status_label' => $this->status->label(),
            'customer' => $this->customer,
            'services' => $this->services,
            'parts' => $this->parts,
            'total_services' => $this->totalServices,
            'total_parts' => $this->totalParts,
            'total_amount' => $this->totalAmount,
            'notes' => $this->notes,
            'created_at' => $this->createdAt->format(DATE_ATOM),
            'decided_at' => $this->decidedAt?->format(DATE_ATOM),
        ];
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        return new self(
            id: $data['id'],
            osId: (int) $data['os_id'],
            sagaId: $data['saga_id'] ?? null,
            correlationId: $data['correlation_id'] ?? null,
            revision: (int) $data['revision'],
            status: BudgetStatus::from($data['status']),
            customer: $data['customer'] ?? null,
            services: $data['services'] ?? [],
            parts: $data['parts'] ?? [],
            totalServices: (float) $data['total_services'],
            totalParts: (float) $data['total_parts'],
            totalAmount: (float) $data['total_amount'],
            notes: $data['notes'] ?? null,
            createdAt: new \DateTimeImmutable($data['created_at']),
            decidedAt: isset($data['decided_at']) ? new \DateTimeImmutable($data['decided_at']) : null,
        );
    }
}
