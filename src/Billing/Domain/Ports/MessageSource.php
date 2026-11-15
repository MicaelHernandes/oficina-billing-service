<?php

namespace Domain\Billing\Domain\Ports;

interface MessageSource
{
    /** @return ReceivedMessage[] lote recebido (pode ser vazio após o long polling) */
    public function receive(): array;

    /** Remove a mensagem da fila. Sem ack ela volta após o visibility timeout e, depois de N tentativas, vai para a DLQ. */
    public function ack(ReceivedMessage $message): void;
}
