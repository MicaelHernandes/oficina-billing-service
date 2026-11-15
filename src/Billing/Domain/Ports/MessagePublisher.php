<?php

namespace Domain\Billing\Domain\Ports;

use Domain\Billing\Domain\Messages\Envelope;

interface MessagePublisher
{
    public function publish(Envelope $envelope): void;
}
