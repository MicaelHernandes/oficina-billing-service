<?php

namespace Domain\Billing\Presentation\Commands;

use Domain\Billing\Application\OutboxRelay;
use Illuminate\Console\Command;

/** Publica o outbox no SNS (Deployment oficina-billing-worker). */
class RelayOutboxCommand extends Command
{
    protected $signature = 'billing:relay
        {--once : Publica um único lote e sai}
        {--sleep=1 : Segundos de espera quando não há pendências}
        {--max-time=3600 : Encerra após N segundos (o Deployment reinicia o processo)}';

    protected $description = 'Publica no broker as mensagens pendentes do outbox';

    private bool $shouldStop = false;

    public function handle(OutboxRelay $relay): int
    {
        $this->trap([SIGTERM, SIGINT], function (): void {
            $this->shouldStop = true;
        });
        $deadline = time() + (int) $this->option('max-time');

        do {
            $published = $relay->relay();

            if ($published > 0) {
                $this->line("publicadas: {$published}");
            } elseif (! $this->option('once')) {
                sleep((int) $this->option('sleep'));
            }
        } while (! $this->option('once') && ! $this->shouldStop && time() < $deadline);

        return self::SUCCESS;
    }
}
