<?php

namespace Domain\Billing\Infrastructure\Messaging;

use Domain\Billing\Domain\Messages\Envelope;
use Domain\Billing\Domain\Ports\MessagePublisher;
use Illuminate\Support\Facades\Log;

/** Desenvolvimento local: só registra a mensagem no log. */
class LogMessagePublisher implements MessagePublisher
{
    public function publish(Envelope $envelope): void
    {
        Log::info('mensagem (driver log)', $envelope->toArray());
    }
}
