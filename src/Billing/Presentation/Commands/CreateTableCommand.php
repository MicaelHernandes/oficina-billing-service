<?php

namespace Domain\Billing\Presentation\Commands;

use Aws\DynamoDb\DynamoDbClient;
use Aws\DynamoDb\Exception\DynamoDbException;
use Domain\Billing\Infrastructure\Persistence\TableDefinition;
use Illuminate\Console\Command;

/** Cria a tabela no DynamoDB Local (dev/testes/CI). Em produção ela vem do Terraform. */
class CreateTableCommand extends Command
{
    protected $signature = 'billing:dynamodb-table {--fresh : Apaga e recria a tabela}';

    protected $description = 'Cria a tabela do Billing no DynamoDB (uso local)';

    public function handle(DynamoDbClient $client): int
    {
        $table = (string) config('billing.dynamodb.table');

        if ($this->option('fresh')) {
            try {
                $client->deleteTable(['TableName' => $table]);
                $client->waitUntil('TableNotExists', ['TableName' => $table]);
            } catch (DynamoDbException $e) {
                // Não existia.
            }
        }

        try {
            $client->createTable(TableDefinition::for($table));
            $client->waitUntil('TableExists', ['TableName' => $table]);
            $this->info("tabela {$table} criada");
        } catch (DynamoDbException $e) {
            if ($e->getAwsErrorCode() !== 'ResourceInUseException') {
                throw $e;
            }
            $this->line("tabela {$table} já existe");
        }

        return self::SUCCESS;
    }
}
