<?php

namespace Domain\Billing\Application;

use Domain\Billing\Domain\Ports\MessagePublisher;
use Domain\Billing\Domain\Repositories\BillingStoreInterface;
use Illuminate\Support\Facades\Log;

/**
 * Publica no broker as mensagens pendentes do outbox, na ordem em que foram
 * gravadas. Uma falha interrompe o ciclo e a mensagem é tentada de novo no
 * próximo (entrega "pelo menos uma vez"; o consumidor é idempotente).
 */
class OutboxRelay
{
    public function __construct(
        private readonly BillingStoreInterface $outbox,
        private readonly MessagePublisher $publisher,
    ) {}

    /** @return int quantidade publicada neste ciclo */
    public function relay(int $batchSize = 50): int
    {
        $published = 0;

        foreach ($this->outbox->pendingOutbox($batchSize) as $key => $envelope) {
            try {
                $this->publisher->publish($envelope);
                $this->outbox->markPublished($key);
                $published++;
                Log::info('mensagem publicada', $envelope->logContext());
            } catch (\Throwable $e) {
                Log::error('falha ao publicar mensagem', $envelope->logContext() + ['error' => $e->getMessage()]);

                // Preserva a ordem: não publica as seguintes antes desta.
                break;
            }
        }

        return $published;
    }
}
