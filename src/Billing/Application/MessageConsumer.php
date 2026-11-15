<?php

namespace Domain\Billing\Application;

use Domain\Billing\Domain\Exceptions\DomainException;
use Domain\Billing\Domain\Messages\Envelope;
use Domain\Billing\Domain\Ports\MessageSource;
use Illuminate\Support\Facades\Log;

/**
 * Lê um lote da fila e entrega cada mensagem ao CommandHandler. Só confirma
 * (ack) o que foi processado; o resto volta para a fila e, depois do limite
 * de tentativas da redrive policy, cai na DLQ.
 */
class MessageConsumer
{
    public function __construct(
        private readonly MessageSource $source,
        private readonly CommandHandler $handler,
    ) {}

    /** @return array{processed: int, failed: int} */
    public function consumeBatch(): array
    {
        $processed = 0;
        $failed = 0;

        foreach ($this->source->receive() as $received) {
            try {
                $envelope = Envelope::fromJson($received->body);
            } catch (DomainException $e) {
                // Mensagem malformada nunca vai dar certo: descarta.
                Log::error('mensagem malformada descartada', ['error' => $e->getMessage()]);
                $this->source->ack($received);

                continue;
            }

            Log::withContext($envelope->logContext());

            try {
                $this->handler->handle($envelope);
                $this->source->ack($received);
                $processed++;
            } catch (\Throwable $e) {
                $failed++;
                Log::error('falha ao processar mensagem; volta para a fila', $envelope->logContext() + [
                    'error' => $e->getMessage(),
                ]);
            } finally {
                Log::withoutContext();
            }
        }

        return ['processed' => $processed, 'failed' => $failed];
    }
}
