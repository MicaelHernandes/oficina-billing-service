<?php

namespace Domain\Billing\Domain\Ports;

/** Pagamento como o gateway o reporta (status normalizado). */
final class GatewayPayment
{
    public const APPROVED = 'approved';

    public const REJECTED = 'rejected';

    public const PENDING = 'pending';

    public function __construct(
        public readonly string $id,
        public readonly string $status,
        public readonly ?string $externalReference,
        public readonly ?string $statusDetail = null,
    ) {}

    /** Mapeia os status do Mercado Pago para approved | rejected | pending. */
    public static function normalize(string $gatewayStatus): string
    {
        return match ($gatewayStatus) {
            'approved', 'authorized' => self::APPROVED,
            'rejected', 'cancelled', 'refunded', 'charged_back' => self::REJECTED,
            default => self::PENDING, // pending, in_process, in_mediation
        };
    }
}
