<?php

namespace Domain\Billing\Infrastructure\Persistence;

use Aws\DynamoDb\DynamoDbClient;
use Aws\DynamoDb\Exception\DynamoDbException;
use Domain\Billing\Domain\Entities\Budget;
use Domain\Billing\Domain\Entities\Payment;
use Domain\Billing\Domain\Exceptions\DuplicateMessageException;
use Domain\Billing\Domain\Messages\Envelope;
use Domain\Billing\Domain\Repositories\BillingStoreInterface;
use Domain\Billing\Domain\Repositories\UnitOfWork;

/**
 * Tabela única no DynamoDB (NoSQL do Billing).
 *
 *   PK                SK        GSI1PK / GSI1SK                     GSI2PK / GSI2SK
 *   BUDGET#<id>       BUDGET    OS#<os> / BUDGET#<rev>#<criado>
 *   PAYMENT#<id>      PAYMENT   OS#<os> / PAYMENT#<criado>
 *   INBOX#<msg>       INBOX     (expira via TTL)
 *   OUTBOX#<msg>      OUTBOX                                        OUTBOX#PENDING / <ocorrido>#<msg>
 *
 * GSI1 lista orçamentos e cobranças de uma OS; GSI2 é esparso e só contém o
 * outbox pendente (o atributo some ao publicar). O estado de cada agregado
 * fica serializado em `data` (JSON), com o status também como atributo.
 */
class DynamoDbBillingStore implements BillingStoreInterface
{
    private const INBOX_TTL_DAYS = 14;

    private const OUTBOX_TTL_DAYS = 7;

    public function __construct(
        private readonly DynamoDbClient $client,
        private readonly string $table,
    ) {}

    public function findBudget(string $id): ?Budget
    {
        $data = $this->get("BUDGET#{$id}", 'BUDGET');

        return $data ? Budget::fromArray($data) : null;
    }

    public function budgetsOf(int $osId): array
    {
        return array_map(fn (array $d) => Budget::fromArray($d), $this->queryOs($osId, 'BUDGET#'));
    }

    public function findPayment(string $id): ?Payment
    {
        $data = $this->get("PAYMENT#{$id}", 'PAYMENT');

        return $data ? Payment::fromArray($data) : null;
    }

    public function paymentsOf(int $osId): array
    {
        return array_map(fn (array $d) => Payment::fromArray($d), $this->queryOs($osId, 'PAYMENT#'));
    }

    public function commit(UnitOfWork $work): void
    {
        $items = [];

        // Primeiro item: a falha da condição identifica a mensagem duplicada.
        if ($work->consumed !== null) {
            $items[] = ['Put' => [
                'TableName' => $this->table,
                'Item' => [
                    'PK' => ['S' => "INBOX#{$work->consumed->id}"],
                    'SK' => ['S' => 'INBOX'],
                    'type' => ['S' => $work->consumed->type],
                    'expires_at' => ['N' => (string) (time() + self::INBOX_TTL_DAYS * 86400)],
                ],
                'ConditionExpression' => 'attribute_not_exists(PK)',
            ]];
        }

        foreach ($work->budgets as $budget) {
            $data = $budget->toArray();
            $items[] = ['Put' => ['TableName' => $this->table, 'Item' => [
                'PK' => ['S' => "BUDGET#{$budget->getId()}"],
                'SK' => ['S' => 'BUDGET'],
                'GSI1PK' => ['S' => "OS#{$budget->getOsId()}"],
                'GSI1SK' => ['S' => sprintf('BUDGET#%05d#%s', $budget->getRevision(), $data['created_at'])],
                'status' => ['S' => $data['status']],
                'data' => ['S' => $this->json($data)],
            ]]];
        }

        foreach ($work->payments as $payment) {
            $data = $payment->toArray();
            $items[] = ['Put' => ['TableName' => $this->table, 'Item' => [
                'PK' => ['S' => "PAYMENT#{$payment->getId()}"],
                'SK' => ['S' => 'PAYMENT'],
                'GSI1PK' => ['S' => "OS#{$payment->getOsId()}"],
                'GSI1SK' => ['S' => "PAYMENT#{$data['created_at']}#{$payment->getId()}"],
                'status' => ['S' => $data['status']],
                'data' => ['S' => $this->json($data)],
            ]]];
        }

        foreach ($work->outbox as $i => $envelope) {
            $items[] = ['Put' => ['TableName' => $this->table, 'Item' => [
                'PK' => ['S' => "OUTBOX#{$envelope->id}"],
                'SK' => ['S' => 'OUTBOX'],
                'GSI2PK' => ['S' => 'OUTBOX#PENDING'],
                // Índice no lote preserva a ordem entre mensagens do mesmo instante.
                'GSI2SK' => ['S' => sprintf('%s#%03d#%s', $envelope->occurredAt->format('Y-m-d\TH:i:s.u'), $i, $envelope->id)],
                'data' => ['S' => $envelope->toJson()],
            ]]];
        }

        if ($items === []) {
            return;
        }

        try {
            $this->client->transactWriteItems(['TransactItems' => $items]);
        } catch (DynamoDbException $e) {
            $reasons = $e->get('CancellationReasons') ?? [];
            if ($work->consumed !== null && ($reasons[0]['Code'] ?? null) === 'ConditionalCheckFailed') {
                throw DuplicateMessageException::because("Mensagem {$work->consumed->id} já processada.");
            }

            throw $e;
        }
    }

    public function pendingOutbox(int $limit): array
    {
        $result = $this->client->query([
            'TableName' => $this->table,
            'IndexName' => 'GSI2',
            'KeyConditionExpression' => 'GSI2PK = :pending',
            'ExpressionAttributeValues' => [':pending' => ['S' => 'OUTBOX#PENDING']],
            'Limit' => $limit,
        ]);

        $pending = [];
        foreach ($result['Items'] ?? [] as $item) {
            $pending[$item['PK']['S']] = Envelope::fromJson($item['data']['S']);
        }

        return $pending;
    }

    public function markPublished(string $key): void
    {
        $this->client->updateItem([
            'TableName' => $this->table,
            'Key' => ['PK' => ['S' => $key], 'SK' => ['S' => 'OUTBOX']],
            'UpdateExpression' => 'REMOVE GSI2PK, GSI2SK SET published_at = :now, expires_at = :ttl',
            'ExpressionAttributeValues' => [
                ':now' => ['S' => (new \DateTimeImmutable)->format(DATE_ATOM)],
                ':ttl' => ['N' => (string) (time() + self::OUTBOX_TTL_DAYS * 86400)],
            ],
        ]);
    }

    /** @return array<string, mixed>|null */
    private function get(string $pk, string $sk): ?array
    {
        $result = $this->client->getItem([
            'TableName' => $this->table,
            'Key' => ['PK' => ['S' => $pk], 'SK' => ['S' => $sk]],
            'ConsistentRead' => true,
        ]);

        return isset($result['Item']['data']['S']) ? json_decode($result['Item']['data']['S'], true) : null;
    }

    /** @return array<int, array<string, mixed>> mais recentes primeiro */
    private function queryOs(int $osId, string $prefix): array
    {
        $result = $this->client->query([
            'TableName' => $this->table,
            'IndexName' => 'GSI1',
            'KeyConditionExpression' => 'GSI1PK = :os AND begins_with(GSI1SK, :prefix)',
            'ExpressionAttributeValues' => [
                ':os' => ['S' => "OS#{$osId}"],
                ':prefix' => ['S' => $prefix],
            ],
            'ScanIndexForward' => false,
        ]);

        return array_map(fn (array $item) => json_decode($item['data']['S'], true), $result['Items'] ?? []);
    }

    private function json(array $data): string
    {
        return json_encode($data, JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION | JSON_UNESCAPED_UNICODE);
    }
}
