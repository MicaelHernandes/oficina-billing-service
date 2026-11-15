<?php

namespace Domain\Billing\Application;

use Domain\Billing\Domain\Entities\Budget;
use Domain\Billing\Domain\Entities\Payment;
use Domain\Billing\Domain\Messages\Envelope;
use Domain\Billing\Domain\Messages\MessageType;

/** Monta os eventos do Billing herdando saga, correlação e OS do agregado. */
final class Events
{
    /** @param array<string, mixed> $payload */
    public static function about(Budget|Payment $subject, MessageType $type, array $payload): Envelope
    {
        return Envelope::new(
            type: $type,
            sagaId: $subject->getSagaId(),
            correlationId: $subject->getCorrelationId(),
            osId: $subject->getOsId(),
            source: (string) config('messaging.source'),
            payload: $payload,
        );
    }

    /** Evento em resposta a um comando, quando ainda não há agregado (ex.: orçamento inexistente). */
    public static function replyTo(Envelope $command, MessageType $type, array $payload): Envelope
    {
        return Envelope::new(
            type: $type,
            sagaId: $command->sagaId,
            correlationId: $command->correlationId,
            osId: $command->osId,
            source: (string) config('messaging.source'),
            payload: $payload,
        );
    }
}
