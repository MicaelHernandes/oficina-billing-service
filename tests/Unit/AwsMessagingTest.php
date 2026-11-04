<?php

use Aws\CommandInterface;
use Aws\MockHandler;
use Aws\Result;
use Aws\Sns\SnsClient;
use Aws\Sqs\SqsClient;
use Domain\Billing\Domain\Messages\Envelope;
use Domain\Billing\Domain\Messages\MessageType;
use Domain\Billing\Domain\Ports\ReceivedMessage;
use Domain\Billing\Infrastructure\Messaging\LogMessagePublisher;
use Domain\Billing\Infrastructure\Messaging\NullMessageSource;
use Domain\Billing\Infrastructure\Messaging\SnsMessagePublisher;
use Domain\Billing\Infrastructure\Messaging\SqsMessageSource;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

function awsClientArgs(MockHandler $handler): array
{
    return [
        'region' => 'us-east-1',
        'credentials' => ['key' => 'test', 'secret' => 'test'],
        'handler' => $handler,
    ];
}

describe('Adaptadores AWS', function () {

    it('publica no SNS com os atributos usados nos filtros', function () {
        $sent = null;
        $handler = new MockHandler;
        $handler->append(function (CommandInterface $cmd) use (&$sent) {
            $sent = $cmd->toArray();

            return new Result(['MessageId' => 'sns-1']);
        });

        $envelope = Envelope::new(MessageType::GenerateBudget, 's-1', 'req-1', 7, 'oficina-os-service');
        (new SnsMessagePublisher(new SnsClient(awsClientArgs($handler) + ['version' => '2010-03-31']), 'arn:topic'))
            ->publish($envelope);

        expect($sent['TopicArn'])->toBe('arn:topic')
            ->and(json_decode($sent['Message'], true)['id'])->toBe($envelope->id)
            ->and($sent['MessageAttributes']['type']['StringValue'])->toBe('billing.budget.generate')
            ->and($sent['MessageAttributes']['correlation_id']['StringValue'])->toBe('req-1');
    });

    it('recebe e confirma mensagens no SQS', function () {
        $calls = [];
        $handler = new MockHandler;
        $handler->append(function (CommandInterface $cmd) use (&$calls) {
            $calls[] = $cmd->getName();

            return new Result(['Messages' => [['Body' => '{"a":1}', 'ReceiptHandle' => 'rh-1', 'MessageId' => 'm']]]);
        });
        $handler->append(function (CommandInterface $cmd) use (&$calls) {
            $calls[] = $cmd->getName().':'.$cmd['ReceiptHandle'];

            return new Result([]);
        });
        $handler->append(new Result([]));

        $source = new SqsMessageSource(new SqsClient(awsClientArgs($handler) + ['version' => '2012-11-05']), 'https://sqs/q', 1, 10);
        $batch = $source->receive();
        $source->ack($batch[0]);

        expect($batch)->toHaveCount(1)
            ->and($batch[0]->body)->toBe('{"a":1}')
            ->and($calls)->toBe(['ReceiveMessage', 'DeleteMessage:rh-1'])
            ->and($source->receive())->toBe([]);
    });

    it('fonte nula nunca recebe nada', function () {
        $source = new NullMessageSource;
        $source->ack(new ReceivedMessage('{}', 'r'));

        expect($source->receive())->toBe([]);
    });
});

it('driver log registra a mensagem publicada', function () {
    Log::shouldReceive('info')
        ->once()
        ->withArgs(fn ($msg, $ctx) => $msg === 'mensagem (driver log)' && $ctx['type'] === 'billing.budget.generated');

    (new LogMessagePublisher)
        ->publish(Envelope::new(MessageType::BudgetGenerated, null, null, 1, 'svc'));
})->uses(TestCase::class);
