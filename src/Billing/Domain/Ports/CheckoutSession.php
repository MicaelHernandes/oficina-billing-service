<?php

namespace Domain\Billing\Domain\Ports;

final class CheckoutSession
{
    public function __construct(
        public readonly string $reference,
        public readonly string $url,
    ) {}
}
