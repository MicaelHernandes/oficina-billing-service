<?php

namespace Domain\Billing\Domain\Enums;

enum PaymentStatus: string
{
    case Pending = 'pending';
    case Approved = 'approved';
    case Rejected = 'rejected';
    case Cancelled = 'cancelled';
    case Refunded = 'refunded';

    public function isFinal(): bool
    {
        return $this !== self::Pending;
    }
}
