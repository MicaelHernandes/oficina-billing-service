<?php

namespace Domain\Billing\Domain\Enums;

enum BudgetStatus: string
{
    case Pending = 'pending';
    case Approved = 'approved';
    case Rejected = 'rejected';
    // Substituído por uma revisão mais nova (renegociação).
    case Superseded = 'superseded';
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Aguardando aprovação',
            self::Approved => 'Aprovado',
            self::Rejected => 'Recusado',
            self::Superseded => 'Substituído por nova proposta',
            self::Cancelled => 'Cancelado',
        };
    }
}
