<?php

use Domain\Billing\Application\CommandHandler;
use Domain\Billing\Domain\Messages\Envelope;
use Domain\Billing\Domain\Messages\MessageType;
use Domain\Billing\Domain\Repositories\BillingStoreInterface;

/** Comando do orquestrador (OS Service) para a OS. */
function command(MessageType $type, int $osId = 42, array $payload = []): Envelope
{
    return Envelope::new($type, 'saga-'.$osId, 'req-'.$osId, $osId, 'oficina-os-service', $payload);
}

function budgetCommand(int $osId = 42, int $revision = 1, float $total = 300.0): Envelope
{
    return command(MessageType::GenerateBudget, $osId, [
        'revision' => $revision,
        'customer' => ['id' => 7, 'name' => 'Cliente Billing', 'email' => 'cliente@test.com', 'document' => '52998224725'],
        'services' => [['service_id' => 1, 'service_name' => 'Revisão', 'quantity' => 1, 'unit_price' => 200.0]],
        'parts' => [['part_id' => 3, 'part_name' => 'Pastilha', 'quantity' => 2, 'unit_price' => 50.0]],
        'total_services' => 200.0,
        'total_parts' => 100.0,
        'total_amount' => $total,
    ]);
}

function dispatchCommand(Envelope $command): Envelope
{
    app(CommandHandler::class)->handle($command);

    return $command;
}

function store(): BillingStoreInterface
{
    return app(BillingStoreInterface::class);
}

/** @return Envelope[] eventos pendentes no outbox, em ordem de gravação */
function outbox(): array
{
    return array_values(store()->pendingOutbox(100));
}

/** @return string[] */
function outboxTypes(): array
{
    return array_map(fn (Envelope $e) => $e->type, outbox());
}

function lastEvent(MessageType $type): Envelope
{
    $events = array_values(array_filter(outbox(), fn (Envelope $e) => $e->type === $type->value));

    return end($events) ?: throw new RuntimeException("evento {$type->value} não encontrado no outbox");
}
