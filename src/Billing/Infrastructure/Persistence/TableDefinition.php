<?php

namespace Domain\Billing\Infrastructure\Persistence;

/**
 * Esquema da tabela (mesmo do infra/main.tf). Usado pelo comando
 * `billing:dynamodb-table` em local, nos testes e no CI.
 */
final class TableDefinition
{
    /** @return array<string, mixed> */
    public static function for(string $table): array
    {
        $index = fn (string $name) => [
            'IndexName' => $name,
            'KeySchema' => [
                ['AttributeName' => "{$name}PK", 'KeyType' => 'HASH'],
                ['AttributeName' => "{$name}SK", 'KeyType' => 'RANGE'],
            ],
            'Projection' => ['ProjectionType' => 'ALL'],
        ];

        return [
            'TableName' => $table,
            'BillingMode' => 'PAY_PER_REQUEST',
            'AttributeDefinitions' => array_map(
                fn (string $name) => ['AttributeName' => $name, 'AttributeType' => 'S'],
                ['PK', 'SK', 'GSI1PK', 'GSI1SK', 'GSI2PK', 'GSI2SK'],
            ),
            'KeySchema' => [
                ['AttributeName' => 'PK', 'KeyType' => 'HASH'],
                ['AttributeName' => 'SK', 'KeyType' => 'RANGE'],
            ],
            'GlobalSecondaryIndexes' => [$index('GSI1'), $index('GSI2')],
        ];
    }
}
