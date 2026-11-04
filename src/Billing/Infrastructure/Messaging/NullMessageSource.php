<?php

namespace Domain\Billing\Infrastructure\Messaging;

use Domain\Billing\Domain\Ports\MessageSource;
use Domain\Billing\Domain\Ports\ReceivedMessage;

/** Sem fila configurada (local/testes): nunca recebe nada. */
class NullMessageSource implements MessageSource
{
    public function receive(): array
    {
        return [];
    }

    public function ack(ReceivedMessage $message): void {}
}
